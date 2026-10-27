<?php

namespace Tests\Feature;

use App\Exceptions\TicketProviderException;
use App\Integrations\HttpTicketProvider;
use App\Jobs\CreateExternalTicket;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Observation;
use App\Models\TicketRequest;
use App\Models\User;
use App\Services\TicketIntegration;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketIntegrationTest extends TestCase
{
    private User $admin;

    private Branch $branch;

    private Device $camera;

    private string $endpoint = 'https://support.example/api/ticket';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        config(['tickets.endpoint' => $this->endpoint, 'tickets.token' => 'SYNTHETIC-TOKEN',
            'tickets.department_id' => 1, 'tickets.category_id' => 33, 'tickets.assignee_id' => 6, 'tickets.board_id' => 10]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->branch = Branch::create(['name' => 'Main branch', 'code' => 'MAIN']);
        $this->camera = Device::create(['branch_id' => $this->branch->id, 'asset_id' => 'CAM-TEST', 'name' => 'Branch camera', 'type' => 'IP Camera']);
        $this->actingAs($this->admin);
    }

    private function payload(): array
    {
        return ['title' => 'Camera offline', 'requested_by' => $this->admin->name, 'requester_email' => $this->admin->email,
            'camera_id' => $this->camera->asset_id, 'camera_name' => $this->camera->name, 'branch' => $this->branch->name,
            'date' => now()->toDateString(), 'status' => 'offline', 'notes' => 'Power checked', 'attachment_ids' => [], 'board_id' => 10];
    }

    private function confirmation(string $id = 'SH-TEST', string $uuid = 'test-uuid'): array
    {
        return ['success' => true, 'data' => ['ticket_id' => $id, 'uuid' => $uuid, 'status' => 'open',
            'assignee_id' => 6, 'url' => 'https://support.example/app/ticket/'.$uuid]];
    }

    private function ticket(array $attributes = [], ?Device $device = null): TicketRequest
    {
        $record = Observation::firstOrCreate(['device_id' => ($device ?? $this->camera)->id, 'date' => now()->toDateString()]);

        return TicketRequest::create(['observation_id' => $record->id, 'requested_by' => $this->admin->id,
            'title' => 'Camera offline', 'status' => 'queued', 'idempotency_key' => (string) Str::uuid(),
            'payload' => $this->payload(), ...$attributes]);
    }

    private function remote(string $id = 'SH-TEST', string $uuid = 'test-uuid'): array
    {
        return [...$this->confirmation($id, $uuid)['data'], 'subject' => 'Camera offline', 'description' => '<b>Notes</b>',
            'priority' => 'medium', 'board' => ['name' => 'CCTV Reports/Concerns'], 'assignees' => [['id' => 6, 'name' => 'Technician']],
            'due_date' => null, 'updated_at' => now()->toIso8601String(), 'private_token' => 'NEVER-EXPOSE'];
    }

    private function listing(array $items, int $page = 1, int $last = 1): array
    {
        return ['success' => true, 'data' => $items, 'meta' => ['current_page' => $page, 'last_page' => $last],
            'links' => ['next' => 'https://untrusted.example/steal', 'previous' => null]];
    }

    public function test_json_creation_matches_the_documented_fields_and_nested_confirmation(): void
    {
        Http::fake([$this->endpoint => Http::response($this->confirmation(), 201)]);
        $result = app(HttpTicketProvider::class)->create($this->payload(), 'correlation-key');
        $this->assertSame('SH-TEST', $result['id']);
        $this->assertSame('https://support.example/app/ticket/test-uuid', $result['url']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->isJson()
            && $request->hasHeader('Authorization', 'Bearer SYNTHETIC-TOKEN')
            && $request['name'] === $this->admin->name && $request['email'] === $this->admin->email
            && $request['department_id'] === 1 && $request['category_id'] === 33 && $request['assignee_id'] === 6
            && $request['board_id'] === 10 && $request['subject'] === 'Camera offline'
            && $request['source_app'] === 'RoseWatch' && $request['external_reference'] === 'correlation-key'
            && str_contains($request['description'], 'Power checked') && ! isset($request['screenshots']));
        Http::assertSentCount(1);
    }

    public function test_multipart_upload_uses_saved_evidence_streams_and_selected_board(): void
    {
        Http::fake([$this->endpoint => Http::response($this->confirmation(), 201)]);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, 'synthetic JPEG');
        rewind($stream);
        try {
            app(HttpTicketProvider::class)->create([...$this->payload(), 'board_id' => 9,
                'attachments' => [['name' => 'camera.jpg', 'mime' => 'image/jpeg', 'stream' => $stream]]], 'key');
            Http::assertSent(function (Request $request) {
                $fields = collect($request->data())->keyBy('name');

                return $request->isMultipart() && $request->hasFile('attachments[]', filename: 'camera.jpg')
                    && $fields['board_id']['contents'] == 9 && $fields['category_id']['contents'] == 33;
            });
            Http::assertSentCount(1);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function test_combined_daily_actions_capture_board_requester_and_all_saved_evidence_before_dispatch(): void
    {
        $this->post('/observations', ['device_ids' => [$this->camera->id], 'date' => now()->toDateString(),
            'update_status' => true, 'status' => 'offline', 'attach_images' => true, 'images' => [UploadedFile::fake()->image('camera.png')],
            'create_ticket' => true, 'ticket_title' => 'CCTV reporting', 'ticket_board_id' => 9, 'notes' => 'Daily check'])
            ->assertSessionHasNoErrors();
        $ticket = TicketRequest::sole();
        $this->assertSame(9, $ticket->payload['board_id']);
        $this->assertSame($this->admin->email, $ticket->payload['requester_email']);
        $this->assertCount(1, $ticket->payload['attachment_ids']);
        Queue::assertPushed(CreateExternalTicket::class, 1);
        Http::assertNothingSent();
        $this->post('/observations', ['device_ids' => [$this->camera->id], 'date' => now()->toDateString(),
            'update_status' => false, 'attach_images' => false, 'create_ticket' => true, 'ticket_title' => 'Wrong board', 'ticket_board_id' => 99, 'notes' => 'Check'])
            ->assertSessionHasErrors('ticket_board_id');
    }

    public function test_confirmed_jobs_send_once_even_when_reexecuted(): void
    {
        Http::fake([$this->endpoint => Http::response($this->confirmation(), 201)]);
        $ticket = $this->ticket();
        $job = new CreateExternalTicket($ticket->id);
        $job->handle(app(HttpTicketProvider::class));
        $job->handle(app(HttpTicketProvider::class));
        $this->assertSame('created', $ticket->fresh()->status);
        $this->assertSame(1, $job->tries);
        Http::assertSentCount(1);
    }

    public function test_uncertain_failure_is_never_reposted_by_jobs_retries_or_resaving_daily_actions(): void
    {
        Http::fake([$this->endpoint => Http::failedConnection('PRIVATE token and connection details')]);
        $ticket = $this->ticket();
        $job = new CreateExternalTicket($ticket->id);
        try {
            $job->handle(app(HttpTicketProvider::class));
            $this->fail('Timeout must be unconfirmed.');
        } catch (TicketProviderException $exception) {
            $this->assertTrue($exception->uncertain);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
        }
        $this->assertSame('unconfirmed', $ticket->fresh()->status);
        $job->handle(app(HttpTicketProvider::class));
        $job->failed(null);
        $this->post('/tickets/'.$ticket->id.'/retry')->assertRedirect();
        $this->post('/observations', ['device_ids' => [$this->camera->id], 'date' => now()->toDateString(),
            'update_status' => false, 'attach_images' => false, 'create_ticket' => true, 'ticket_title' => 'Again', 'notes' => 'Review'])
            ->assertSessionHasNoErrors();
        Queue::assertNotPushed(CreateExternalTicket::class);
        $this->assertSame('unconfirmed', $ticket->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_validation_rejection_allows_only_an_explicit_retry_and_never_exposes_provider_body(): void
    {
        Http::fake([$this->endpoint => Http::response(['message' => 'PRIVATE exception', 'token' => 'SYNTHETIC-TOKEN'], 422)]);
        $ticket = $this->ticket();
        try {
            (new CreateExternalTicket($ticket->id))->handle(app(HttpTicketProvider::class));
            $this->fail('Rejected request must fail.');
        } catch (TicketProviderException $exception) {
            $this->assertFalse($exception->uncertain);
            $this->assertStringContainsString('HTTP 422', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
        }
        $this->assertSame('failed', $ticket->fresh()->status);
        Queue::assertNothingPushed();
        $this->post('/tickets/'.$ticket->id.'/retry')->assertRedirect();
        Queue::assertPushed(CreateExternalTicket::class, 1);
        Http::assertSentCount(1);
    }

    public function test_redirects_errors_and_malformed_success_remain_uncertain_without_retry(): void
    {
        Http::fakeSequence()->push('', 302, ['Location' => 'https://untrusted.example'])
            ->push(['success' => false], 500)->push(['id' => 'old-format'], 201)
            ->push(['success' => true, 'data' => ['ticket_id' => 'SH-X', 'url' => 'https://untrusted.example/app/ticket/X']], 201);
        for ($i = 0; $i < 4; $i++) {
            try {
                app(HttpTicketProvider::class)->create($this->payload(), 'key');
                $this->fail('Untrusted confirmation must be rejected.');
            } catch (TicketProviderException $exception) {
                $this->assertTrue($exception->uncertain);
            }
        }
        Http::assertSentCount(4);
    }

    public function test_get_uses_assigned_id_pagination_and_returns_only_allowlisted_fields(): void
    {
        Http::fake([$this->endpoint.'*' => Http::response($this->listing([$this->remote()], 2, 3))]);
        $result = app(HttpTicketProvider::class)->tickets(2);
        $this->assertSame(2, $result['page']);
        $this->assertTrue($result['has_more']);
        $this->assertSame(['Technician'], $result['data'][0]['assignees']);
        $this->assertArrayNotHasKey('private_token', $result['data'][0]);
        $this->assertArrayNotHasKey('links', $result);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request['assigned_id'] == 6 && $request['page'] == 2 && $request['per_page'] == 50
            && $request->hasHeader('Authorization', 'Bearer SYNTHETIC-TOKEN'));
    }

    public function test_live_feed_never_exposes_unrelated_or_unauthorized_branch_tickets(): void
    {
        $own = $this->ticket(['status' => 'created', 'external_id' => 'SH-TEST', 'external_url' => 'https://support.example/app/ticket/test-uuid']);
        $other = Branch::create(['name' => 'Other branch', 'code' => 'OTHER']);
        $device = Device::create(['branch_id' => $other->id, 'asset_id' => 'CAM-OTHER', 'name' => 'Other', 'type' => 'IP Camera']);
        $this->ticket(['status' => 'created', 'external_id' => 'SH-OTHER', 'external_url' => 'https://support.example/app/ticket/other-uuid'], $device);
        Http::fake([$this->endpoint.'*' => Http::response($this->listing([$this->remote(), $this->remote('SH-OTHER', 'other-uuid'), $this->remote('OTHER-APP', 'unrelated')]))]);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $viewer->branches()->attach($this->branch);
        $response = $this->actingAs($viewer)->getJson('/tickets/external?date='.now()->toDateString())->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.request_id', $own->id)->assertJsonPath('data.0.status', 'open');
        $this->assertStringNotContainsString('SH-OTHER', $response->getContent());
        $this->assertStringNotContainsString('OTHER-APP', $response->getContent());
        $this->assertStringNotContainsString('NEVER-EXPOSE', $response->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-TOKEN', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('created', $own->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_feed_requires_auth_validates_parameters_and_does_not_accept_an_arbitrary_assignee(): void
    {
        $this->getJson('/tickets/external?date=invalid&page=0')->assertUnprocessable();
        $this->getJson('/tickets/external?date='.now()->addDay()->toDateString())->assertUnprocessable();
        $this->getJson('/tickets/external?date='.now()->toDateString().'&page=1001')->assertUnprocessable();
        $this->ticket(['external_id' => 'SH-TEST', 'external_url' => 'https://support.example/app/ticket/test-uuid']);
        Http::fake([$this->endpoint.'*' => Http::response($this->listing([]))]);
        $this->getJson('/tickets/external?date='.now()->toDateString().'&assigned_id=999')->assertOk();
        Http::assertSent(fn (Request $request) => $request['assigned_id'] == 6);
        auth()->logout();
        $this->getJson('/tickets/external?date='.now()->toDateString())->assertUnauthorized();
    }

    public function test_missing_configuration_never_sends_and_page_props_expose_only_flags_and_boards(): void
    {
        config(['tickets.category_id' => null]);
        $this->assertFalse(app(TicketIntegration::class)->canCreate());
        $this->assertTrue(app(TicketIntegration::class)->canView());
        $response = $this->get('/tickets')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workspace.ticket_configured', false)->where('workspace.ticket_view_configured', true)
            ->where('workspace.ticket_boards.0.id', 10)->where('workspace.ticket_boards.1.id', 9));
        $this->assertStringNotContainsString('SYNTHETIC-TOKEN', $response->getContent());
        try {
            app(HttpTicketProvider::class)->create($this->payload(), 'key');
            $this->fail('Incomplete routing must not send.');
        } catch (TicketProviderException $exception) {
            $this->assertFalse($exception->uncertain);
        }
        Http::assertNothingSent();
    }
}
