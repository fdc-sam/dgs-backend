<?php

namespace App\Models;

use App\Enums\CashOutStatus;
use App\Models\Concerns\SeparatesTestData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer asking for part (or all) of their in-app earnings to be sent to their GCash. The admin sends it and
 * records the GCash reference number; only then is it taken off the balance. See App\Services\EarningsService.
 */
#[Fillable([
    'customer_id', 'amount', 'gcash_number', 'gcash_name', 'status', 'reference_number', 'admin_note',
    'processed_by', 'processed_at', 'is_test',
])]
class CashOutRequest extends Model
{
    use HasUlids, SeparatesTestData;

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => CashOutStatus::class,
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    /** On the customer's side, like their bookings. */
    protected function testFlagForNewRecord(): bool
    {
        return (bool) User::withTrashed()->whereKey($this->customer_id)->value('is_test');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === CashOutStatus::Pending;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CashOutStatus::Pending->value);
    }
}
