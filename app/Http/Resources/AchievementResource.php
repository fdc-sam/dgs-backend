<?php

namespace App\Http\Resources;

use App\Models\Achievement;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Achievement */
class AchievementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => Fmt::url($this->image),
            'created_at' => Fmt::iso($this->created_at),
        ];
    }
}
