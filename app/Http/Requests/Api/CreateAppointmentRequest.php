<?php

namespace App\Http\Requests\Api;

class CreateAppointmentRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'string', 'size:26', 'exists:branches,id'],
            'staff_id' => ['nullable', 'string', 'size:26', 'exists:staff_profiles,id'],
            'appointment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'services' => ['required', 'array', 'min:1', 'max:5'],
            'services.*.service_id' => ['required', 'string', 'size:26', 'distinct', 'exists:services,id'],
            'services.*.service_variant_id' => ['nullable', 'string', 'size:26'],
            'services.*.staff_id' => ['nullable', 'string', 'size:26', 'exists:staff_profiles,id'],
            'promotion_id' => ['nullable', 'string', 'size:26', 'exists:promotions,id'],
            'customer_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
