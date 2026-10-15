<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Enums\PromotionStatus;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Sample Promo '.ucfirst(fake()->unique()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(),
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'promo_price' => null,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'terms_and_conditions' => 'Sample terms. Not an official DGS promotion.',
            'status' => PromotionStatus::Active,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => PromotionStatus::Draft]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['start_at' => now()->subMonths(2), 'end_at' => now()->subMonth()]);
    }

    public function upcoming(): static
    {
        return $this->state(fn () => ['start_at' => now()->addWeek(), 'end_at' => now()->addMonth()]);
    }

    public function fixed(float $amount): static
    {
        return $this->state(fn () => ['discount_type' => DiscountType::Fixed, 'discount_value' => $amount]);
    }

    public function specialPrice(float $price): static
    {
        return $this->state(fn () => ['discount_type' => DiscountType::SpecialPrice, 'discount_value' => null, 'promo_price' => $price]);
    }
}
