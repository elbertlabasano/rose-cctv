<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    // Starter locations from the mockup; locations are branches in the application.
    public const LOCATIONS = [
        'MKT' => 'Makati Central',
        'QC' => 'Quezon City',
        'CBU' => 'Cebu IT Park',
        'DVO' => 'Davao Downtown',
    ];

    public function run(): void
    {
        foreach (self::LOCATIONS as $code => $name) {
            // Match either unique identifier without renaming an existing branch.
            if (Branch::where('code', $code)->orWhere('name', $name)->exists()) {
                continue;
            }

            Branch::create([
                'code' => $code,
                'name' => $name,
                'active' => true,
            ]);
        }
    }
}
