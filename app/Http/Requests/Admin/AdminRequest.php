<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

abstract class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') ?? false;
    }

    protected function imageRules(): array
    {
        return ['nullable', 'file', 'image', 'mimes:'.implode(',', config('salon.uploads.mimes')), 'max:'.config('salon.uploads.max_kilobytes')];
    }
}
