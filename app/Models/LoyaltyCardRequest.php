<?php

namespace App\Models;

use App\Enums\LoyaltyCardRequestStatus;
use App\Models\Concerns\SeparatesTestData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's request for a digital loyalty card through a friend who has one (the endorser): the customer pays
 * gcash_amount to the salon's GCash (receipt attached) and endorser_cash in cash to the endorser, which is what the
 * endorser earns once the admin approves. See App\Services\LoyaltyCardRequestService.
 */
#[Fillable([
    'customer_id', 'endorser_id', 'loyalty_card_id', 'gcash_amount', 'endorser_cash', 'reference_number', 'proof_path',
    'status', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'is_test',
])]
class LoyaltyCardRequest extends Model
{
    use HasUlids, SeparatesTestData;

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => LoyaltyCardRequestStatus::class,
            'gcash_amount' => 'decimal:2',
            'endorser_cash' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    /** On the requesting customer's side, like their bookings. */
    protected function testFlagForNewRecord(): bool
    {
        return (bool) User::withTrashed()->whereKey($this->customer_id)->value('is_test');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function endorser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorser_id')->withTrashed();
    }

    public function loyaltyCard(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === LoyaltyCardRequestStatus::Pending;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', LoyaltyCardRequestStatus::Pending->value);
    }

    /** "Maria Santos" → "Maria S.": enough for a friend to recognise, without the full name behind a scanned code. */
    public static function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.' : '';

        return trim($first.' '.$last);
    }
}
