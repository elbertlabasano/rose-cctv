<?php

namespace Database\Seeders;

use App\Models\Cluster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClusterSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach (MonitoringRoster::clusters() as $entry) {
                if (! MonitoringRoster::cluster($entry)) {
                    Cluster::create(['code' => $entry['code'], 'name' => $entry['name']]);
                }
            }
        });
    }
}
