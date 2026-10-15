<?php

namespace App\Http\Requests\Admin;

use App\Enums\RecordStatus;
use Illuminate\Validation\Rule;

class BranchRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'alpha_dash', 'max:170', Rule::unique('branches', 'slug')->ignore($this->route('branch'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['required', Rule::enum(RecordStatus::class)],
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.is_open' => ['nullable', 'boolean'],
            'hours.*.opening_time' => ['nullable', 'required_if:hours.*.is_open,1', 'date_format:H:i'],
            'hours.*.closing_time' => ['nullable', 'required_if:hours.*.is_open,1', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            foreach ((array) $this->input('hours', []) as $day => $row) {
                if (! empty($row['is_open']) && ($row['opening_time'] ?? '') >= ($row['closing_time'] ?? '')) {
                    $validator->errors()->add("hours.{$day}.closing_time", 'Closing time must be after opening time.');
                }
            }
        }];
    }
}
