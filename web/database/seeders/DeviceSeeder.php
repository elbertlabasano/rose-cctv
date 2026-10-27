<?php

namespace Database\Seeders;

use App\Models\Device;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->call(LocationSeeder::class);
            foreach (MonitoringRoster::clusters() as $group) {
                foreach ($group['branches'] as $entry) {
                    $branch = MonitoringRoster::branch($entry);
                    // Reuse an existing camera regardless of its asset ID or saved status.
                    $existing = $branch->devices()->where('type', 'IP Camera')->count();
                    if ($existing > 0) {
                        if ($existing > 1) {
                            $this->command?->warn('Preserved '.$existing.' existing IP cameras at '.$branch->name.'; no extra camera was created.');
                        }

                        continue;
                    }

                    $assetId = 'CAM-'.$entry['code'].'-001';
                    if (Device::where('asset_id', $assetId)->exists()) {
                        throw new RuntimeException('Asset ID conflict for '.$assetId.'. Seeding was rolled back; no existing device was moved or changed.');
                    }
                    Device::create([
                        'asset_id' => $assetId,
                        'name' => 'Branch IP Camera',
                        'branch_id' => $branch->id,
                        'type' => 'IP Camera',
                        'status' => 'unknown',
                        'owner' => 'CCTV Operations',
                        'notes' => 'One IP camera monitoring entry for this branch, from the October 2026 branch roster. Confirm installed equipment details before checking status.',
                    ]);
                }
            }
        });
    }
}
