<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class DeleteAccountRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            // Not asked of a customer who only signed in with Google (they have no password).
            'password' => [Rule::requiredIf(fn () => $this->user()?->password !== null), 'nullable', 'string'],
        ];
    }
}
