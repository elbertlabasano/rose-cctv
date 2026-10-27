<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Observation;
use App\Models\Outage;
use App\Models\TicketRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class QuickStatusTest extends TestCase
{
    private Branch $branch;

    private Device $camera;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-26 00:05:00', 'Asia/Manila'));
        Storage::fake('local');
        Queue::fake();
        $this->branch = Branch::create(['name' => 'Main branch', 'code' => 'MAIN']);
        $this->camera = Device::create(['branch_id' => $this->branch->id, 'asset_id' => 'CAM-TEST', 'name' => 'Camera',
            'type' => 'IP Camera', 'status' => 'unknown', 'ip' => '192.168.1.99']);
        $this->operator = User::factory()->create(['role' => 'operator']);
        $this->operator->branches()->attach($this->branch);
        $this->actingAs($this->operator);
    }

    private function input(string $status = 'online', array $attributes = []): array
    {
        return ['device_id' => $this->camera->id, 'date' => now()->toDateString(), 'status' => $status, ...$attributes];
    }

    public function test_one_click_records_today_with_the_real_checker_and_a_small_private_response(): void
    {
        $response = $this->postJson('/observations/quick-status', $this->input())->assertOk()
            ->assertJsonPath('observation.status', 'online')->assertJsonPath('observation.date', '2026-10-26')
            ->assertJsonPath('observation.checker', $this->operator->name)->assertJsonPath('observation.is_checked', true)
            ->assertJsonPath('observation.notes', null)->assertJsonPath('device.status', 'online')
            ->assertJsonCount(0, 'outages')->assertJsonMissingPath('device.ip');
        $record = Observation::sole();
        $this->assertSame($this->operator->id, $record->checked_by);
        $this->assertTrue($record->checked_at->eq(now()));
        $this->assertSame('online', $this->camera->fresh()->status);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'Camera quick status saved', 'branch_id' => $this->branch->id]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_quick_marking_preserves_notes_evidence_tickets_and_their_snapshot(): void
    {
        $record = Observation::create(['device_id' => $this->camera->id, 'date' => now()->toDateString(),
            'status' => 'offline', 'notes' => 'Existing investigation notes']);
        Storage::disk('local')->put('evidence/test.jpg', 'evidence bytes');
        $attachment = Attachment::create(['observation_id' => $record->id, 'branch_id' => $this->branch->id,
            'disk' => 'local', 'path' => 'evidence/test.jpg', 'name' => 'Camera.jpg', 'mime' => 'image/jpeg', 'size' => 14]);
        $ticket = TicketRequest::create(['observation_id' => $record->id, 'title' => 'Existing ticket', 'status' => 'failed',
            'idempotency_key' => (string) Str::uuid(), 'payload' => ['private' => 'NEVER-EXPOSE']]);
        $original = $ticket->fresh()->getRawOriginal();
        $response = $this->postJson('/observations/quick-status', [...$this->input(), 'create_ticket' => true,
            'notes' => 'Do not overwrite', 'remove_attachment_ids' => [$attachment->id]])->assertOk()
            ->assertJsonPath('observation.notes', 'Existing investigation notes')
            ->assertJsonPath('observation.attachments.0.id', $attachment->id)
            ->assertJsonPath('observation.ticket.id', $ticket->id)->assertJsonMissingPath('observation.ticket.payload');
        $this->assertStringNotContainsString('NEVER-EXPOSE', $response->getContent());
        $this->assertSame($original, $ticket->fresh()->getRawOriginal());
        $this->assertDatabaseCount('attachments', 1);
        Storage::disk('local')->assertExists($attachment->path);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_offline_and_online_follow_existing_outage_rules_without_duplicate_outages(): void
    {
        $this->postJson('/observations/quick-status', $this->input('offline'))->assertOk()->assertJsonCount(1, 'outages');
        $outage = Outage::sole();
        $this->postJson('/observations/quick-status', $this->input('offline'))->assertOk();
        $this->assertDatabaseCount('outages', 1);
        $this->travel(2)->minutes();
        $this->postJson('/observations/quick-status', $this->input('online'))->assertOk()
            ->assertJsonPath('device.status', 'online')->assertJsonPath('observation.status', 'online');
        $this->assertNotNull($outage->fresh()->restored_at);
        $this->assertDatabaseCount('observations', 1);
        $this->assertDatabaseCount('ticket_requests', 0);
        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_manual_quick_check_replaces_import_markers_and_reported_date_with_actual_check_metadata(): void
    {
        $this->camera->update(['status_reported_on' => now()->toDateString()]);
        Observation::create(['device_id' => $this->camera->id, 'date' => now()->toDateString(),
            'check_result' => 'day_off', 'import_source' => 'October workbook', 'import_reference' => 'D9', 'notes' => 'Preserved']);
        $this->postJson('/observations/quick-status', $this->input('offline'))->assertOk()
            ->assertJsonPath('observation.check_result', null)->assertJsonPath('observation.import_source', null)
            ->assertJsonPath('observation.import_reference', null)->assertJsonPath('device.status_reported_on', null)
            ->assertJsonPath('observation.notes', 'Preserved');
        $this->assertSame($this->operator->id, Observation::sole()->checked_by);
    }

    public function test_past_future_and_invalid_status_checks_do_not_write_anything(): void
    {
        foreach ([['date' => '2026-10-25'], ['date' => '2026-10-27'], ['date' => 'invalid'], ['status' => 'maintenance'], ['status' => null]] as $invalid) {
            $this->postJson('/observations/quick-status', $this->input(attributes: $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outages', 0);
        $this->assertSame('unknown', $this->camera->fresh()->status);
    }

    public function test_authorization_rejects_foreign_branches_viewers_inactive_accounts_and_guests(): void
    {
        $foreign = Branch::create(['name' => 'Foreign', 'code' => 'OTHER']);
        $this->camera->update(['branch_id' => $foreign->id]);
        $this->postJson('/observations/quick-status', $this->input())->assertForbidden();
        $this->camera->update(['branch_id' => $this->branch->id]);
        $this->operator->update(['role' => 'viewer']);
        $this->postJson('/observations/quick-status', $this->input())->assertForbidden();
        $this->operator->update(['role' => 'operator', 'active' => false]);
        $this->postJson('/observations/quick-status', $this->input())->assertRedirect('/login');
        auth()->logout();
        $this->postJson('/observations/quick-status', $this->input())->assertUnauthorized();
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_recorder_status_cannot_be_marked_as_a_camera_check(): void
    {
        $this->camera->update(['type' => 'NVR']);
        $this->postJson('/observations/quick-status', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('observations', 0);
    }

    public function test_failed_audit_rolls_back_status_observation_and_outage_together(): void
    {
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->app->instance(AuditService::class, $audit);
        $this->postJson('/observations/quick-status', $this->input('offline'))->assertServerError();
        $this->assertSame('unknown', $this->camera->fresh()->status);
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('outages', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
