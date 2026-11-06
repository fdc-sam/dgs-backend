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

#[Fillable(['category_id', 'name', 'slug', 'short_description', 'description', 'image', 'regular_price', 'deposit_amount', 'gcash_qr', 'loyalty_price', 'return_price', 'first_visit_discount_enabled', 'first_visit_discount_period', 'first_visit_discount_unit', 'duration_minutes', 'buffer_minutes', 'status', 'is_featured', 'sort_order'])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = ['status' => 'active', 'buffer_minutes' => 0, 'is_featured' => false, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'regular_price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            // Loyalty card prices: what a card holder pays, and what they pay after no visit within the return period.
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
     * The price a customer pays for this service with their loyalty card, or null for the regular price. Only an
     * active (not expired) card counts. The return price wins when its rule is on and they have not had this service
     * within the period; otherwise the card holder price. Callers still charge the option or branch price when that is
     * lower.
     */
    public function loyaltyPriceFor(?User $customer): ?float
    {
        if (! $customer?->hasActiveLoyaltyCard()) return null;

        if ((float) $this->return_price > 0 && $this->qualifiesForReturnPrice($customer)) {
            return (float) $this->return_price;
        }

        return (float) $this->loyalty_price > 0 ? (float) $this->loyalty_price : null;
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
