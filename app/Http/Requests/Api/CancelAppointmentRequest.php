<?php

namespace App\Http\Requests\Api;

class CancelAppointmentRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
