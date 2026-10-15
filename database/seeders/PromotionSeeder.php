<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Promotion;
use App\Models\Service;
use Illuminate\Database\Seeder;

/** SAMPLE promotions — NOT actual DGS promotions or promo prices. */
class PromotionSeeder extends Seeder
{
    public function run(): void
    {
        $promos = [
            ['Sample Promo: 20% Off Hair Color', 'sample-20-off-hair-color', 'percentage', 20, null, ['full-hair-color', 'highlights'], 'promotions/sample-20-off-hair-color.jpg'],
            ['Sample Promo: Rebond Special Price', 'sample-rebond-special', 'special_price', null, 1999, ['hair-rebond'], 'promotions/sample-rebond-special.jpg'],
            ['Sample Promo: ₱100 Off Spa', 'sample-100-off-spa', 'fixed', 100, null, ['hair-spa-treatment', 'full-body-massage'], 'promotions/sample-100-off-spa.jpg'],
        ];

        $branchIds = Branch::pluck('id');

        foreach ($promos as [$name, $slug, $type, $value, $price, $serviceSlugs, $image]) {
            $promo = Promotion::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => 'Development sample promotion. Not an official DGS offer.',
                'image' => $image,
                'discount_type' => $type,
                'discount_value' => $value,
                'promo_price' => $price,
                'start_at' => now()->startOfDay(),
                'end_at' => now()->addMonths(2)->endOfDay(),
                'terms_and_conditions' => 'Sample terms and conditions for development only.',
                'status' => 'active',
            ]);
            $promo->services()->sync(Service::whereIn('slug', $serviceSlugs)->pluck('id'));
            $promo->branches()->sync($branchIds);
        }
    }
}
