<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Observation;
use App\Models\User;
use App\Services\OctoberMonitoringImportService;
use Carbon\CarbonImmutable;
use Database\Seeders\MonitoringSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class OctoberMonitoringImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function smallImport(array $days): OctoberMonitoringImportService
    {
        return new class($days) extends OctoberMonitoringImportService
        {
            public function __construct(private array $days) {}

            public function snapshot(): array
            {
                return ['source' => 'OCTOBER 2026.xlsx', 'sheet' => 'Sheet1 (2)', 'sha256' => 'test-hash',
                    'branches' => [['branch_code' => 'TEST', 'branch_name' => 'TEST BRANCH', 'days' => $this->days]]];
            }
        };
    }

    private function camera(): Device
    {
        $branch = Branch::create(['name' => 'TEST BRANCH', 'code' => 'TEST']);

        return Device::create(['asset_id' => 'TEST-CAMERA', 'name' => 'IP Camera', 'branch_id' => $branch->id, 'type' => 'IP Camera', 'status' => 'unknown']);
    }

    public function test_full_month_snapshot_imports_reported_cells_only_and_reruns_preserve_everything(): void
    {
        $this->seed(MonitoringSeeder::class);
        $import = app(OctoberMonitoringImportService::class);
        $preview = $import->run(true, true);
        $this->assertSame(2909, $preview['imported']);
        $this->assertSame(112, $preview['statuses_initialized']);
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $result = $import->run(false, true);
        $this->assertSame(2909, $result['imported']);
        $this->assertDatabaseCount('observations', 2909);
        $this->assertSame(['day_off' => 423], Observation::whereNotNull('check_result')->selectRaw('check_result, count(*) as total')->groupBy('check_result')->pluck('total', 'check_result')->all());
        $this->assertSame(2014, Observation::where('status', 'online')->count());
        $this->assertSame(472, Observation::where('status', 'offline')->count());
        $this->assertSame(0, Observation::whereNotNull('checked_at')->count());
        $this->assertSame(0, Observation::whereNotNull('checked_by')->count());
        $this->assertSame(0, Observation::where('date', '>', '2026-10-26')->count());
        $this->assertSame(111, Observation::whereDate('date', '2026-10-26')->count());
        $this->assertSame(92, Observation::whereDate('date', '2026-10-26')->where('status', 'online')->count());
        $this->assertSame(19, Observation::whereDate('date', '2026-10-26')->where('status', 'offline')->count());
        $this->assertSame(112, Observation::whereDate('date', '2026-10-04')->count());
        $this->assertSame(106, Observation::whereDate('date', '2026-10-04')->where('check_result', 'day_off')->count());
        $this->assertSame(92, Device::where('status', 'online')->count());
        $this->assertSame(20, Device::where('status', 'offline')->count());
        $maringondon = Device::whereHas('branch', fn ($q) => $q->where('name', 'MARINGONDON'))->sole();
        $this->assertSame('2026-10-22', $maringondon->status_reported_on->toDateString());
        $this->assertNull($maringondon->status_observed_at);
        $this->assertDatabaseCount('outages', 0);
        $this->assertDatabaseCount('ticket_requests', 0);
        $rerun = $import->run(false, true);
        $this->assertSame(0, $rerun['imported']);
        $this->assertSame(2909, $rerun['preserved']);
        $this->assertSame(0, $rerun['statuses_initialized']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_existing_manual_records_and_current_device_status_are_never_overwritten(): void
    {
        $device = $this->camera();
        $device->update(['status' => 'online', 'status_observed_at' => now()]);
        $record = Observation::create(['device_id' => $device->id, 'date' => '2026-10-26', 'status' => 'online', 'checked_at' => now(), 'notes' => 'Keep manual check']);
        $before = $record->fresh()->getAttributes();
        $result = $this->smallImport([
            '2026-10-01' => ['value' => 'OFF', 'cell' => 'D9'],
            '2026-10-26' => ['value' => 'OFF', 'cell' => 'AC9'],
            '2026-10-27' => ['value' => 'ON', 'cell' => 'AD9'],
        ])->run(false, true);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['preserved']);
        $this->assertSame(1, $result['future_skipped']);
        $this->assertSame(0, $result['statuses_initialized']);
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame('online', $device->fresh()->status);
        $this->assertNull($device->fresh()->status_reported_on);
    }

    public function test_day_off_holiday_no_device_and_alert_are_report_markers_without_a_fake_check(): void
    {
        $device = $this->camera();
        $this->smallImport([
            '2026-10-01' => ['value' => 'DAYOFF', 'cell' => 'D9'],
            '2026-10-02' => ['value' => 'HOLIDAY', 'cell' => 'E9'],
            '2026-10-03' => ['value' => 'NO DEVICE', 'cell' => 'F9'],
            '2026-10-04' => ['value' => 'ALERT', 'cell' => 'G9'],
        ])->run(false, true);
        foreach (Observation::orderBy('date')->get() as $record) {
            $this->assertNull($record->status);
            $this->assertNull($record->checked_at);
            $this->assertFalse($record->hasStatusCheck());
            $this->assertNotSame('unchecked', $record->displayStatus());
        }
        $this->assertSame('unknown', $device->fresh()->status);
        $this->assertDatabaseCount('outages', 0);
    }

    public function test_ambiguous_devices_roll_back_import_and_do_not_guess_which_camera_to_use(): void
    {
        $device = $this->camera();
        Device::create(['asset_id' => 'SECOND-CAMERA', 'name' => 'Other IP Camera', 'branch_id' => $device->branch_id, 'type' => 'IP Camera']);
        $this->expectException(RuntimeException::class);
        try {
            $this->smallImport(['2026-10-01' => ['value' => 'ON', 'cell' => 'D9']])->run();
        } finally {
            $this->assertDatabaseCount('observations', 0);
        }
    }

    public function test_imported_records_appear_in_authorized_history_and_past_dates_remain_read_only(): void
    {
        $device = $this->camera();
        $this->smallImport([
            '2026-10-01' => ['value' => 'ON', 'cell' => 'D9'],
            '2026-10-04' => ['value' => 'DAYOFF', 'cell' => 'G9'],
        ])->run();
        $user = User::factory()->create(['role' => 'operator', 'all_branches' => false]);
        $user->branches()->attach($device->branch_id);
        $this->actingAs($user)->get('/cameras?date=2026-10-26')->assertInertia(fn (Assert $page) => $page
            ->has('workspace.observations', 2)->where('workspace.observations.0.import_source', 'OCTOBER 2026.xlsx')
            ->where('workspace.observations.0.is_checked', true)->where('workspace.observations.0.checked_at', null));
        $this->get('/devices/'.$device->id.'?from=2026-10-01&to=2026-10-04')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.days.0.status', 'online')->where('dashboard.days.3.status', 'day_off'));
        $this->post('/observations', ['device_ids' => [$device->id], 'date' => '2026-10-01', 'update_status' => true,
            'attach_images' => false, 'create_ticket' => false, 'status' => 'offline', 'notes' => 'Cannot edit past'])
            ->assertSessionHasErrors('date');
        $this->assertSame('online', Observation::whereDate('date', '2026-10-01')->sole()->status);
        $csv = $this->get('/reports/export?date=2026-10-04&format=csv')->assertOk();
        $this->assertStringContainsString('day off', $csv->streamedContent());
        $this->get('/reports/export?date=2026-10-04&format=html')->assertOk()->assertSee('day off');
    }

    public function test_a_current_manual_check_replaces_import_provenance_with_a_real_check_time(): void
    {
        $device = $this->camera();
        $this->smallImport(['2026-10-26' => ['value' => 'OFF', 'cell' => 'AC9']])->run(false, true);
        $user = User::factory()->create(['role' => 'operator', 'all_branches' => true]);
        $this->actingAs($user)->post('/observations', ['device_ids' => [$device->id], 'date' => '2026-10-26',
            'update_status' => true, 'attach_images' => false, 'create_ticket' => false,
            'status' => 'online', 'notes' => 'Checked the actual camera; online'])->assertSessionHasNoErrors();
        $record = Observation::sole();
        $this->assertSame('online', $record->status);
        $this->assertSame($user->id, $record->checked_by);
        $this->assertNotNull($record->checked_at);
        $this->assertNull($record->import_source);
        $this->assertNull($record->import_reference);
        $this->assertNull($record->check_result);
        $this->assertNull($device->fresh()->status_reported_on);
        $this->assertNotNull($device->fresh()->status_observed_at);
    }

    public function test_status_initialization_uses_the_latest_date_even_if_source_cells_are_out_of_order(): void
    {
        $device = $this->camera();
        $this->smallImport([
            '2026-10-26' => ['value' => 'OFF', 'cell' => 'AC9'],
            '2026-10-01' => ['value' => 'ON', 'cell' => 'D9'],
        ])->run(false, true);
        $this->assertSame('offline', $device->fresh()->status);
        $this->assertSame('2026-10-26', $device->fresh()->status_reported_on->toDateString());
    }
}
