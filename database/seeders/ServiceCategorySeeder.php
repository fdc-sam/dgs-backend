<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['Haircut & Styling', 'categories/haircut-styling.jpg'],
            ['Hair Color', 'categories/hair-color.jpg'],
            ['Hair Treatment', 'categories/hair-treatment.jpg'],
            ['Rebond & Keratin', 'categories/rebond-keratin.jpg'],
            ['Nail Care', 'categories/nail-care.jpg'],
            ['Spa & Massage', 'categories/spa-massage.jpg'],
        ];

        foreach ($categories as $i => [$name, $image]) {
            ServiceCategory::updateOrCreate(['slug' => Str::slug($name)], [
                'name' => $name,
                'description' => 'Sample category description.',
                'image' => $image,
                'sort_order' => $i,
                'status' => 'active',
            ]);
        }
    }
}
