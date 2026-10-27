<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class MonitoringSeeder extends Seeder
{
    public function run(): void
    {
        // DeviceSeeder includes its cluster/branch dependencies in one transaction.
        // This entry point never seeds users or changes passwords/access.
        $this->call(DeviceSeeder::class);
    }
}
