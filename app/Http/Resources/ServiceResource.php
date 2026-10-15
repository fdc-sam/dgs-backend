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
            'price' => $price,
            'price_from' => Fmt::money($priceFrom),
            // GCash downpayment asked when booking this service (its own fixed-amount QR); null = none.
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
            ])->values()),
            'staff' => $this->whenLoaded('staff', fn () => $this->staff->map(fn ($staff) => [
                'id' => $staff->id,
                'name' => $staff->user?->name,
                'position' => $staff->position,
                'photo_url' => Fmt::url($staff->user?->profile_photo),
            ])->values()),
        ];
    }
}
