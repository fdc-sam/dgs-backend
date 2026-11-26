<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            // Not asked when setting the first password of a customer who only signed in with Google.
            'current_password' => [Rule::requiredIf(fn () => $this->user()?->password !== null), 'nullable', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
            'device_token' => ['nullable', 'string', 'max:512'],
        ];
    }
}
