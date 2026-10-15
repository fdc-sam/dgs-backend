<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One change to a product's stock. Written only by App\Services\InventoryService. */
#[Fillable(['product_id', 'type', 'quantity', 'balance_after', 'source_type', 'source_id', 'note', 'user_id'])]
class StockMovement extends Model
{
    use HasUlids;

    /** Stock the product had when it was added. */
    public const OPENING = 'opening';

    /** A delivery was received. */
    public const RESTOCK = 'restock';

    /** A completed service used it up. */
    public const USAGE = 'usage';

    /** Usage put back (a completed walk-in's service was changed or removed). */
    public const RETURN = 'return';

    /** Corrected after a physical count. */
    public const ADJUSTMENT = 'adjustment';

    public const LABELS = [
        self::OPENING => 'Opening stock',
        self::RESTOCK => 'Stock added',
        self::USAGE => 'Used in service',
        self::RETURN => 'Returned',
        self::ADJUSTMENT => 'Stock count',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** AppointmentItem or WalkInVisitItem. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? ucfirst($this->type);
    }
}
