<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\ReviewStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['category_id', 'name', 'slug', 'short_description', 'description', 'image', 'regular_price', 'deposit_amount', 'gcash_qr', 'loyalty_enabled', 'loyalty_pricing', 'loyalty_discount', 'loyalty_discount_type', 'loyalty_price', 'return_price', 'first_visit_discount_enabled', 'first_visit_discount_period', 'first_visit_discount_unit', 'duration_minutes', 'buffer_minutes', 'status', 'is_featured', 'sort_order'])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = ['status' => 'active', 'buffer_minutes' => 0, 'is_featured' => false, 'sort_order' => 0, 'loyalty_enabled' => false];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'regular_price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            // Loyalty card pricing, used only while loyalty_enabled (the card's on/off switch) is on. loyalty_pricing "less" or "tiered": Less = loyalty_discount off, in pesos or percent
            // (loyalty_discount_type); Tiered = the maintenance price (loyalty_price) and the lapsed price (return_price).
            'loyalty_enabled' => 'boolean',
            'loyalty_discount' => 'decimal:2',
            'loyalty_price' => 'decimal:2',
            'return_price' => 'decimal:2',
            'first_visit_discount_enabled' => 'boolean',
            'first_visit_discount_period' => 'integer',
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The downpayment encoded in this service's own GCash QR, or null when it has none. */
    public function ownDeposit(): ?float
    {
        return $this->gcash_qr && (float) $this->deposit_amount > 0 ? (float) $this->deposit_amount : null;
    }

    /**
     * How this customer's loyalty card prices this service, or null when it does not (no active card, loyalty prices
     * switched off for the service, or none set): ['type' => 'price', 'value' => 500] (tiered: the lapsed price when its rule applies, else the
     * maintenance price), ['type' => 'amount', 'value' => 200] (Less ₱200) or ['type' => 'percent', 'value' => 50].
     * The app gets this too, to price whichever option and branch the customer picks.
     *
     * @return array{type: 'price'|'amount'|'percent', value: float}|null
     */
    public function loyaltyRuleFor(?User $customer): ?array
    {
        if (! $this->loyalty_enabled || ! $customer?->hasActiveLoyaltyCard()) return null;

        if ($this->loyalty_pricing === 'tiered') {
            if ((float) $this->return_price > 0 && $this->qualifiesForReturnPrice($customer)) {
                return ['type' => 'price', 'value' => (float) $this->return_price];
            }

            return (float) $this->loyalty_price > 0 ? ['type' => 'price', 'value' => (float) $this->loyalty_price] : null;
        }

        $value = (float) $this->loyalty_discount;
        if ($value <= 0) return null;

        return $this->loyalty_discount_type === 'percent'
            ? ['type' => 'percent', 'value' => min($value, 100.0)]
            : ['type' => 'amount', 'value' => $value];
    }

    /** What this customer pays at $basePrice (an option or branch price) with their loyalty card, or null to pay $basePrice. */
    public function loyaltyPriceFor(?User $customer, float $basePrice): ?float
    {
        $rule = $this->loyaltyRuleFor($customer);

        return $rule === null ? null : self::applyLoyaltyRule($rule, $basePrice);
    }

    /**
     * $basePrice under a loyalty rule (see loyaltyRuleFor), or null when that is not lower: a tiered price never makes
     * a cheaper option dearer.
     *
     * @param  array{type: string, value: float}  $rule
     */
    public static function applyLoyaltyRule(array $rule, float $basePrice): ?float
    {
        $price = round(match ($rule['type']) {
            'price' => min($basePrice, $rule['value']),
            'percent' => $basePrice * (1 - $rule['value'] / 100),
            default => max(0, $basePrice - $rule['value']),
        }, 2);

        return $price < $basePrice ? $price : null;
    }

    /** The return price applies when the customer's last completed visit for this service, or signup if none, is outside the period. */
    public function qualifiesForReturnPrice(User $customer): bool
    {
        $period = (int) $this->first_visit_discount_period;
        if (! $this->first_visit_discount_enabled || $period < 1) return false;

        $cutoff = $this->first_visit_discount_unit === 'months'
            ? CarbonImmutable::now()->subMonthsNoOverflow($period)
            : CarbonImmutable::now()->subDays($period);

        $lastVisit = $this->appointmentItems()
            ->whereHas('appointment', fn ($query) => $query
                ->where('customer_id', $customer->id)
                ->where('status', 'completed'))
            ->with('appointment:id,completed_at,appointment_date')
            ->get()
            ->map(fn (AppointmentItem $item) => $item->appointment?->completed_at ?? $item->appointment?->appointment_date)
            ->filter()
            ->max();

        $periodStart = $lastVisit
            ? CarbonImmutable::parse($lastVisit)
            : CarbonImmutable::parse($customer->created_at);

        return $periodStart->lessThanOrEqualTo($cutoff);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ServiceVariant::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)
            ->withPivot(['custom_price', 'is_available']);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(StaffProfile::class, 'service_staff')->withTimestamps();
    }

    /** Inventory used each time this service is performed; `quantity` is in the product's own unit. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('quantity')->withTimestamps();
    }

    /** Cost of the products one service uses up, at current unit costs. */
    public function productCost(): float
    {
        return round($this->products->sum(fn (Product $product) => (float) $product->pivot->quantity * (float) $product->unit_cost), 2);
    }

    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'promotion_service');
    }

    public function appointmentItems(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->reviews()->where('status', ReviewStatus::Approved->value);
    }

    public function favorites(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Services offered (and currently available) at the given branch. */
    public function scopeAvailableAtBranch(Builder $query, string $branchId): Builder
    {
        return $query->whereHas('branches', fn (Builder $q) => $q
            ->where('branches.id', $branchId)
            ->where('branch_service.is_available', true));
    }

    public function isActive(): bool
    {
        return $this->status === RecordStatus::Active;
    }
}
