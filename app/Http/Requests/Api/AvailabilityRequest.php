<?php

namespace App\Http\Requests\Api;

class AvailabilityRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'string', 'size:26'],
            'service_id' => ['required', 'string', 'size:26'],
            'service_variant_id' => ['nullable', 'string', 'size:26'],
            'staff_id' => ['nullable', 'string', 'size:26'],
            'date' => [$this->routeIs('*.dates') ? 'nullable' : 'required', 'date_format:Y-m-d'],
            'month' => [$this->routeIs('*.dates') ? 'required' : 'nullable', 'date_format:Y-m'],
            'exclude_appointment_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
