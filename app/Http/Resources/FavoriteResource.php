<?php

namespace App\Http\Resources;

use App\Models\Favorite;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Favorite */
class FavoriteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->favoritable;

        return [
            'id' => $this->id,
            'type' => $this->favoritable_type,
            'item' => match ($this->favoritable_type) {
                'service' => $item ? new ServiceResource($item) : null,
                'staff' => $item ? new StaffResource($item) : null,
                'promotion' => $item ? new PromotionResource($item) : null,
                default => null,
            },
            'created_at' => Fmt::iso($this->created_at),
        ];
    }
}
