<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Service;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

/** SAMPLE inventory and unit costs — NOT actual DGS costs. Replace via the admin's Inventory page. */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // [name, unit, unit cost, [service slug => units used per service], stock on hand, low stock alert]
        $products = [
            ['Hair Color', 'tube', 180, ['full-hair-color' => 2, 'highlights' => 1], 24, 6],
            ['Developer', 'ml', 0.45, ['full-hair-color' => 120, 'highlights' => 60], 5000, 1000],
            ['Shampoo', 'ml', 0.35, ['haircut' => 15, 'blow-dry-style' => 15, 'hair-spa-treatment' => 20], 4000, 500],
            ['Hair Spa Cream', 'ml', 1.2, ['hair-spa-treatment' => 50], 1500, 300],
            ['Rebonding Cream Set', 'set', 650, ['hair-rebond' => 1], 6, 2],
            ['Keratin Solution', 'ml', 4.5, ['keratin-treatment' => 60], 1000, 240],
            ['Nail Polish', 'bottle', 120, [], 18, 5],
            ['Massage Oil', 'ml', 0.8, ['full-body-massage' => 30], 2000, 300],
        ];

        $services = Service::pluck('id', 'slug');

        foreach ($products as [$name, $unit, $cost, $usage, $stock, $alert]) {
            $product = Product::updateOrCreate(['name' => $name], ['unit' => $unit, 'unit_cost' => $cost, 'reorder_level' => $alert, 'notes' => 'Development sample data.']);
            if (! $product->stockMovements()->exists()) {
                app(InventoryService::class)->countStock($product, $stock, 'Sample opening stock');
            }
            $product->services()->syncWithoutDetaching(collect($usage)
                ->filter(fn ($quantity, $slug) => isset($services[$slug]))
                ->mapWithKeys(fn ($quantity, $slug) => [$services[$slug] => ['quantity' => $quantity]])
                ->all());
        }
    }
}
