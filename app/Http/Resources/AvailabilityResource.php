<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps an App\Support\Booking\DaySchedule result. */
class AvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $day = $this->resource;

        return [
            'date' => $day->date->toDateString(),
            'branch_id' => $day->branchId,
            'service_id' => $day->serviceId,
            'staff_id' => $day->staffId,
            'duration_minutes' => $day->durationMinutes,
            'is_open' => $day->isOpen,
            'closed_reason' => $day->closedReason,
            'slots' => array_map(fn ($slot) => [
                'start_time' => $slot->start->format('H:i'),
                'end_time' => $slot->end->format('H:i'),
                'available' => $slot->available,
            ], $day->slots),
        ];
    }
}
