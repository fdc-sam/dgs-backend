<?php

namespace App\Http\Resources;

use App\Enums\DiscountType;
use App\Models\Promotion;
use App\Support\FavoriteLookup;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Promotion */
class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => Fmt::url($this->image),
            'discount_type' => $this->discount_type->value,
            'discount_label' => $this->discountLabel(),
            'discount_value' => Fmt::money($this->discount_value),
            'promo_price' => Fmt::money($this->promo_price),
            'start_at' => Fmt::iso($this->start_at),
            'end_at' => Fmt::iso($this->end_at),
            'terms_and_conditions' => $this->terms_and_conditions,
            'status' => $this->when($request->user()?->role?->canAccessAdmin() ?? false, fn () => $this->status->value),
            'is_active' => $this->isCurrentlyActive(),
            'services' => $this->whenLoaded('services', fn () => $this->services->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'price' => Fmt::money($s->regular_price),
                'image_url' => Fmt::url($s->image),
            ])->values()),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
            ])->values()),
            ...app(FavoriteLookup::class)->fields($this->resource),
        ];
    }

    private function discountLabel(): string
    {
        $value = (float) $this->discount_value;

        return match ($this->discount_type) {
            DiscountType::Percentage => rtrim(rtrim(number_format($value, 2), '0'), '.').'% off',
            DiscountType::Fixed => 'Now '.Fmt::peso($value),
            DiscountType::SpecialPrice => 'Now '.Fmt::peso($this->promo_price),
            DiscountType::Package => $this->promo_price !== null ? 'Package '.Fmt::peso($this->promo_price) : 'Package deal',
            DiscountType::BuyXGetY => 'Bundle offer',
        };
    }
}
