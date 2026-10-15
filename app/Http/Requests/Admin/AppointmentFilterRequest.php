<?php

namespace App\Http\Requests\Admin;

use App\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'string'],
            'staff_id' => ['nullable', 'string'],
            'service_id' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'customer' => ['nullable', 'string', 'max:100'],
        ];
    }
}
