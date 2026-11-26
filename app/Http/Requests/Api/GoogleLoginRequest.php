<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class GoogleLoginRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => $this->normalizePhone($this->input('phone'))]);
    }

    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:4096'],
            // Only for a new customer (the first try without it answers needs_phone).
            'phone' => ['nullable', 'string', 'regex:'.self::PHONE_REGEX, Rule::unique('users', 'phone')],
            'device_name' => ['nullable', 'string', 'max:120'],
            'invite_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid mobile number, e.g. 09171234567.'];
    }
}
