<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\IncidentReport;
use App\Models\Observation;
use App\Models\Outage;
use App\Models\TicketRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeviceDashboardTest extends TestCase
{
    private User $reader;

    private Branch $branch;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        // Never use RefreshDatabase: only migrate the guarded, in-memory test connection.
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $this->reader = User::factory()->create(['role' => 'viewer', 'all_branches' => false]);
        $this->branch = Branch::create(['name' => 'Assigned branch', 'code' => 'ASSIGNED']);
        $this->reader->branches()->attach($this->branch);
        $this->device = $this->device();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function device(array $attributes = []): Device
    {
        return Device::create([
            'asset_id' => 'CAM-'.Str::random(8), 'name' => 'Main entrance',
            'branch_id' => $this->branch->id, 'type' => 'IP Camera', 'status' => 'online',
            ...$attributes,
        ]);
    }

    private function incident(Device $device, array $attributes = []): IncidentReport
    {
        return IncidentReport::create([
            'reference' => 'IR-'.Str::random(8), 'device_id' => $device->id,
            'branch_id' => $device->branch_id, 'title' => 'Device investigation',
            'priority' => 'high', 'category' => 'Connectivity', 'status' => 'assigned',
            'due_at' => now()->addDay(), ...$attributes,
        ]);
    }

    private function observation(Device $device, array $attributes = []): Observation
    {
        return Observation::create([
            'device_id' => $device->id, 'date' => '2026-10-25', 'status' => 'online',
            'checked_at' => '2026-10-25 10:00:00', 'checked_by' => $this->reader->id,
            ...$attributes,
        ]);
    }

    public function test_device_dashboard_requires_login_and_assigned_branch_access(): void
    {
        $this->get('/devices/'.$this->device->id)->assertRedirect('/login');
        $foreign = Branch::create(['name' => 'Private branch', 'code' => 'PRIVATE']);
        $device = $this->device(['branch_id' => $foreign->id]);
        $this->actingAs($this->reader);
        foreach (['overview', 'status', 'tickets', 'incidents', 'evidence', 'outages', 'activity'] as $tab) {
            $this->get('/devices/'.$device->id.'?tab='.$tab)->assertForbidden();
        }
        $this->get('/devices/999999')->assertNotFound();
    }

    public function test_all_date_histories_are_device_scoped_and_ticket_secrets_are_never_exposed(): void
    {
        $old = $this->observation($this->device, ['date' => '2025-01-01']);
        $ticket = TicketRequest::create([
            'observation_id' => $old->id, 'idempotency_key' => (string) Str::uuid(),
            'title' => 'Archived service request', 'status' => 'unavailable',
            'payload' => ['private' => 'PRIVATE-PROVIDER-PAYLOAD'],
        ]);
        $incident = $this->incident($this->device, ['created_at' => '2025-01-01', 'status' => 'closed']);
        $other = $this->device();
        $this->observation($other);
        $this->incident($other);
        $this->actingAs($this->reader);

        $this->get('/devices/'.$this->device->id)->assertInertia(fn (Assert $page) => $page
            ->component('Devices/Show')->where('dashboard.device.id', $this->device->id)
            ->where('dashboard.counts.status', 1)->where('dashboard.counts.tickets', 1)
            ->where('dashboard.counts.confirmed_tickets', 0)->where('dashboard.counts.incidents', 1)
            ->where('dashboard.counts.active_incidents', 0)->where('dashboard.can_check', false)
            ->has('dashboard.days', 30)->where('dashboard.days.0.status', 'unchecked')
            ->missing('dashboard.counts.activity'));

        $this->get('/devices/'.$this->device->id.'?tab=status')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.history.status.total', 1)->where('dashboard.history.status.data.0.date', '2025-01-01'));
        $this->get('/devices/'.$this->device->id.'?tab=tickets')->assertDontSee('PRIVATE-PROVIDER-PAYLOAD', false)
            ->assertDontSee($ticket->idempotency_key, false)->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.history.tickets.total', 1)->where('dashboard.history.tickets.data.0.id', $ticket->id)
            ->where('dashboard.history.tickets.data.0.observation.id', $old->id));
        $this->get('/devices/'.$this->device->id.'?tab=incidents')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.history.incidents.total', 1)->where('dashboard.history.incidents.data.0.id', $incident->id)
            ->where('dashboard.history.incidents.data.0.url', fn ($url) => str_contains($url, 'incident_id='.$incident->id)));
        $this->get('/devices/'.$this->device->id.'?tab=activity')->assertForbidden();
    }

    public function test_charts_clip_merge_and_split_outages_at_philippine_day_boundaries(): void
    {
        $this->observation($this->device);
        $this->observation($this->device, ['date' => '2026-10-26', 'status' => null, 'checked_at' => null]);
        foreach ([
            ['2026-10-24 23:00:00', '2026-10-25 02:00:00'],
            ['2026-10-25 01:00:00', '2026-10-25 03:00:00'],
            ['2026-10-25 23:00:00', null],
        ] as [$start, $end]) {
            Outage::create(['device_id' => $this->device->id, 'started_at' => $start, 'restored_at' => $end]);
        }
        $this->actingAs($this->reader)->get('/devices/'.$this->device->id.'?from=2026-10-25&to=2026-10-26')
            ->assertInertia(fn (Assert $page) => $page
                ->has('dashboard.days', 2)->where('dashboard.days.0.date', '2026-10-25')
                ->where('dashboard.days.0.status', 'online')->where('dashboard.days.0.downtime_minutes', 240)
                ->where('dashboard.days.1.status', 'unchecked')->where('dashboard.days.1.downtime_minutes', 720)
                ->where('dashboard.counts.open_outages', 1));
    }

    public function test_history_pagination_and_chart_period_validation(): void
    {
        for ($day = 1; $day <= 13; $day++) {
            $this->observation($this->device, ['date' => sprintf('2026-09-%02d', $day)]);
        }
        $this->actingAs($this->reader)->get('/devices/'.$this->device->id.'?tab=status&page=2')
            ->assertInertia(fn (Assert $page) => $page->where('dashboard.history.status.current_page', 2)
                ->where('dashboard.history.status.total', 13)->has('dashboard.history.status.data', 1)
                ->where('dashboard.history.status.data.0.date', '2026-09-01'));
        foreach ([
            'from=2026-10-26&to=2026-10-25' => 'to',
            'from=2026-10-01&to=2026-10-27' => 'to',
            'from=2026-01-01&to=2026-10-26' => 'from',
            'from=not-a-date&to=2026-10-26' => 'from',
            'tab=unrecognized' => 'tab', 'page=0' => 'page',
        ] as $query => $field) {
            $this->getJson('/devices/'.$this->device->id.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_evidence_includes_daily_and_incident_files_without_storage_paths(): void
    {
        $record = $this->observation($this->device);
        $incident = $this->incident($this->device);
        $log = $incident->logs()->create(['action' => 'log', 'notes' => 'Investigated']);
        foreach ([['observation_id' => $record->id], ['incident_log_id' => $log->id]] as $relation) {
            Attachment::create([
                'branch_id' => $this->branch->id, 'path' => 'PRIVATE-STORAGE-PATH',
                'name' => 'evidence.png', 'mime' => 'image/png', 'size' => 12, ...$relation,
            ]);
        }
        $this->actingAs($this->reader)->get('/devices/'.$this->device->id.'?tab=evidence')
            ->assertDontSee('PRIVATE-STORAGE-PATH', false)->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.counts.evidence', 2)->where('dashboard.history.evidence.total', 2)
            ->where('dashboard.history.evidence.data.0.source', 'Incident evidence')
            ->where('dashboard.history.evidence.data.0.incident_reference', $incident->reference)
            ->where('dashboard.history.evidence.data.1.record_date', '2026-10-25')
            ->missing('dashboard.history.evidence.data.0.path'));
    }

    public function test_related_hardware_is_branch_scoped_and_admin_activity_is_sanitized(): void
    {
        $foreign = Branch::create(['name' => 'Foreign hardware branch', 'code' => 'FOREIGN']);
        $foreignParent = $this->device(['branch_id' => $foreign->id, 'type' => 'NVR']);
        $this->device->update(['parent_id' => $foreignParent->id]);
        $this->device(['branch_id' => $foreign->id, 'parent_id' => $this->device->id]);
        AuditLog::create([
            'subject_type' => 'Device', 'subject_id' => $this->device->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->reader->id, 'action' => 'Device updated', 'reason' => 'Replaced hardware',
            'changes' => ['private' => 'PRIVATE-AUDIT-CHANGES'],
        ]);
        $this->actingAs($this->reader)->get('/devices/'.$this->device->id)->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.parent', null)->has('dashboard.children', 0)->where('dashboard.can_audit', false));
        $this->reader->update(['role' => 'admin']);
        $this->get('/devices/'.$this->device->id.'?tab=activity')->assertDontSee('PRIVATE-AUDIT-CHANGES', false)
            ->assertInertia(fn (Assert $page) => $page->where('dashboard.history.activity.total', 1)
                ->where('dashboard.history.activity.data.0.action', 'Device updated')
                ->missing('dashboard.history.activity.data.0.changes'));
    }

    public function test_non_camera_status_history_exposes_only_actual_sanitized_transitions(): void
    {
        $this->device->update(['type' => 'NVR', 'channels' => 8]);
        foreach ([['online', 'offline'], ['offline', 'offline']] as [$from, $to]) {
            AuditLog::create([
                'subject_type' => 'Device', 'subject_id' => $this->device->id,
                'branch_id' => $this->branch->id, 'user_id' => $this->reader->id,
                'action' => 'Device updated', 'reason' => 'PRIVATE-ADMIN-REASON',
                'changes' => ['before' => ['status' => $from], 'after' => ['status' => $to, 'private' => 'PRIVATE-ADMIN-FIELDS']],
            ]);
        }
        $this->actingAs($this->reader)->get('/devices/'.$this->device->id.'?tab=status')
            ->assertDontSee('PRIVATE-ADMIN-REASON', false)->assertDontSee('PRIVATE-ADMIN-FIELDS', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.can_check', false)->where('dashboard.counts.status_changes', 1)
                ->where('dashboard.history.status.total', 0)->where('dashboard.history.status_changes.total', 1)
                ->where('dashboard.history.status_changes.data.0.from', 'online')
                ->where('dashboard.history.status_changes.data.0.to', 'offline')
                ->missing('dashboard.history.status_changes.data.0.reason')
                ->missing('dashboard.history.status_changes.data.0.changes'));
    }
}
