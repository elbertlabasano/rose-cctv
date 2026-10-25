<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceSearchTest extends TestCase
{
    private User $reader;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        // Only the guarded in-memory database is migrated. Never use RefreshDatabase here.
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->reader = User::factory()->create(['role' => 'viewer', 'all_branches' => false]);
        $this->branch = Branch::create(['name' => 'East Bakery', 'code' => 'EAST']);
        $this->reader->branches()->attach($this->branch);
    }

    private function device(array $attributes = []): Device
    {
        return Device::create([
            'asset_id' => 'CAM-'.Str::random(8), 'name' => 'Main entrance',
            'branch_id' => $this->branch->id, 'type' => 'IP Camera', 'status' => 'unknown',
            ...$attributes,
        ]);
    }

    private function search(string $query)
    {
        return $this->getJson('/devices/search?'.http_build_query(['q' => $query]));
    }

    public function test_search_requires_an_active_account_and_never_returns_unassigned_devices(): void
    {
        $this->search('camera')->assertUnauthorized();
        $foreign = Branch::create(['name' => 'Private bakery', 'code' => 'PRIVATE']);
        $this->device(['branch_id' => $foreign->id, 'name' => 'Private camera']);
        $this->actingAs($this->reader);
        $this->search('Private')->assertOk()->assertExactJson(['devices' => [], 'has_more' => false]);
        $this->reader->branches()->detach();
        $this->device(['name' => 'Unassigned camera']);
        $this->search('camera')->assertOk()->assertJsonCount(0, 'devices');
        $this->reader->update(['active' => false]);
        $this->search('camera')->assertRedirect('/login');
    }

    public function test_search_matches_device_and_branch_fields_and_returns_only_safe_dashboard_details(): void
    {
        $device = $this->device([
            'asset_id' => 'CAM-EAST-001', 'name' => 'Loading Camera', 'location' => 'Stock room',
            'ip' => '10.0.0.10', 'serial' => 'PRIVATE-SERIAL', 'notes' => 'PRIVATE-NOTES',
        ]);
        $this->actingAs($this->reader);
        foreach (['cam-east-001', '  LOADING  ', 'Stock room', 'east bakery', 'EAST'] as $term) {
            $response = $this->search($term)->assertOk()->assertJsonCount(1, 'devices')
                ->assertJsonPath('devices.0.id', $device->id)
                ->assertJsonPath('devices.0.url', '/devices/'.$device->id)
                ->assertJsonPath('devices.0.branch', 'East Bakery')
                ->assertJsonMissingPath('devices.0.ip')->assertJsonMissingPath('devices.0.serial')
                ->assertJsonMissingPath('devices.0.notes')->assertJsonPath('has_more', false);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }
    }

    public function test_search_prioritizes_asset_matches_and_limits_results(): void
    {
        $exact = $this->device(['asset_id' => 'CAM-EXACT', 'name' => 'Door']);
        $prefix = $this->device(['asset_id' => 'CAM-EXACT-SECOND']);
        $this->device(['name' => 'CAM-EXACT']);
        $this->actingAs($this->reader)->search('CAM-EXACT')->assertOk()
            ->assertJsonPath('devices.0.id', $exact->id)->assertJsonPath('devices.1.id', $prefix->id);
        for ($index = 0; $index < 14; $index++) {
            $this->device(['name' => 'Hall camera']);
        }
        $this->search('Hall camera')->assertOk()->assertJsonCount(12, 'devices')->assertJsonPath('has_more', true);
    }

    public function test_literal_wildcards_cannot_expand_the_search_or_bypass_permissions(): void
    {
        $underscore = $this->device(['name' => 'Loading_Bay']);
        $percent = $this->device(['name' => 'Loading%Area']);
        $this->device(['name' => 'LoadingXBay']);
        $this->actingAs($this->reader);
        $this->search('Loading_')->assertOk()->assertJsonCount(1, 'devices')->assertJsonPath('devices.0.id', $underscore->id);
        $this->search('Loading%')->assertOk()->assertJsonCount(1, 'devices')->assertJsonPath('devices.0.id', $percent->id);
        $this->search("%' OR 1=1 --")->assertOk()->assertJsonCount(0, 'devices');
    }

    public function test_admin_can_search_all_branches_and_queries_are_validated(): void
    {
        $foreign = Branch::create(['name' => 'West Bakery', 'code' => 'WEST']);
        $device = $this->device(['branch_id' => $foreign->id, 'type' => 'NVR', 'name' => 'Recorder']);
        $this->reader->update(['role' => 'admin']);
        $this->actingAs($this->reader)->search('Recorder')->assertOk()->assertJsonPath('devices.0.id', $device->id);
        foreach (['', ' ', 'a', str_repeat('a', 101)] as $term) {
            $this->search($term)->assertUnprocessable()->assertJsonValidationErrors('q');
        }
        $this->getJson('/devices/search?q[]=camera')->assertUnprocessable()->assertJsonValidationErrors('q');
    }
}
