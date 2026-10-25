<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Device;
use Illuminate\Database\Seeder;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        // Also support running this seeder on its own.
        $this->call(LocationSeeder::class);

        foreach (LocationSeeder::LOCATIONS as $code => $name) {
            $branch = Branch::where('code', $code)->orWhere('name', $name)->firstOrFail();

            $recorder = Device::firstOrCreate(
                ['asset_id' => "NVR-{$code}-001"],
                [
                    'name' => 'Main recorder',
                    'branch_id' => $branch->id,
                    'type' => 'NVR',
                    'location' => 'Security office',
                    'channels' => 8,
                    'status' => 'unknown',
                    'disk_health' => 'Unknown',
                    'owner' => 'CCTV Operations',
                    'notes' => 'Starter device from seeder. Update details to match installed equipment.',
                ],
            );

            foreach ([1 => 'Main Entrance', 2 => 'Cashier Area'] as $channel => $location) {
                $assetId = sprintf('CAM-%s-%03d', $code, $channel);

                if (Device::where('asset_id', $assetId)->exists()) {
                    continue;
                }

                // Never attach a starter camera to an incompatible existing asset/channel.
                $canConnect = (int) $recorder->branch_id === (int) $branch->id
                    && in_array($recorder->type, ['DVR', 'NVR'], true)
                    && $recorder->channels >= $channel
                    && ! $recorder->children()->where('channel', $channel)->exists();

                Device::create([
                    'asset_id' => $assetId,
                    'name' => $location,
                    'branch_id' => $branch->id,
                    'type' => 'IP Camera',
                    'location' => $location,
                    'parent_id' => $canConnect ? $recorder->id : null,
                    'channel' => $canConnect ? $channel : null,
                    'status' => 'unknown',
                    'owner' => 'CCTV Operations',
                    'notes' => 'Starter camera from seeder. Update details to match installed equipment.',
                ]);
            }
        }
    }
}
