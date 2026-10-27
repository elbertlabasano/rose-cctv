<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cluster;
use App\Models\Device;
use App\Models\IncidentReport;
use App\Models\Observation;
use App\Models\User;
use Tests\TestCase;

class ClusterReportFilterTest extends TestCase
{
    private Cluster $north;

    private Cluster $south;

    private Branch $northBranch;

    private Branch $southBranch;

    private Branch $unassigned;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->north = Cluster::create(['name' => 'North', 'code' => 'NORTH']);
        $this->south = Cluster::create(['name' => 'South', 'code' => 'SOUTH']);
        $this->northBranch = Branch::create(['name' => 'North branch', 'code' => 'N', 'cluster_id' => $this->north->id]);
        $this->southBranch = Branch::create(['name' => 'South branch', 'code' => 'S', 'cluster_id' => $this->south->id]);
        $this->unassigned = Branch::create(['name' => 'Unassigned branch', 'code' => 'U']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        foreach ([$this->northBranch, $this->southBranch, $this->unassigned] as $branch) {
            $device = Device::create(['asset_id' => 'CAM-'.$branch->code, 'name' => 'Camera '.$branch->code,
                'type' => 'IP Camera', 'branch_id' => $branch->id, 'status' => 'online']);
            Observation::create(['device_id' => $device->id, 'date' => now()->toDateString(), 'status' => 'online', 'checked_at' => now()]);
            IncidentReport::create(['reference' => 'IR-'.$branch->code, 'device_id' => $device->id,
                'branch_id' => $branch->id, 'created_by' => $this->admin->id,
                'title' => 'Issue '.$branch->code, 'description' => 'Scope test', 'status' => 'assigned',
                'priority' => 'medium', 'category' => 'Camera offline', 'due_at' => now()->addDay()]);
        }
    }

    private function url(array $scope = [], string $format = 'csv'): string
    {
        return '/reports/export?'.http_build_query(['date' => now()->toDateString(), 'format' => $format, ...$scope]);
    }

    public function test_cluster_scope_applies_to_csv_cameras_and_html_incidents(): void
    {
        $this->actingAs($this->admin);
        $contents = $this->get($this->url(['cluster_id' => (string) $this->north->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('CAM-N', $contents);
        $this->assertStringNotContainsString('CAM-S', $contents);
        $this->assertStringNotContainsString('CAM-U', $contents);
        $this->get($this->url(['cluster_id' => (string) $this->north->id], 'html'))->assertOk()
            ->assertSee('CAM-N')->assertSee('IR-N')->assertDontSee('CAM-S')->assertDontSee('IR-S')->assertDontSee('CAM-U');
    }

    public function test_assigned_and_unassigned_scopes_filter_the_export_consistently(): void
    {
        $this->actingAs($this->admin);
        $assigned = $this->get($this->url(['cluster_id' => 'clustered']))->assertOk()->streamedContent();
        $this->assertStringContainsString('CAM-N', $assigned);
        $this->assertStringContainsString('CAM-S', $assigned);
        $this->assertStringNotContainsString('CAM-U', $assigned);
        $unassigned = $this->get($this->url(['cluster_id' => 'unassigned']))->assertOk()->streamedContent();
        $this->assertStringContainsString('CAM-U', $unassigned);
        $this->assertStringNotContainsString('CAM-N', $unassigned);
        $this->assertStringNotContainsString('CAM-S', $unassigned);
    }

    public function test_branch_selection_intersects_cluster_scope_and_cannot_broaden_it(): void
    {
        $this->actingAs($this->admin);
        $this->getJson($this->url(['cluster_id' => (string) $this->north->id, 'branch_id' => $this->southBranch->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $contents = $this->get($this->url(['cluster_id' => (string) $this->north->id, 'branch_id' => $this->northBranch->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('CAM-N', $contents);
        $this->assertStringNotContainsString('CAM-S', $contents);
    }

    public function test_cluster_filters_never_bypass_branch_authorization(): void
    {
        $reader = User::factory()->create(['role' => 'viewer', 'all_branches' => false]);
        $reader->branches()->attach($this->northBranch);
        $this->actingAs($reader)->get($this->url(['cluster_id' => (string) $this->south->id]))->assertForbidden();
        $this->get($this->url(['cluster_id' => 'all', 'branch_id' => $this->southBranch->id]))->assertForbidden();
        $contents = $this->get($this->url(['cluster_id' => 'clustered']))->assertOk()->streamedContent();
        $this->assertStringContainsString('CAM-N', $contents);
        $this->assertStringNotContainsString('CAM-S', $contents);
        $this->assertStringNotContainsString('CAM-U', $contents);
    }

    public function test_invalid_cluster_filters_are_rejected_and_an_empty_admin_cluster_is_valid(): void
    {
        $this->actingAs($this->admin);
        foreach (['missing', '-1', '999999', ['1']] as $cluster) {
            $this->getJson($this->url(['cluster_id' => $cluster]))->assertUnprocessable()->assertJsonValidationErrors('cluster_id');
        }
        $empty = Cluster::create(['name' => 'Empty', 'code' => 'EMPTY']);
        $contents = $this->get($this->url(['cluster_id' => (string) $empty->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Date,Camera,Branch,Status', $contents);
        $this->assertStringNotContainsString('CAM-', $contents);
    }
}
