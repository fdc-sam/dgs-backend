<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromotionStatus;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'description', 'image', 'discount_type', 'discount_value', 'promo_price', 'start_at', 'end_at', 'terms_and_conditions', 'status'])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'status' => PromotionStatus::class,
            'discount_value' => 'decimal:2',
            'promo_price' => 'decimal:2',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    /**
     * The services it is for; none = every service. Deleted services stay in the list, so a promotion made for a
     * service that was deleted later applies to nothing, instead of looking like "every service".
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'promotion_service')->withTrashed();
    }

    /** The branches it is for; none = every branch. Deleted branches stay in the list, as for [services]. */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'promotion_branch')->withTrashed();
    }

    /** A Fixed Price or Special Price promotion sets the price of particular services, so it needs some picked. */
    public function setsServicePrices(): bool
    {
        return in_array($this->discount_type, [DiscountType::Fixed, DiscountType::SpecialPrice], true);
    }

    /**
     * It can't be used: every service (or branch) it was for has been deleted, or it sets a price but no services
     * are picked (which would put that price on every service).
     */
    public function appliesToNothing(): bool
    {
        return ($this->services->isNotEmpty() && $this->services->every->trashed())
            || ($this->branches->isNotEmpty() && $this->branches->every->trashed())
            || ($this->setsServicePrices() && $this->services->isEmpty());
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function favorites(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }

    /** Published and within its validity window right now. */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('status', PromotionStatus::Active->value)
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now);
    }

    public function isCurrentlyActive(): bool
    {
        return $this->status === PromotionStatus::Active
            && $this->start_at->lte(now())
            && $this->end_at->gte(now());
    }
}
