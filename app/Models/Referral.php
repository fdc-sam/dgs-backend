<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use App\Models\Concerns\SeparatesTestData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Someone a customer referred to the salon; the referrer earns a reward, added to their in-app earnings, once that
 * person completes a service. Rewards from before in-app earnings were sent straight to GCash (reward_paid_directly).
 */
#[Fillable(['referrer_id', 'name', 'phone', 'notes', 'status', 'admin_note', 'reward_amount', 'reward_reference', 'reward_paid_directly', 'rewarded_at'])]
class Referral extends Model
{
    use HasUlids, SeparatesTestData;

    protected $attributes = ['status' => 'pending', 'reward_paid_directly' => false];

    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'reward_amount' => 'decimal:2',
            'reward_paid_directly' => 'boolean',
            'rewarded_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    /** On the referrer's side, like their bookings. */
    protected function testFlagForNewRecord(): bool
    {
        return (bool) User::withTrashed()->whereKey($this->referrer_id)->value('is_test');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id')->withTrashed();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withTrashed()->orderBy('name');
    }
}
