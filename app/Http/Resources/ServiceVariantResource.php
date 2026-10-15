<?php

namespace App\Http\Resources;

use App\Models\ServiceVariant;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceVariant */
class ServiceVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => Fmt::money($this->price),
            'duration_minutes' => $this->duration_minutes ?? $this->service?->duration_minutes,
        ];
    }
}
