<?php

namespace App\Services;

use App\Models\AppointmentItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\WalkInVisitItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Inventory products, their stock and the cost of what each service uses up. Net revenue on the dashboard is the
 * amount charged minus the product cost recorded on each item when its service was completed.
 *
 * Stock: completing a service takes what it uses off each product (once per appointment/walk-in item); deliveries
 * and physical counts add or correct it. Every change is a row in stock_movements, written while the product row
 * is locked so concurrent completions never lose an update. Stock may go negative: a service is never blocked
 * because the count was off, it just shows as out of stock until someone recounts.
 */
class InventoryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function saveProduct(?Product $product, array $data): Product
    {
        $product ??= new Product;
        $original = $product->getAttributes();

        $creating = ! $product->exists;

        return DB::transaction(function () use ($product, $data, $original, $creating) {
            $product->fill([
                'name' => $data['name'],
                'brand' => $data['brand'] ?? null,
                'unit' => $data['unit'],
                'unit_cost' => $data['unit_cost'],
                'reorder_level' => $this->nullableQuantity($data['reorder_level'] ?? null),
                'notes' => $data['notes'] ?? null,
            ])->save();
            $this->audit->changes($creating ? 'product.created' : 'product.updated', $product, $original);

            // Stock is only set directly when the product is added; afterwards use restock() / countStock().
            $opening = $creating ? (float) ($data['stock_quantity'] ?? 0) : 0;
            if ($opening > 0) {
                $this->move($product, $opening, StockMovement::OPENING);
            }

            return $product;
        });
    }

    public function deleteProduct(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $this->audit->log('product.deleted', $product, ['name' => $product->name, 'services' => $product->services()->pluck('name')->all()]);
            // Services stop counting it from now on; completed visits keep the cost already recorded.
            $product->services()->detach();
            $product->delete();
        });
    }

    /**
     * A service was completed: record what each item's service used at today's unit costs, and take it off stock.
     * Safe to call again for the same item: stock is only taken once until the usage is released.
     * Test bookings and walk-ins record the cost but leave the (shared, real) stock alone.
     *
     * @param  Collection<int, AppointmentItem|WalkInVisitItem>  $items
     */
    public function recordUsage(Collection $items): void
    {
        $items->loadMissing('service.products');
        foreach ($items as $item) {
            $item->update(['product_cost' => round(($item->service?->productCost() ?? 0) * $item->quantity, 2)]);

            if ($this->usedBy($item)->isNotEmpty() || $this->isTest($item)) {
                continue; // already taken off stock, or a test visit
            }
            foreach ($item->service?->products ?? [] as $product) {
                $used = round((float) $product->pivot->quantity * $item->quantity, 2);
                if ($used > 0) {
                    $this->move($product, -$used, StockMovement::USAGE, $item);
                }
            }
        }
    }

    /** Put back what a completed item used, e.g. its service was changed or it was removed from the visit. */
    public function releaseUsage(AppointmentItem|WalkInVisitItem $item): void
    {
        foreach ($this->usedBy($item) as $productId => $used) {
            if ($product = Product::withTrashed()->find($productId)) {
                $this->move($product, $used, StockMovement::RETURN, $item);
            }
        }
    }

    /** A delivery arrived. */
    public function restock(Product $product, float $quantity, ?string $note = null): StockMovement
    {
        return $this->move($product, round($quantity, 2), StockMovement::RESTOCK, null, $note);
    }

    /** Set stock to what was physically counted. Returns null when the count already matches. */
    public function countStock(Product $product, float $counted, ?string $note = null): ?StockMovement
    {
        return DB::transaction(function () use ($product, $counted, $note) {
            $current = (float) Product::withTrashed()->lockForUpdate()->findOrFail($product->id)->stock_quantity;
            $difference = round($counted - $current, 2);

            return $difference == 0 ? null : $this->move($product, $difference, StockMovement::ADJUSTMENT, null, $note);
        });
    }

    /** Change stock by $change (signed) and log it. */
    private function move(Product $product, float $change, string $type, ?Model $source = null, ?string $note = null): StockMovement
    {
        return DB::transaction(function () use ($product, $change, $type, $source, $note) {
            $locked = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
            $balance = round((float) $locked->stock_quantity + $change, 2);
            $locked->update(['stock_quantity' => $balance]);
            // Keep the caller's copy current without marking it as changed.
            $product->forceFill(['stock_quantity' => $locked->stock_quantity])->syncOriginalAttribute('stock_quantity');

            return $locked->stockMovements()->create([
                'type' => $type,
                'quantity' => $change,
                'balance_after' => $balance,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'note' => $note,
                'user_id' => auth()->id(),
            ]);
        });
    }

    /**
     * Units each product is still counted as used by this item (usage minus anything returned).
     *
     * @return \Illuminate\Support\Collection<string, float> product id => units
     */
    private function isTest(AppointmentItem|WalkInVisitItem $item): bool
    {
        $parent = $item instanceof AppointmentItem ? $item->appointment()->withoutGlobalScopes()->first() : $item->visit()->withoutGlobalScopes()->first();

        return (bool) $parent?->is_test;
    }

    private function usedBy(Model $item): \Illuminate\Support\Collection
    {
        return StockMovement::query()
            ->where('source_type', $item->getMorphClass())->where('source_id', $item->getKey())
            ->groupBy('product_id')->selectRaw('product_id, sum(quantity) as net')
            ->pluck('net', 'product_id')
            ->map(fn ($net) => round(-(float) $net, 2))
            ->filter(fn (float $used) => $used > 0);
    }

    private function nullableQuantity(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }
}
