<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->call(ClusterSeeder::class);
            foreach (MonitoringRoster::clusters() as $group) {
                $cluster = MonitoringRoster::cluster($group);
                foreach ($group['branches'] as $entry) {
                    $branch = MonitoringRoster::branch($entry);
                    if (! $branch) {
                        Branch::create([...$entry, 'cluster_id' => $cluster->id, 'active' => true]);
                    } elseif ($branch->cluster_id === null) {
                        $branch->update(['cluster_id' => $cluster->id]);
                    } elseif ((int) $branch->cluster_id !== (int) $cluster->id) {
                        $this->command?->warn('Preserved the existing cluster assignment for '.$branch->name.'.');
                    }
                }
            }
        });
    }
}
