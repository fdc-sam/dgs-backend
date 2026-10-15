<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SettingsSeeder::class);

        // Demo data is for local development only. It is NOT official DGS information.
        if (app()->environment(['local', 'testing', 'development', 'staging'])) {
            $this->call([
                AdminSeeder::class,
                BranchSeeder::class,
                ServiceCategorySeeder::class,
                ServiceSeeder::class,
                ProductSeeder::class,
                StaffSeeder::class,
                PromotionSeeder::class,
                DemoCustomerSeeder::class,
                DemoAppointmentSeeder::class,
            ]);
        }
    }
}
