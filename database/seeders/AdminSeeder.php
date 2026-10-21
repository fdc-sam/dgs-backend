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
            ['Demo Super Admin', 'superadmin@example.com', UserRole::SuperAdmin, false],
            ['Demo Admin', 'admin@example.com', UserRole::Admin, false],
            // Sees only test customers and their bookings; the salon setup is view-only for it.
            ['Demo Test Admin', 'testadmin@example.com', UserRole::Admin, true],
        ] as [$name, $email, $role, $test]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'role' => $role,
                'status' => 'active',
                'email_verified_at' => now(),
            ])->forceFill(['is_test' => $test])->save();
        }
    }
}
