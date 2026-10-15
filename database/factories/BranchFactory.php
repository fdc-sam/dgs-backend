<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Sample Branch '.fake()->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => 'Development sample branch.',
            'address' => fake()->address(),
            'phone' => '02'.fake()->numerify('########'),
            'email' => fake()->safeEmail(),
            'latitude' => fake()->latitude(14.4, 14.8),
            'longitude' => fake()->longitude(120.9, 121.1),
            'opening_time' => '09:00:00',
            'closing_time' => '18:00:00',
            'status' => RecordStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => RecordStatus::Inactive]);
    }

    /**
     * Create weekly business hours rows.
     *
     * @param  list<int>  $closedDays  0 = Sunday ... 6 = Saturday
     */
    public function withHours(string $open = '09:00', string $close = '18:00', array $closedDays = []): static
    {
        return $this->afterCreating(function (Branch $branch) use ($open, $close, $closedDays) {
            foreach (range(0, 6) as $day) {
                $isOpen = ! in_array($day, $closedDays, true);
                $branch->businessHours()->create([
                    'day_of_week' => $day,
                    'is_open' => $isOpen,
                    'opening_time' => $isOpen ? $open : null,
                    'closing_time' => $isOpen ? $close : null,
                ]);
            }
        });
    }
}
