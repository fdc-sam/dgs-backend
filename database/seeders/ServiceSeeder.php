<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** SAMPLE services and prices — NOT actual DGS prices. Replace via the admin. */
class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        // [category slug, name, price, duration, buffer, featured, variants [name, price, duration]]
        $services = [
            ['haircut-styling', 'Haircut', 350, 45, 15, true, []],
            ['haircut-styling', 'Blow Dry & Style', 400, 45, 15, false, []],
            ['hair-color', 'Full Hair Color', 1500, 120, 15, true, [['Short Hair', 1500, 90], ['Medium Hair', 1900, 120], ['Long Hair', 2400, 150]]],
            ['hair-color', 'Highlights', 2000, 150, 15, false, []],
            ['hair-treatment', 'Hair Spa Treatment', 800, 60, 15, true, []],
            ['rebond-keratin', 'Hair Rebond', 2500, 180, 30, true, [['Short Hair', 2500, 150], ['Medium Hair', 3200, 180], ['Long Hair', 4000, 240]]],
            ['rebond-keratin', 'Keratin Treatment', 3000, 150, 30, false, []],
            ['nail-care', 'Manicure', 250, 45, 10, false, []],
            ['nail-care', 'Pedicure', 300, 60, 10, false, []],
            ['spa-massage', 'Full Body Massage', 900, 60, 15, true, [['60 Minutes', 900, 60], ['90 Minutes', 1300, 90]]],
        ];

        $branchIds = Branch::pluck('id');

        foreach ($services as $i => [$categorySlug, $name, $price, $duration, $buffer, $featured, $variants]) {
            $service = Service::updateOrCreate(['slug' => Str::slug($name)], [
                'category_id' => ServiceCategory::where('slug', $categorySlug)->value('id'),
                'name' => $name,
                'short_description' => "Sample description for {$name}.",
                'description' => "Development sample data for {$name}. Prices and durations shown are placeholders, not official DGS pricing.",
                'image' => 'services/'.Str::slug($name).'.jpg',
                'regular_price' => $price,
                'duration_minutes' => $duration,
                'buffer_minutes' => $buffer,
                'status' => 'active',
                'is_featured' => $featured,
                'sort_order' => $i,
            ]);

            foreach ($variants as [$variantName, $variantPrice, $variantDuration]) {
                $service->variants()->updateOrCreate(['name' => $variantName], [
                    'price' => $variantPrice,
                    'duration_minutes' => $variantDuration,
                    'status' => 'active',
                ]);
            }

            $service->branches()->syncWithoutDetaching(
                $branchIds->mapWithKeys(fn ($id) => [$id => ['is_available' => true]])->all()
            );
        }
    }
}
