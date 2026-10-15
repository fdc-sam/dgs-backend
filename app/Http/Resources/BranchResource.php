<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function toArray(Request $request): array
    {
        $hours = $this->businessHours->keyBy('day_of_week');

        $week = collect(range(0, 6))->map(function (int $day) use ($hours) {
            $row = $hours->get($day);
            $open = $row ? $row->is_open : ($this->opening_time !== null && $this->closing_time !== null);

            return [
                'day_of_week' => $day,
                'day_name' => self::DAYS[$day],
                'is_open' => $open,
                'opening_time' => $open ? Fmt::time($row?->opening_time ?? $this->opening_time) : null,
                'closing_time' => $open ? Fmt::time($row?->closing_time ?? $this->closing_time) : null,
            ];
        });

        $now = now();
        $today = $week[$now->dayOfWeek];
        $current = $now->format('H:i');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'image_url' => null,
            'status' => $this->when($request->user()?->role?->canAccessAdmin() ?? false, fn () => $this->status->value),
            'is_open_now' => $today['is_open'] && $current >= $today['opening_time'] && $current < $today['closing_time'],
            'business_hours' => $week->values(),
        ];
    }
}
