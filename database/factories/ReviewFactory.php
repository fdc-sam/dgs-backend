<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\Appointment;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory()->completed(),
            'customer_id' => fn (array $attributes) => Appointment::find($attributes['appointment_id'])->customer_id,
            'service_id' => null,
            'rating' => fake()->numberBetween(3, 5),
            'review' => fake()->sentence(15),
            'status' => ReviewStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => ReviewStatus::Approved]);
    }
}
