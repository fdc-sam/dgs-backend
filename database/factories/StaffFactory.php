<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for StaffProfile (bound via #[UseFactory]). Creates the linked staff user too.
 *
 * @extends Factory<StaffProfile>
 */
class StaffFactory extends Factory
{
    protected $model = StaffProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->staff(),
            'position' => fake()->randomElement(['Senior Stylist', 'Stylist', 'Colorist', 'Nail Technician', 'Spa Therapist']),
            'bio' => fake()->sentence(12),
            'employee_code' => 'EMP-'.Str::upper(Str::random(6)),
            'hire_date' => fake()->dateTimeBetween('-5 years', '-1 month'),
            'status' => RecordStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => RecordStatus::Inactive]);
    }

    /**
     * Assign to a branch with a weekly schedule.
     *
     * @param  list<int>  $days  Working weekdays, 0 = Sunday ... 6 = Saturday
     */
    public function worksAt(
        Branch $branch,
        string $start = '09:00',
        string $end = '18:00',
        ?string $breakStart = '12:00',
        ?string $breakEnd = '13:00',
        array $days = [0, 1, 2, 3, 4, 5, 6],
    ): static {
        return $this->afterCreating(function (StaffProfile $staff) use ($branch, $start, $end, $breakStart, $breakEnd, $days) {
            $staff->branches()->syncWithoutDetaching([$branch->id => ['is_primary' => $staff->branches()->doesntExist()]]);
            foreach ($days as $day) {
                $staff->schedules()->updateOrCreate(['day_of_week' => $day], [
                    'branch_id' => $branch->id,
                    'is_working' => true,
                    'start_time' => $start,
                    'end_time' => $end,
                    'break_start' => $breakStart,
                    'break_end' => $breakEnd,
                ]);
            }
        });
    }

    /** @param  iterable<Service>|Service  $services */
    public function offers(iterable|Service $services): static
    {
        $ids = collect($services instanceof Service ? [$services] : $services)->pluck('id')->all();

        return $this->afterCreating(fn (StaffProfile $staff) => $staff->services()->syncWithoutDetaching($ids));
    }
}
