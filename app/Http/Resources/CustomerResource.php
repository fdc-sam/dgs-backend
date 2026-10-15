<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Customer with booking statistics (admin/reporting use). @mixin \App\Models\User */
class CustomerResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'appointments_count' => $this->whenCounted('appointments'),
            'completed_appointments_count' => $this->whenCounted('completedAppointments'),
        ];
    }
}
