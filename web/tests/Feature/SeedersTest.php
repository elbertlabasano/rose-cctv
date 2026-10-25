<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DeviceSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No RefreshDatabase or persistent database: migrate only the guarded in-memory connection.
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
    }

    public function test_seeders_create_the_requested_accounts_and_connected_starter_cameras(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('branches', 4);
        $this->assertDatabaseCount('devices', 12);
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('outages', 0);

        foreach (['rynhart@rosebakeshop.net', 'elbert@rosebakeshop.net'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check('password', $user->password));
            $this->assertNotSame('password', $user->password);
            $this->assertSame('admin', $user->role);
            $this->assertTrue($user->active);
            $this->assertTrue($user->all_branches);
            $this->assertSame($email === 'elbert@rosebakeshop.net' ? '20260211' : null, $user->idcode);
        }

        $cameras = Device::where('type', 'IP Camera')->get();
        $this->assertCount(8, $cameras);

        foreach ($cameras as $camera) {
            $this->assertSame('unknown', $camera->status);
            $this->assertNull($camera->status_observed_at);
            $this->assertSame('NVR', $camera->parent->type);
            $this->assertSame($camera->branch_id, $camera->parent->branch_id);
            $this->assertContains($camera->channel, [1, 2]);
        }
    }

    public function test_rerunning_seeders_preserves_existing_passwords_permissions_and_equipment(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'elbert@rosebakeshop.net')->firstOrFail();
        $user->update(['name' => 'Existing account', 'password' => 'changed-password', 'role' => 'viewer', 'active' => false, 'all_branches' => false, 'idcode' => 'KEEP-THIS-CODE']);
        $hash = $user->password;

        $branch = Branch::where('code', 'MKT')->firstOrFail();
        $branch->update(['name' => 'Renamed branch', 'active' => false]);

        $camera = Device::where('asset_id', 'CAM-MKT-001')->firstOrFail();
        $camera->update(['name' => 'Existing camera', 'status' => 'offline', 'notes' => 'Keep these notes']);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('branches', 4);
        $this->assertDatabaseCount('devices', 12);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame('KEEP-THIS-CODE', $user->fresh()->idcode);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Existing account', 'role' => 'viewer', 'active' => false, 'all_branches' => false]);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'Renamed branch', 'active' => false]);
        $this->assertDatabaseHas('devices', ['id' => $camera->id, 'name' => 'Existing camera', 'status' => 'offline', 'notes' => 'Keep these notes']);
    }

    public function test_device_seeder_alone_handles_existing_location_names_and_occupied_channels(): void
    {
        $branch = Branch::create(['name' => 'Makati Central', 'code' => 'EXISTING']);
        $recorder = Device::create(['asset_id' => 'NVR-MKT-001', 'name' => 'Existing recorder', 'branch_id' => $branch->id, 'type' => 'NVR', 'channels' => 8]);
        Device::create(['asset_id' => 'EXISTING-CAMERA', 'name' => 'Existing camera', 'branch_id' => $branch->id, 'type' => 'IP Camera', 'parent_id' => $recorder->id, 'channel' => 1]);

        $this->seed(DeviceSeeder::class);

        $this->assertDatabaseCount('branches', 4);
        $this->assertDatabaseCount('devices', 13);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'code' => 'EXISTING']);
        $this->assertDatabaseHas('devices', ['asset_id' => 'EXISTING-CAMERA', 'parent_id' => $recorder->id, 'channel' => 1]);
        $this->assertDatabaseHas('devices', ['asset_id' => 'CAM-MKT-001', 'branch_id' => $branch->id, 'parent_id' => null, 'channel' => null]);
        $this->assertDatabaseHas('devices', ['asset_id' => 'CAM-MKT-002', 'parent_id' => $recorder->id, 'channel' => 2]);
    }
}
