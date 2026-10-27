<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cluster;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DeviceSeeder;
use Database\Seeders\MonitoringSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No RefreshDatabase: migrate only the guarded in-memory connection.
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
    }

    public function test_seeders_create_requested_accounts_and_one_ip_camera_for_each_real_branch(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('clusters', 10);
        $this->assertDatabaseCount('branches', 112);
        $this->assertDatabaseCount('devices', 112);
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
        $cameras = Device::with('branch')->where('type', 'IP Camera')->get();
        $this->assertCount(112, $cameras);
        $this->assertCount(112, $cameras->pluck('branch_id')->unique());
        foreach ($cameras as $camera) {
            $this->assertSame('unknown', $camera->status);
            $this->assertNull($camera->status_observed_at);
            $this->assertNull($camera->parent_id);
            $this->assertNull($camera->channel);
            $this->assertNull($camera->ip);
            $this->assertNotNull($camera->branch->cluster_id);
        }
        $this->assertSame([
            'BANKID' => 7, 'CALINAN/TORIL' => 14, 'CEBU' => 1, 'DIGOS' => 18,
            'DOWNTOWN NORTH' => 16, 'DOWNTOWN SOUTH' => 12, 'GENSAN' => 7,
            'INDANGAN' => 5, 'LEYTE' => 16, 'TAGUM/PANABO' => 16,
        ], Cluster::withCount('branches')->orderBy('name')->get()->pluck('branches_count', 'name')->all());
        $this->assertSame('LEYTE', Branch::where('name', 'BATO 3')->firstOrFail()->cluster->name);
        $this->assertSame('DOWNTOWN SOUTH', Branch::where('name', 'OBRERO')->firstOrFail()->cluster->name);
    }

    public function test_rerunning_seeders_preserves_existing_passwords_permissions_and_equipment(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'elbert@rosebakeshop.net')->firstOrFail();
        $user->update(['name' => 'Existing account', 'password' => 'changed-password', 'role' => 'viewer', 'active' => false, 'all_branches' => false, 'idcode' => 'KEEP-THIS-CODE']);
        $hash = $user->password;
        $branch = Branch::where('code', 'AGDAO-1')->firstOrFail();
        $branch->update(['name' => 'Renamed branch', 'active' => false]);
        $camera = Device::where('asset_id', 'CAM-AGDAO-1-001')->firstOrFail();
        $camera->update(['name' => 'Existing camera', 'status' => 'offline', 'notes' => 'Keep notes']);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('clusters', 10);
        $this->assertDatabaseCount('branches', 112);
        $this->assertDatabaseCount('devices', 112);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame('KEEP-THIS-CODE', $user->fresh()->idcode);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Existing account', 'role' => 'viewer', 'active' => false, 'all_branches' => false]);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'code' => 'AGDAO-1', 'name' => 'Renamed branch', 'active' => false]);
        $this->assertDatabaseHas('devices', ['id' => $camera->id, 'name' => 'Existing camera', 'status' => 'offline', 'notes' => 'Keep notes']);
    }

    public function test_monitoring_seeder_reuses_case_insensitive_names_and_existing_ip_camera_without_seeding_users(): void
    {
        $branch = Branch::create(['name' => 'Obrero', 'code' => 'EXISTING', 'active' => false, 'address' => 'Keep address']);
        $camera = Device::create(['asset_id' => 'EXISTING-CAMERA', 'name' => 'Camera1', 'branch_id' => $branch->id,
            'type' => 'IP Camera', 'status' => 'offline', 'ip' => '10.0.0.5', 'brand' => 'Existing brand', 'notes' => 'Keep notes']);
        $original = $camera->fresh()->getAttributes();
        $this->seed(MonitoringSeeder::class);
        $this->seed(MonitoringSeeder::class);
        $this->assertDatabaseCount('branches', 112);
        $this->assertDatabaseCount('devices', 112);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame($original, $camera->fresh()->getAttributes());
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'Obrero', 'code' => 'EXISTING', 'address' => 'Keep address', 'active' => false]);
        $this->assertSame('DOWNTOWN SOUTH', $branch->fresh()->cluster->name);
        $this->assertSame(0, Device::whereIn('type', ['NVR', 'DVR'])->count());
    }

    public function test_existing_cluster_assignments_and_extra_equipment_are_preserved(): void
    {
        $cluster = Cluster::create(['name' => 'Existing cluster', 'code' => 'CUSTOM']);
        $branch = Branch::create(['name' => 'AGDAO 1', 'code' => 'AGDAO-1', 'cluster_id' => $cluster->id]);
        foreach (['FIRST', 'SECOND'] as $asset) {
            Device::create(['asset_id' => $asset, 'name' => 'Existing camera', 'branch_id' => $branch->id, 'type' => 'IP Camera']);
        }
        Device::create(['asset_id' => 'RECORDER', 'name' => 'Existing recorder', 'branch_id' => $branch->id, 'type' => 'NVR']);
        $this->seed(DeviceSeeder::class);
        $this->assertSame($cluster->id, $branch->fresh()->cluster_id);
        $this->assertSame(2, $branch->devices()->where('type', 'IP Camera')->count());
        $this->assertDatabaseCount('devices', 114);
        $this->assertDatabaseHas('devices', ['asset_id' => 'RECORDER']);
    }

    public function test_asset_id_conflict_rolls_back_all_new_clusters_branches_and_devices(): void
    {
        $branch = Branch::create(['name' => 'Other branch', 'code' => 'OTHER']);
        Device::create(['asset_id' => 'CAM-BAJADA-001', 'name' => 'Existing asset', 'branch_id' => $branch->id, 'type' => 'NVR']);
        try {
            $this->seed(MonitoringSeeder::class);
            $this->fail('An occupied asset ID must never be reassigned.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Asset ID conflict', $exception->getMessage());
        }
        $this->assertDatabaseCount('clusters', 0);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseHas('devices', ['asset_id' => 'CAM-BAJADA-001', 'branch_id' => $branch->id, 'type' => 'NVR']);
    }

    public function test_name_and_code_conflicts_do_not_silently_select_the_wrong_branch(): void
    {
        Branch::create(['name' => 'AGDAO 1', 'code' => 'OTHER']);
        Branch::create(['name' => 'Different branch', 'code' => 'AGDAO-1']);
        try {
            $this->seed(MonitoringSeeder::class);
            $this->fail('Ambiguous branch identities must stop the seed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Ambiguous existing branch', $exception->getMessage());
        }
        $this->assertDatabaseCount('clusters', 0);
        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_admin_can_assign_clusters_and_other_users_only_receive_clusters_of_assigned_branches(): void
    {
        $a = Cluster::create(['name' => 'North', 'code' => 'NORTH']);
        $b = Cluster::create(['name' => 'South', 'code' => 'SOUTH']);
        $branch = Branch::create(['name' => 'My branch', 'code' => 'MINE', 'cluster_id' => $a->id]);
        Branch::create(['name' => 'Other branch', 'code' => 'OTHER', 'cluster_id' => $b->id]);
        $viewer = User::factory()->create(['role' => 'viewer', 'all_branches' => false]);
        $viewer->branches()->attach($branch);
        $this->actingAs($viewer)->get('/branches')->assertInertia(fn (Assert $page) => $page
            ->has('workspace.clusters', 1)->where('workspace.clusters.0.name', 'North')
            ->where('workspace.branches.0.cluster.name', 'North'));
        $this->put('/branches/'.$branch->id, ['name' => 'My branch', 'code' => 'MINE', 'active' => true, 'cluster_id' => $b->id])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put('/branches/'.$branch->id, ['name' => 'My branch', 'code' => 'MINE', 'active' => true, 'cluster_id' => 99999])->assertSessionHasErrors('cluster_id');
        $this->put('/branches/'.$branch->id, ['name' => 'My branch', 'code' => 'MINE', 'active' => true, 'cluster_id' => $b->id])->assertSessionHasNoErrors();
        $this->assertSame($b->id, $branch->fresh()->cluster_id);
    }
}
