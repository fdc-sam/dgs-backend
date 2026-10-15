<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An inventory item the salon buys and uses up while performing services. */
#[Fillable(['name', 'brand', 'unit', 'unit_cost', 'stock_quantity', 'reorder_level', 'notes'])]
class Product extends Model
{
    use HasUlids, SoftDeletes;

    /** Units offered in the admin's Unit dropdown (value => label). */
    public const UNITS = [
        'pc' => 'pc (piece)', 'ml' => 'ml (milliliter)', 'g' => 'g (gram)', 'tube' => 'tube', 'bottle' => 'bottle',
        'sachet' => 'sachet', 'pack' => 'pack', 'set' => 'set', 'pair' => 'pair',
    ];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2', 'stock_quantity' => 'decimal:2', 'reorder_level' => 'decimal:2'];
    }

    /** Every stock change, newest first. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('created_at')->latest('id');
    }

    /** `out` (nothing left), `low` (at or below the alert level) or `ok`. */
    public function stockStatus(): string
    {
        return match (true) {
            (float) $this->stock_quantity <= 0 => 'out',
            $this->reorder_level !== null && (float) $this->stock_quantity <= (float) $this->reorder_level => 'low',
            default => 'ok',
        };
    }

    /** Out of stock, or at/below its alert level. */
    public function scopeNeedsRestock(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('stock_quantity', '<=', 0)
            ->orWhere(fn ($q) => $q->whereNotNull('reorder_level')->whereColumn('stock_quantity', '<=', 'reorder_level')));
    }

    /** Services that use this product; `quantity` is how many units one service uses. */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withPivot('quantity')->withTimestamps();
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name')->orderBy('brand');
    }

    /** "Hair color (L'Oréal)" */
    public function label(): string
    {
        return $this->brand ? "{$this->name} ({$this->brand})" : $this->name;
    }
}
