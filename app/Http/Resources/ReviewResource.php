<?php

namespace App\Http\Resources;

use App\Models\Review;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin Review */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user() ?? auth('sanctum')->user();
        $isOwner = $viewer && $viewer->id === $this->customer_id;

        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'service_id' => $this->service_id,
            'service_name' => $this->service?->name ?? $this->appointment?->items->first()?->service_name,
            'rating' => $this->rating,
            'review' => $this->review,
            'status' => $this->status->value,
            'customer' => [
                'id' => $this->customer_id,
                'name' => $isOwner ? $this->customer?->name : $this->publicName(),
                'photo_url' => Fmt::url($this->customer?->profile_photo),
            ],
            'images' => $this->images->map(fn ($image) => $image->url())->values(),
            'created_at' => Fmt::iso($this->created_at),
        ];
    }

    /** "Juan Dela Cruz" → "Juan D." */
    private function publicName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->customer?->name)) ?: [];
        $first = $parts[0] ?? 'Customer';
        $last = count($parts) > 1 ? ' '.Str::upper(Str::substr(end($parts), 0, 1)).'.' : '';

        return $first.$last;
    }
}
