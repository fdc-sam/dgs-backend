<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'category_id' => ServiceCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'regular_price' => fake()->randomElement([300, 500, 800, 1200, 2500]),
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'status' => RecordStatus::Active,
            'is_featured' => false,
            'sort_order' => 0,
        ];
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => RecordStatus::Inactive]);
    }

    public function duration(int $minutes, int $buffer = 0): static
    {
        return $this->state(fn () => ['duration_minutes' => $minutes, 'buffer_minutes' => $buffer]);
    }

    public function atBranch(Branch $branch, ?float $customPrice = null): static
    {
        return $this->afterCreating(fn (Service $service) => $service->branches()->attach($branch->id, [
            'custom_price' => $customPrice,
            'is_available' => true,
        ]));
    }
}
