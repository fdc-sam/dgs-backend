<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/** Inserts default settings without overwriting values the admin already changed. Safe in production. */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('salon.settings') as $key => $default) {
            Setting::firstOrCreate(['key' => $key], $default);
        }
    }
}
