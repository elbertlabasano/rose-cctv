<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cluster;
use App\Models\Device;
use App\Models\IncidentReport;
use App\Models\Observation;
use App\Models\Outage;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchIssueReportTest extends TestCase
{
    private User $admin;

    private Branch $branch;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-10-29 12:00:00', 'Asia/Manila'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 12:00:00', 'Asia/Manila'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->cluster = Cluster::create(['name' => 'North', 'code' => 'N']);
        $this->branch = Branch::create(['name' => 'North branch', 'code' => 'NB', 'cluster_id' => $this->cluster->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function device(string $asset, string $status = 'online', string $date = '2026-10-29'): Device
    {
        $device = Device::create(['asset_id' => $asset, 'name' => 'Branch IP Camera', 'type' => 'IP Camera', 'branch_id' => $this->branch->id, 'status' => $status]);
        Observation::create(['device_id' => $device->id, 'date' => $date, 'status' => $status, 'checked_at' => $date.' 09:00:00']);

        return $device;
    }

    private function incident(Device $device, array $extra = []): IncidentReport
    {
        return IncidentReport::create([
            'reference' => 'IR-'.$device->asset_id, 'device_id' => $device->id, 'branch_id' => $device->branch_id,
            'created_by' => $this->admin->id, 'title' => 'Image unclear', 'description' => 'Inspect the lens',
            'priority' => 'medium', 'category' => 'Unstable video', 'status' => 'assigned',
            'created_at' => '2026-10-25 10:00:00', 'updated_at' => '2026-10-25 10:00:00',
            'due_at' => '2026-10-28 10:00:00', ...$extra,
        ]);
    }

    private function url(array $input = []): string
    {
        return '/reports/issues?'.http_build_query(['date' => '2026-10-29', ...$input]);
    }

    public function test_report_only_includes_status_issues_and_online_equipment_with_recorded_concerns(): void
    {
        $this->device('HEALTHY');
        $offline = $this->device('OFFLINE', 'offline');
        $online = $this->device('ONLINE-ISSUE');
        $this->incident($online);
        Outage::create(['device_id' => $offline->id, 'started_at' => '2026-10-27 23:50:00', 'notes' => 'Outage']);
        $this->actingAs($this->admin)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Issues')->has('report.rows', 2)->where('report.summary.branches', 1)
            ->where('report.summary.devices', 2)->where('report.rows.0.asset_id', 'OFFLINE')
            ->where('report.rows.0.days_offline', 3)->where('report.rows.0.concern_on', '2026-10-27')
            ->where('report.rows.1.status', 'online')->where('report.rows.1.remarks', 'Image unclear — Inspect the lens'));
    }

    public function test_imported_history_can_appear_before_inventory_creation_with_unknown_outage_duration(): void
    {
        $device = Device::create(['asset_id' => 'IMPORTED', 'name' => 'Imported camera', 'type' => 'IP Camera', 'branch_id' => $this->branch->id, 'status' => 'online', 'status_observed_at' => now()]);
        Observation::create(['device_id' => $device->id, 'date' => '2026-10-02', 'status' => 'offline', 'import_source' => 'october_workbook', 'notes' => 'No visual']);
        $this->actingAs($this->admin)->get($this->url(['date' => '2026-10-03']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('report.rows', 1)->where('report.rows.0.status', 'offline')->where('report.rows.0.status_date', '2026-10-02')
            ->where('report.rows.0.days_offline', null)->where('report.rows.0.concern_on', null));
    }

    public function test_historical_reports_exclude_future_actions_and_resolution(): void
    {
        $device = $this->device('HISTORY', 'online', '2026-10-28');
        $incident = $this->incident($device, ['status' => 'resolved', 'resolved_at' => '2026-10-29 11:00:00', 'updated_at' => '2026-10-29 11:00:00']);
        $incident->logs()->create(['action' => 'log', 'notes' => 'Technician inspecting', 'details' => ['to' => 'in_progress'], 'created_at' => '2026-10-27 12:00:00']);
        $incident->logs()->create(['action' => 'resolve', 'notes' => 'Future repair details', 'details' => ['to' => 'resolved'], 'created_at' => '2026-10-29 11:00:00']);
        $this->actingAs($this->admin)->get($this->url(['date' => '2026-10-28']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('report.rows', 1)->where('report.rows.0.issue_state', 'in_progress')
            ->where('report.rows.0.remarks', 'Image unclear — Technician inspecting')->where('report.rows.0.resolved_on', null));
    }

    public function test_resolved_today_is_optional_and_reopened_incidents_are_active_again(): void
    {
        $device = $this->device('RESOLVED');
        $incident = $this->incident($device, ['status' => 'resolved', 'resolved_at' => '2026-10-29 10:00:00', 'updated_at' => '2026-10-29 10:00:00']);
        $incident->logs()->create(['action' => 'resolve', 'notes' => 'Lens repaired', 'details' => ['to' => 'resolved'], 'created_at' => '2026-10-29 10:00:00']);
        $this->actingAs($this->admin)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.rows', 0));
        $this->get($this->url(['include_resolved' => 1]))->assertInertia(fn (Assert $page) => $page
            ->has('report.rows', 1)->where('report.rows.0.resolved_on', '2026-10-29'));
        $incident->update(['status' => 'in_progress']);
        $incident->logs()->create(['action' => 'reopen', 'notes' => 'Concern returned', 'details' => ['to' => 'in_progress'], 'created_at' => '2026-10-29 10:00:00']);
        $this->get($this->url())->assertInertia(fn (Assert $page) => $page->has('report.rows', 1)
            ->where('report.rows.0.issue_state', 'in_progress')->where('report.rows.0.resolved_on', null));
    }

    public function test_resolution_does_not_hide_equipment_that_remains_offline(): void
    {
        $device = $this->device('STILL-OFFLINE', 'offline');
        $this->incident($device, ['status' => 'resolved', 'resolved_at' => '2026-10-28 11:00:00']);
        $this->actingAs($this->admin)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('report.rows', 1)->where('report.rows.0.issue_state', 'camera_status')->where('report.rows.0.incident_id', null));
    }

    public function test_cluster_and_branch_scope_is_enforced_for_preview_csv_and_print(): void
    {
        $this->device('VISIBLE', 'offline');
        $otherCluster = Cluster::create(['name' => 'South', 'code' => 'S']);
        $other = Branch::create(['name' => 'Private south branch', 'code' => 'SB', 'cluster_id' => $otherCluster->id]);
        $foreign = Device::create(['asset_id' => 'PRIVATE-CAMERA', 'name' => 'Private', 'type' => 'IP Camera', 'branch_id' => $other->id, 'status' => 'offline']);
        Observation::create(['device_id' => $foreign->id, 'date' => '2026-10-29', 'status' => 'offline', 'checked_at' => now()]);
        $viewer = User::factory()->create(['role' => 'viewer', 'all_branches' => false]);
        $viewer->branches()->attach($this->branch);
        $this->actingAs($viewer)->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->has('report.rows', 1)->has('report.branches', 1)->has('report.clusters', 1)->where('report.rows.0.asset_id', 'VISIBLE'));
        foreach (['csv', 'html'] as $format) {
            $response = $this->get($this->url(['format' => $format]))->assertOk();
            $body = $format === 'csv' ? $response->streamedContent() : $response->getContent();
            $this->assertStringContainsString('VISIBLE', $body);
            $this->assertStringNotContainsString('PRIVATE-CAMERA', $body);
            $this->get($this->url(['format' => $format, 'cluster_id' => (string) $otherCluster->id]))->assertForbidden();
            $this->get($this->url(['format' => $format, 'branch_id' => $other->id]))->assertForbidden();
        }
        $this->actingAs($this->admin)->getJson($this->url(['cluster_id' => (string) $this->cluster->id, 'branch_id' => $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_exports_escape_formulas_and_html_and_are_not_cached(): void
    {
        $this->branch->update(['name' => '=SUM(1)']);
        $device = $this->device('FORMULA');
        $this->incident($device, ['title' => '=BAD()', 'description' => '<script>bad()</script>']);
        $this->actingAs($this->admin);
        $csv = $this->get($this->url(['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM(1)", $csv);
        $this->assertStringContainsString("'=BAD()", $csv);
        $response = $this->get($this->url(['format' => 'html']))->assertOk()->assertDontSee('<script>', false)->assertSee('<script>bad()</script>');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_status_only_inventory_changes_are_not_projected_backwards(): void
    {
        $device = Device::create(['asset_id' => 'INVENTORY', 'name' => 'Recorder', 'type' => 'DVR', 'branch_id' => $this->branch->id, 'status' => 'offline', 'status_observed_at' => '2026-10-29 10:00:00']);
        $this->actingAs($this->admin)->get($this->url(['date' => '2026-10-28']))->assertInertia(fn (Assert $page) => $page->has('report.rows', 0));
        $this->get($this->url())->assertInertia(fn (Assert $page) => $page->has('report.rows', 1)->where('report.rows.0.device_id', $device->id));
    }

    public function test_invalid_filters_and_unauthenticated_access_are_rejected(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->actingAs($this->admin);
        foreach ([['date' => '2026-10-30'], ['cluster_id' => 'missing'], ['cluster_id' => ['1']], ['include_resolved' => 'yes'], ['format' => 'sql']] as $input) {
            $this->getJson($this->url($input))->assertUnprocessable();
        }
        $this->admin->update(['active' => false]);
        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_a_later_inventory_update_on_the_same_day_supersedes_a_saved_check(): void
    {
        $device = $this->device('LATER-UPDATE');
        $device->observations()->update(['notes' => 'Old online check']);
        $device->update(['status' => 'offline', 'status_observed_at' => '2026-10-29 10:30:00']);
        $this->actingAs($this->admin)->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->has('report.rows', 1)->where('report.rows.0.status', 'offline')
            ->where('report.rows.0.remarks', 'Offline recorded. No active incident report is linked.'));
    }
}
