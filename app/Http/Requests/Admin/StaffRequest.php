<?php

namespace App\Http\Requests\Admin;

use App\Enums\RecordStatus;
use App\Http\Requests\Api\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffRequest extends AdminRequest
{
    protected function prepareForValidation(): void
    {
        $phone = preg_replace('/[\s\-().]/', '', (string) $this->input('phone'));
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'phone' => $phone === '' ? null : (preg_match('/^\+?639\d{9}$/', $phone) ? '0'.substr($phone, -10) : $phone),
        ]);
    }

    public function rules(): array
    {
        $userId = $this->route('staff')?->user_id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'regex:'.ApiRequest::PHONE_REGEX, Rule::unique('users', 'phone')->ignore($userId)],
            'password' => [$userId ? 'nullable' : 'required', Password::min(8)->letters()->numbers()],
            'position' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'employee_code' => ['nullable', 'string', 'max:64', Rule::unique('staff_profiles', 'employee_code')->ignore($this->route('staff'))],
            'hire_date' => ['nullable', 'date'],
            'status' => ['required', Rule::enum(RecordStatus::class)],
            'photo' => $this->imageRules(),

            'branches' => ['required', 'array', 'min:1'],
            'branches.*' => ['string', 'exists:branches,id'],
            'primary_branch' => ['nullable', 'string', 'in_array:branches.*'],
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'exists:services,id'],

            'schedule' => ['nullable', 'array'],
            'schedule.*.is_working' => ['nullable', 'boolean'],
            'schedule.*.branch_id' => ['nullable', 'required_if:schedule.*.is_working,1', 'string', 'in_array:branches.*'],
            'schedule.*.start_time' => ['nullable', 'required_if:schedule.*.is_working,1', 'date_format:H:i'],
            'schedule.*.end_time' => ['nullable', 'required_if:schedule.*.is_working,1', 'date_format:H:i'],
            'schedule.*.break_start' => ['nullable', 'date_format:H:i'],
            'schedule.*.break_end' => ['nullable', 'date_format:H:i', 'required_with:schedule.*.break_start'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            foreach ((array) $this->input('schedule', []) as $day => $row) {
                if (empty($row['is_working'])) {
                    continue;
                }
                if (($row['start_time'] ?? '') >= ($row['end_time'] ?? '')) {
                    $validator->errors()->add("schedule.{$day}.end_time", 'End time must be after start time.');
                }
                if (! empty($row['break_start']) && (($row['break_start'] < $row['start_time']) || ($row['break_end'] ?? '') > $row['end_time'] || $row['break_start'] >= ($row['break_end'] ?? ''))) {
                    $validator->errors()->add("schedule.{$day}.break_start", 'Break must be within working hours.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'branches.required' => 'Assign the staff member to at least one branch.',
            'schedule.*.branch_id.in_array' => 'Scheduled branch must be one of the assigned branches.',
            'phone.regex' => 'Enter a valid mobile number, e.g. 09171234567.',
        ];
    }
}
