<?php

namespace App\Http\Resources;

use App\Models\StaffProfile;
use App\Support\FavoriteLookup;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StaffProfile */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user?->name,
            'position' => $this->position,
            'bio' => $this->bio,
            'photo_url' => Fmt::url($this->user?->profile_photo),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
            ])->values()),
            'service_ids' => $this->whenLoaded('services', fn () => $this->services->pluck('id')->values()),
            ...app(FavoriteLookup::class)->fields($this->resource),
        ];
    }
}
