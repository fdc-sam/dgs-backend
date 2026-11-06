<?php

namespace Database\Seeders;

use App\Support\LocalDatabase;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SettingsSeeder::class);

        // Demo data is for local development only. It is NOT official DGS information. A local .env may point at the
        // live database, so the database itself must be on this machine too, not just APP_ENV saying "local".
        if (! app()->environment(['local', 'testing', 'development', 'staging'])) {
            return;
        }
        if (! LocalDatabase::isLocal()) {
            $this->command?->warn('Skipped the demo data: the database ('.LocalDatabase::describe().') is not on this machine.');

            return;
        }

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
