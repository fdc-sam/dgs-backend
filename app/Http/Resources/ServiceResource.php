<?php

namespace App\Http\Resources;

use App\Models\Service;
use App\Support\FavoriteLookup;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $branchPrice = Fmt::attr($this->resource, 'branch_price');
        $price = Fmt::money($branchPrice ?? $this->regular_price);
        $variants = $this->relationLoaded('variants') ? $this->variants : null;
        $priceFrom = $variants && $variants->isNotEmpty() ? min($price, (float) $variants->min('price')) : $price;
        $average = Fmt::attr($this->resource, 'approved_reviews_avg_rating');
        // How this customer's loyalty card prices the service (Services → Loyalty card prices), or null: no active card or
        // no loyalty price. $discountedPrice = the "from" price with it.
        $loyaltyRule = $this->resource->loyaltyRuleFor($request->user());
        $discountedPrice = $loyaltyRule === null ? null : Service::applyLoyaltyRule($loyaltyRule, $priceFrom);

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'image_url' => Fmt::url($this->image),
            'regular_price' => Fmt::money($this->regular_price),
            // The service's loyalty card pricing as set in the admin: "less" (an amount or percent off) or "tiered"
            // (maintenance price, and the lapsed price after no visit within the period). null = not used. While the
            // admin has it switched off (loyalty_enabled false) the prices are null: card holders pay the regular price.
            'loyalty_enabled' => $on = (bool) $this->loyalty_enabled,
            'loyalty_pricing' => $this->loyalty_pricing,
            'loyalty_less' => $on && (float) $this->loyalty_discount > 0 ? Fmt::money($this->loyalty_discount) : null,
            'loyalty_less_type' => $this->loyalty_discount_type,
            'card_holder_price' => $on ? Fmt::money($this->loyalty_price) : null,
            'return_price' => $on ? Fmt::money($this->return_price) : null,
            'return_price_enabled' => $on && $this->first_visit_discount_enabled,
            'return_price_period' => (int) $this->first_visit_discount_period,
            'return_price_period_unit' => $this->first_visit_discount_unit,
            'price' => $price,
            'price_from' => Fmt::money($priceFrom),
            'loyalty_discounted_price' => $discountedPrice === null ? null : Fmt::money($discountedPrice),
            // This customer's loyalty rule ({type: price|amount|percent, value}), so the app can price any option and branch.
            'loyalty_rule' => $loyaltyRule,
            // For older app versions, which take a flat amount off: the saving on the regular (or branch) price.
            'loyalty_discount_amount' => Fmt::money($loyaltyRule === null ? 0 : $price - (Service::applyLoyaltyRule($loyaltyRule, $price) ?? $price)),
            'deposit_amount' => Fmt::money($this->resource->ownDeposit()),
            'duration_minutes' => $this->duration_minutes,
            'buffer_minutes' => $this->buffer_minutes,
            'is_featured' => $this->is_featured,
            'status' => $this->when($request->user()?->role?->canAccessAdmin() ?? false, fn () => $this->status->value),
            'rating_average' => $average === null ? null : round((float) $average, 1),
            'reviews_count' => (int) Fmt::attr($this->resource, 'approved_reviews_count', 0),
            ...app(FavoriteLookup::class)->fields($this->resource),
            'variants' => ServiceVariantResource::collection($this->whenLoaded('variants')),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'price' => Fmt::money($branch->pivot->custom_price ?? $this->regular_price),
                'loyalty_discounted_price' => $this->loyaltyPriceAt((float) ($branch->pivot->custom_price ?? $this->regular_price), $loyaltyRule),
            ])->values()),
            'staff' => $this->whenLoaded('staff', fn () => $this->staff->map(fn ($staff) => [
                'id' => $staff->id,
                'name' => $staff->user?->name,
                'position' => $staff->position,
                'photo_url' => Fmt::url($staff->user?->profile_photo),
            ])->values()),
        ];
    }

    /** The loyalty price to show at a branch: the cheapest way to book the service there, with the customer's rule. */
    private function loyaltyPriceAt(float $branchPrice, ?array $rule): ?float
    {
        $from = min($branchPrice, (float) ($this->variants->min('price') ?? $branchPrice));

        return $rule === null ? null : Service::applyLoyaltyRule($rule, $from);
    }
}
