<?php

namespace App\Http\Requests\Api;

class RescheduleAppointmentRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'appointment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'staff_id' => ['nullable', 'string', 'size:26', 'exists:staff_profiles,id'],
        ];
    }
}
