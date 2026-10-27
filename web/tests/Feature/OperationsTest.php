<?php

namespace Tests\Feature;

use App\Contracts\TicketProvider;
use App\Jobs\CreateExternalTicket;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Device;
use App\Models\IncidentReport;
use App\Models\Observation;
use App\Models\Outage;
use App\Models\TicketRequest;
use App\Models\User;
use App\Services\ObservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    private Branch $other;

    private Device $camera;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin', 'all_branches' => true]);
        $this->branch = Branch::create(['name' => 'Makati', 'code' => 'MKT']);
        $this->other = Branch::create(['name' => 'Cebu', 'code' => 'CEB']);
        $this->camera = $this->device();
        $this->actingAs($this->admin);
    }

    private function device(array $attributes = []): Device
    {
        return Device::create([...['asset_id' => 'CAM-'.bin2hex(random_bytes(3)), 'name' => 'Entrance', 'branch_id' => $this->branch->id, 'type' => 'IP Camera', 'status' => 'unknown'], ...$attributes]);
    }

    private function observation(array $attributes = []): array
    {
        return [...['device_ids' => [$this->camera->id], 'date' => now()->toDateString(), 'update_status' => true, 'attach_images' => false, 'create_ticket' => false, 'status' => 'offline', 'notes' => 'Camera checked at branch'], ...$attributes];
    }

    private function operator(string $role = 'operator'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->branches()->attach($this->branch);

        return $user;
    }

    public function test_workspace_and_direct_mutations_are_branch_scoped(): void
    {
        $foreign = $this->device(['branch_id' => $this->other->id]);
        $user = $this->operator();
        $this->actingAs($user);
        $this->get('/cameras')->assertInertia(fn (Assert $page) => $page->component('Workspace')->has('workspace.devices', 1)->where('workspace.devices.0.id', $this->camera->id));
        $this->post('/observations', $this->observation(['device_ids' => [$foreign->id]]))->assertForbidden();
        $this->get('/reports/export?date='.now()->toDateString().'&format=csv&branch_id='.$this->other->id)->assertForbidden();
        $this->get('/users')->assertForbidden();
    }

    public function test_viewer_cannot_change_observations_or_devices(): void
    {
        $this->actingAs($this->operator('viewer'))->post('/observations', $this->observation())->assertForbidden();
        $this->post('/devices', ['name' => 'Blocked'])->assertForbidden();
        $this->assertDatabaseCount('observations', 0);
    }

    public function test_daily_status_tracks_outage_and_restoration_without_duplicates(): void
    {
        $this->post('/observations', $this->observation())->assertSessionHasNoErrors();
        $this->post('/observations', $this->observation())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('outages', 1);
        $this->assertSame('offline', $this->camera->fresh()->status);
        $this->travel(20)->minutes();
        $this->post('/observations', $this->observation(['status' => 'online']))->assertSessionHasNoErrors();
        $this->assertNotNull(Outage::first()->restored_at);
        $this->assertSame('online', $this->camera->fresh()->status);
        $this->assertDatabaseCount('observations', 1);
    }

    public function test_past_actions_are_rejected_and_today_image_only_save_does_not_mark_checked(): void
    {
        $this->post('/observations', $this->observation(['date' => now()->subDays(2)->toDateString()]))->assertSessionHasErrors('date');
        $this->assertSame('unknown', $this->camera->fresh()->status);
        $this->assertDatabaseCount('outages', 0);
        $this->post('/observations', $this->observation(['update_status' => false, 'attach_images' => true, 'images' => [UploadedFile::fake()->image('proof.png')]]))->assertSessionHasNoErrors();
        $this->assertNull(Observation::whereDate('date', now()->toDateString())->first()->checked_at);
    }

    public function test_evidence_is_reencoded_private_and_branch_authorized(): void
    {
        $this->post('/observations', $this->observation(['attach_images' => true, 'images' => [UploadedFile::fake()->image('camera.png'), UploadedFile::fake()->image('second.png')]]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attachments', 2);
        $file = Attachment::first();
        $this->assertSame('image/jpeg', $file->mime);
        Storage::disk('local')->assertExists($file->path);
        $this->get('/attachments/'.$file->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $user = $this->operator();
        $user->branches()->sync([$this->other->id]);
        $this->actingAs($user);
        $this->get('/attachments/'.$file->id)->assertForbidden();
        $this->get('/attachments/'.$file->id.'/download')->assertForbidden();
        $this->post('/observations', $this->observation(['device_ids' => [$this->device(['branch_id' => $this->other->id])->id], 'attach_images' => true, 'remove_attachment_ids' => [$file->id]]))->assertSessionHasErrors();
        $this->assertDatabaseHas('attachments', ['id' => $file->id]);
    }

    public function test_bulk_is_atomic_and_preserves_old_records_on_foreign_branch_failure(): void
    {
        $foreign = $this->device(['branch_id' => $this->other->id]);
        $this->actingAs($this->operator());
        $this->post('/observations', $this->observation(['device_ids' => [$this->camera->id, $foreign->id]]))->assertForbidden();
        $this->assertDatabaseCount('observations', 0);
        $this->assertSame('unknown', $this->camera->fresh()->status);
        $this->assertDatabaseCount('outages', 0);
    }

    public function test_combined_status_evidence_and_unconfigured_ticket_are_retained_truthfully(): void
    {
        $this->post('/observations', $this->observation(['attach_images' => true, 'images' => [UploadedFile::fake()->image('camera.jpg')], 'create_ticket' => true, 'ticket_title' => 'Support request']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ticket_requests', ['status' => 'unavailable', 'external_url' => null]);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_ticket_job_uses_provider_and_stable_reference_without_duplicate_creation(): void
    {
        Queue::fake();
        config(['tickets.endpoint' => 'https://provider.example/tickets', 'tickets.token' => 'synthetic-test-token',
            'tickets.department_id' => 1, 'tickets.category_id' => 33, 'tickets.assignee_id' => 6]);
        $this->post('/observations', $this->observation(['create_ticket' => true, 'ticket_title' => 'Support']))->assertSessionHasNoErrors();
        Queue::assertPushed(CreateExternalTicket::class);
        $ticket = TicketRequest::first();
        $key = $ticket->idempotency_key;
        $provider = \Mockery::mock(TicketProvider::class);
        $provider->shouldReceive('create')->once()->with(\Mockery::type('array'), $key)->andReturn(['id' => 'EX-1', 'url' => 'https://provider.example/EX-1']);
        (new CreateExternalTicket($ticket->id))->handle($provider);
        (new CreateExternalTicket($ticket->id))->handle($provider);
        $this->assertSame('created', $ticket->fresh()->status);
        $this->assertSame('EX-1', $ticket->fresh()->external_id);
        $this->post('/observations', $this->observation(['create_ticket' => true, 'ticket_title' => 'Support']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ticket_requests', 1);
        $this->assertSame($key, $ticket->fresh()->idempotency_key);
    }

    public function test_uncertain_external_failure_preserves_request_without_automatic_retry(): void
    {
        Queue::fake();
        config(['tickets.endpoint' => 'https://provider.example/tickets', 'tickets.token' => 'synthetic-test-token',
            'tickets.department_id' => 1, 'tickets.category_id' => 33, 'tickets.assignee_id' => 6]);
        $this->post('/observations', $this->observation(['create_ticket' => true, 'ticket_title' => 'Support']));
        $ticket = TicketRequest::first();
        $provider = \Mockery::mock(TicketProvider::class);
        $provider->shouldReceive('create')->once()->andThrow(new \RuntimeException('Provider down'));
        try {
            (new CreateExternalTicket($ticket->id))->handle($provider);
            $this->fail('Should throw');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('A ticket may already exist', $error->getMessage());
        }
        $this->assertSame('unconfirmed', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->external_url);
        $this->assertDatabaseCount('observations', 1);
    }

    public function test_device_channel_conflicts_and_circular_parent_are_rejected(): void
    {
        $recorder = $this->device(['type' => 'NVR', 'channels' => 4]);
        $this->camera->update(['parent_id' => $recorder->id, 'channel' => 1]);
        $base = ['asset_id' => 'CAM-NEW', 'name' => 'Other', 'branch_id' => $this->branch->id, 'type' => 'IP Camera', 'status' => 'unknown', 'parent_id' => $recorder->id, 'channel' => 1, 'reason' => 'Register camera'];
        $this->post('/devices', $base)->assertSessionHasErrors('device');
        $this->post('/devices', [...$base, 'channel' => 5])->assertSessionHasErrors('device');
        $this->put('/devices/'.$recorder->id, [...$base, 'asset_id' => $recorder->asset_id, 'type' => 'NVR', 'channels' => 4, 'parent_id' => $this->camera->id, 'channel' => null])->assertSessionHasErrors('device');
    }

    public function test_incident_resolution_evidence_verification_reopen_and_document_history(): void
    {
        $this->post('/incidents', ['device_id' => $this->camera->id, 'title' => 'Camera unavailable', 'priority' => 'high', 'category' => 'Camera offline', 'description' => 'No response', 'signed_document_url' => 'https://docs.example/signed.pdf'])->assertSessionHasNoErrors();
        $incident = IncidentReport::first();
        $this->assertNotNull($incident);
        $this->travel(1)->minutes();
        $this->post('/incidents/'.$incident->id.'/actions', ['action' => 'resolve', 'notes' => 'Repair complete', 'cause' => 'Failed power supply', 'work' => 'Replaced adapter', 'restored_at' => now()->toIso8601String(), 'confirmed' => true, 'proofs' => [UploadedFile::fake()->create('work.pdf', 20, 'application/pdf')]])->assertSessionHasNoErrors();
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertDatabaseCount('attachments', 1);
        $this->actingAs($this->operator('technician'))->post('/incidents/'.$incident->id.'/actions', ['action' => 'verify', 'notes' => 'Checked', 'confirmed' => true])->assertForbidden();
        $this->actingAs($this->admin)->post('/incidents/'.$incident->id.'/actions', ['action' => 'verify', 'notes' => 'Confirmed service', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('closed', $incident->fresh()->status);
        $this->post('/incidents/'.$incident->id.'/actions', ['action' => 'reopen', 'notes' => 'Issue returned'])->assertSessionHasNoErrors();
        $this->post('/incidents/'.$incident->id.'/actions', ['action' => 'document', 'notes' => 'Signed report received', 'signed_document_url' => 'https://docs.example/final.pdf'])->assertSessionHasNoErrors();
        $this->assertSame('https://docs.example/final.pdf', $incident->fresh()->signed_document_url);
        $this->assertSame(5, $incident->logs()->count());
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_incident_invalid_url_and_resolution_time_are_rejected(): void
    {
        $this->post('/incidents', ['device_id' => $this->camera->id, 'title' => 'Test', 'priority' => 'low', 'category' => 'Other', 'signed_document_url' => 'javascript:alert(1)'])->assertSessionHasErrors('signed_document_url');
        $this->assertDatabaseCount('incident_reports', 0);
        $this->post('/incidents', ['device_id' => $this->camera->id, 'title' => 'Test', 'priority' => 'low', 'category' => 'Other']);
        $incident = IncidentReport::first();
        $this->post('/incidents/'.$incident->id.'/actions', ['action' => 'resolve', 'notes' => 'bad time', 'cause' => 'Test', 'work' => 'Test', 'confirmed' => true, 'restored_at' => now()->subDay()->toIso8601String()])->assertSessionHasErrors('incident');
    }

    public function test_account_permissions_and_last_admin_protection(): void
    {
        $input = ['name' => 'New user', 'email' => 'operator@example.com', 'password' => 'long-password-123', 'role' => 'operator', 'active' => true, 'all_branches' => false, 'branch_ids' => [$this->branch->id], 'reason' => 'Branch operator'];
        $this->post('/users', $input)->assertSessionHasNoErrors();
        $user = User::where('email', $input['email'])->first();
        $this->assertTrue($user->branches()->whereKey($this->branch->id)->exists());
        $this->put('/users/'.$this->admin->id, [...$input, 'email' => $this->admin->email, 'role' => 'viewer'])->assertSessionHasErrors('account');
        $this->flushSession();
        $this->actingAs($user)->post('/branches', ['name' => 'Blocked', 'code' => 'NO', 'active' => true])->assertForbidden();
        $this->post('/users', $input)->assertForbidden();
    }

    public function test_inactive_account_cannot_sign_in_or_keep_using_session(): void
    {
        $user = $this->operator();
        $user->update(['active' => false]);
        $this->actingAs($user)->get('/cameras')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_reports_are_scoped_escape_csv_formulas_and_include_evidence(): void
    {
        $this->post('/observations', $this->observation(['notes' => '=MALICIOUS()', 'attach_images' => true, 'images' => [UploadedFile::fake()->image('proof.jpg')]]));
        $response = $this->get('/reports/export?date='.now()->toDateString().'&format=csv')->assertOk();
        $this->assertStringContainsString("'=MALICIOUS()", $response->streamedContent());
        $this->get('/reports/export?date='.now()->toDateString().'&format=html')->assertOk()->assertSee('data:image/jpeg;base64,', false);
    }

    public function test_snoozes_are_per_user_and_branch_checked(): void
    {
        $this->post('/followups', ['subject_type' => 'device', 'subject_id' => $this->camera->id, 'snoozed_until' => now()->addDay()->toDateString()])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('followups', ['user_id' => $this->admin->id, 'subject_id' => $this->camera->id]);
        $foreign = $this->device(['branch_id' => $this->other->id]);
        $this->actingAs($this->operator());
        $this->post('/followups', ['subject_type' => 'device', 'subject_id' => $foreign->id, 'snoozed_until' => now()->addDay()->toDateString()])->assertForbidden();
    }

    public function test_invalid_upload_and_future_observation_do_not_create_records(): void
    {
        $this->post('/observations', $this->observation(['date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('date');
        $this->post('/observations', $this->observation(['attach_images' => true, 'images' => [UploadedFile::fake()->create('script.html', 5, 'text/html')]]))->assertSessionHasErrors('images.0');
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_document_urls_with_embedded_credentials_are_rejected(): void
    {
        $this->post('/incidents', ['device_id' => $this->camera->id, 'title' => 'Issue', 'priority' => 'low', 'category' => 'Other', 'signed_document_url' => 'https://user:secret@docs.example/report.pdf'])->assertSessionHasErrors('signed_document_url');
        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_rejected_attachment_removal_rolls_back_the_record(): void
    {
        $this->post('/observations', $this->observation(['attach_images' => true, 'images' => [UploadedFile::fake()->image('one.png')], 'remove_attachment_ids' => [999999]]))->assertSessionHasErrors('observation');
        $this->assertDatabaseCount('observations', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_all_past_daily_actions_and_ticket_retries_preserve_existing_data(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $record = Observation::create(['device_id' => $this->camera->id, 'date' => $yesterday, 'status' => 'online', 'notes' => 'Original historical record', 'checked_at' => now()->subDay(), 'checked_by' => $this->admin->id]);
        Storage::disk('local')->put('evidence/history.jpg', 'original');
        $file = Attachment::create(['branch_id' => $this->branch->id, 'observation_id' => $record->id, 'uploaded_by' => $this->admin->id, 'disk' => 'local', 'path' => 'evidence/history.jpg', 'name' => 'history.jpg', 'mime' => 'image/jpeg', 'size' => 8]);
        $ticket = TicketRequest::create(['observation_id' => $record->id, 'requested_by' => $this->admin->id, 'idempotency_key' => (string) Str::uuid(), 'title' => 'Existing request', 'status' => 'unavailable']);
        foreach ([
            ['update_status' => true],
            ['update_status' => false, 'attach_images' => true, 'images' => [UploadedFile::fake()->image('new.jpg')]],
            ['update_status' => false, 'attach_images' => true, 'remove_attachment_ids' => [$file->id]],
            ['update_status' => false, 'create_ticket' => true, 'ticket_title' => 'New request'],
        ] as $action) {
            $this->post('/observations', $this->observation([...$action, 'date' => $yesterday]))->assertSessionHasErrors('date');
        }
        $other = $this->device();
        $this->post('/observations', $this->observation(['device_ids' => [$this->camera->id, $other->id], 'date' => $yesterday]))->assertSessionHasErrors('date');
        config(['tickets.endpoint' => 'https://provider.example/tickets']);
        Queue::fake();
        $this->post('/tickets/'.$ticket->id.'/retry')->assertSessionHas('error', 'Past daily records are read-only. Ticket creation can only be triggered for today.');
        Queue::assertNothingPushed();
        $this->assertSame('Original historical record', $record->fresh()->notes);
        $this->assertSame('online', $record->fresh()->status);
        $this->assertDatabaseCount('observations', 1);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertDatabaseCount('ticket_requests', 1);
        $this->assertSame('unavailable', $ticket->fresh()->status);
        Storage::disk('local')->assertExists($file->path);
        $this->get('/attachments/'.$file->id)->assertOk();
    }

    public function test_daily_write_uses_philippine_date_after_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 00:01:00', 'Asia/Manila'));
        $this->post('/observations', $this->observation(['date' => '2026-10-25']))->assertSessionHasErrors('date');
        $this->post('/observations', $this->observation(['date' => '2026-10-26']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('observations', ['date' => '2026-10-26']);
    }

    public function test_service_rejects_past_date_without_form_request(): void
    {
        try {
            app(ObservationService::class)->save($this->admin, $this->observation(['date' => now()->subDay()->toDateString()]));
            $this->fail('Past actions should be blocked.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('date', $error->errors());
        }
        $this->assertDatabaseCount('observations', 0);
    }
}
