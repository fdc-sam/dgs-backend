<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Sample staff — fictional people. Password for all: "password". */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $branchA = Branch::where('slug', 'sample-branch-a')->firstOrFail();
        $branchB = Branch::where('slug', 'sample-branch-b')->firstOrFail();

        // [name, email, position, branch, category slugs, working days, profile photo]
        $staff = [
            ['Demo Stylist Ana', 'staff.ana@example.com', 'Senior Stylist', $branchA, ['haircut-styling', 'hair-color', 'rebond-keratin'], [1, 2, 3, 4, 5, 6], 'staff/demo-stylist-ana.jpg'],
            ['Demo Stylist Ben', 'staff.ben@example.com', 'Colorist', $branchA, ['haircut-styling', 'hair-color', 'hair-treatment'], [0, 2, 3, 4, 5, 6], 'staff/demo-stylist-ben.jpg'],
            ['Demo Therapist Cara', 'staff.cara@example.com', 'Spa Therapist', $branchA, ['spa-massage', 'nail-care', 'hair-treatment'], [0, 1, 2, 3, 4, 5], 'staff/demo-therapist-cara.jpg'],
            ['Demo Stylist Dan', 'staff.dan@example.com', 'Stylist', $branchB, ['haircut-styling', 'rebond-keratin', 'hair-treatment'], [1, 2, 3, 4, 5, 6], 'staff/demo-stylist-dan.jpg'],
            ['Demo Technician Eve', 'staff.eve@example.com', 'Nail Technician', $branchB, ['nail-care', 'spa-massage'], [1, 2, 3, 4, 5, 6], 'staff/demo-technician-eve.jpg'],
        ];

        foreach ($staff as $i => [$name, $email, $position, $branch, $categorySlugs, $days, $profilePhoto]) {
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'profile_photo' => $profilePhoto,
                'role' => UserRole::Staff,
                'status' => 'active',
                'email_verified_at' => now(),
            ] + (User::where('email', $email)->exists() ? [] : ['password' => 'password'])); // never reset an existing password

            $profile = StaffProfile::updateOrCreate(['user_id' => $user->id], [
                'position' => $position,
                'bio' => 'Sample staff profile for development.',
                'employee_code' => sprintf('DEMO-%03d', $i + 1),
                'hire_date' => now()->subYears(2)->toDateString(),
                'status' => 'active',
            ]);

            $profile->branches()->syncWithoutDetaching([$branch->id => ['is_primary' => true]]);

            $categoryIds = ServiceCategory::whereIn('slug', $categorySlugs)->pluck('id');
            $profile->services()->syncWithoutDetaching(Service::whereIn('category_id', $categoryIds)->pluck('id'));

            $hours = $branch->businessHours->keyBy('day_of_week');
            foreach ($days as $day) {
                $open = $hours[$day] ?? null;
                if (! $open?->is_open) {
                    continue;
                }
                $profile->schedules()->updateOrCreate(['day_of_week' => $day], [
                    'branch_id' => $branch->id,
                    'is_working' => true,
                    'start_time' => $open->opening_time,
                    'end_time' => $open->closing_time,
                    'break_start' => '12:00',
                    'break_end' => '13:00',
                ]);
            }
        }
    }
}
