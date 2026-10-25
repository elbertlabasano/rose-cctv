<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'rynhart@rosebakeshop.net' => 'Rynhart',
            'elbert@rosebakeshop.net' => 'Elbert',
        ] as $email => $name) {
            // User's hashed cast stores a hash; existing credentials and access stay intact.
            User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'idcode' => $email === 'elbert@rosebakeshop.net' ? '20260211' : null,
                    'password' => 'password',
                    'role' => 'admin',
                    'active' => true,
                    'all_branches' => true,
                    'team' => 'CCTV Operations',
                ],
            );
        }
    }
}
