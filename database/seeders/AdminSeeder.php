<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/** LOCAL DEVELOPMENT ONLY — demo accounts with the password "password". */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Demo Super Admin', 'superadmin@example.com', UserRole::SuperAdmin],
            ['Demo Admin', 'admin@example.com', UserRole::Admin],
        ] as [$name, $email, $role]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'role' => $role,
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
        }
    }
}
