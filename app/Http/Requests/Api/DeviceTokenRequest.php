<?php

namespace App\Http\Requests\Api;

use App\Enums\DevicePlatform;
use Illuminate\Validation\Rule;

class DeviceTokenRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
