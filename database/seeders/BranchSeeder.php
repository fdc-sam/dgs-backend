<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

/** Sample branches — NOT real DGS locations. Replace via the admin. */
class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            ['Sample Branch A (Demo)', 'sample-branch-a', '123 Sample Street, Demo City', 14.5995, 120.9842, ['09:00', '19:00'], []],
            ['Sample Branch B (Demo)', 'sample-branch-b', '456 Example Avenue, Demo City', 14.5547, 121.0244, ['10:00', '20:00'], [0]],
        ];

        foreach ($branches as [$name, $slug, $address, $lat, $lng, [$open, $close], $closedDays]) {
            $branch = Branch::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => 'Development sample branch. Not official DGS information.',
                'address' => $address,
                'phone' => '0000-000-0000',
                'email' => $slug.'@example.com',
                'latitude' => $lat,
                'longitude' => $lng,
                'opening_time' => $open,
                'closing_time' => $close,
                'status' => 'active',
            ]);

            foreach (range(0, 6) as $day) {
                $isOpen = ! in_array($day, $closedDays, true);
                $branch->businessHours()->updateOrCreate(['day_of_week' => $day], [
                    'is_open' => $isOpen,
                    'opening_time' => $isOpen ? $open : null,
                    'closing_time' => $isOpen ? $close : null,
                ]);
            }
        }
    }
}
