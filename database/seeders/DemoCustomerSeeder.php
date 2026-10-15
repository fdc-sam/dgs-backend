<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/** LOCAL DEVELOPMENT ONLY — demo customer login customer@example.com / password. */
class DemoCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'customer@example.com';
        $user = User::updateOrCreate(['email' => $email], [
            'name' => 'Demo Customer',
            'phone' => '09170000000',
            'profile_photo' => 'customers/demo-customer.jpg',
            'role' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ] + (User::where('email', $email)->exists() ? [] : ['password' => 'password'])); // never reset an existing password
        $user->customerProfile()->firstOrCreate([]);
    }
}
