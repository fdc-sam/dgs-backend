<?php

namespace App\Http\Resources;

use App\Models\AppointmentItem;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppointmentItem */
class AppointmentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'service_variant_id' => $this->service_variant_id,
            'staff_id' => $this->staff_profile_id,
            'staff_name' => $this->staff?->user?->name,
            'service_name' => $this->service_name,
            'variant_name' => $this->variant_name,
            'quantity' => $this->quantity,
            'unit_price' => Fmt::money($this->unit_price),
            'duration_minutes' => $this->duration_minutes,
            'subtotal' => Fmt::money($this->subtotal),
            'deposit_amount' => Fmt::money($this->deposit_amount),
        ];
    }
}
