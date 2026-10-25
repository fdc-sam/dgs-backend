<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Models\Branch;
use App\Models\Promotion;
use App\Support\Booking\ServiceSelection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PromotionService
{
    /** Currently active promotions, optionally restricted to a branch and/or service. */
    public function activeQuery(?string $branchId = null, ?string $serviceId = null): Builder
    {
        return Promotion::query()
            ->currentlyActive()
            ->with(['services', 'branches'])
            ->when($branchId, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereDoesntHave('branches')
                ->orWhereHas('branches', fn (Builder $b) => $b->whereKey($branchId))))
            ->when($serviceId, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereDoesntHave('services')
                ->orWhereHas('services', fn (Builder $s) => $s->whereKey($serviceId))))
            ->orderBy('end_at');
    }

    /**
     * Discount a promotion gives on a booking. Empty service/branch lists mean "applies to all".
     * package / buy_x_get_y are redeemed in-salon, so they are linked but give no automatic discount.
     *
     * @param  Collection<int, ServiceSelection>  $selections
     *
     * @throws ValidationException when the promotion cannot be used for this booking
     */
    public function discountFor(Promotion $promotion, Branch $branch, Collection $selections): float
    {
        $promotion->loadMissing(['services', 'branches']);

        if (! $promotion->isCurrentlyActive()) {
            throw ValidationException::withMessages(['promotion_id' => 'This promotion is not currently active.']);
        }
        if ($promotion->branches->isNotEmpty() && ! $promotion->branches->contains('id', $branch->id)) {
            throw ValidationException::withMessages(['promotion_id' => 'This promotion is not available at the selected branch.']);
        }

        $serviceIds = $promotion->services->pluck('id');
        $eligible = $serviceIds->isEmpty()
            ? $selections
            : $selections->filter(fn (ServiceSelection $s) => $serviceIds->contains($s->service->id));

        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages(['promotion_id' => 'This promotion does not apply to the selected services.']);
        }

        $eligibleTotal = (float) $eligible->sum(fn (ServiceSelection $s) => $s->unitPrice);

        $discount = match ($promotion->discount_type) {
            DiscountType::Percentage => $eligibleTotal * min(100, (float) $promotion->discount_value) / 100,
            DiscountType::Fixed => (float) $eligible->sum(fn (ServiceSelection $s) => max(0, $s->unitPrice - (float) $promotion->discount_value)),
            DiscountType::SpecialPrice => (float) $eligible->sum(fn (ServiceSelection $s) => max(0, $s->unitPrice - (float) $promotion->promo_price)),
            DiscountType::Package, DiscountType::BuyXGetY => 0.0,
        };

        return round(min(max(0, $discount), $eligibleTotal), 2);
    }
}
