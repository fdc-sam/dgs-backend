<?php

namespace App\Http\Requests\Api;

/** Admin sign-in accepts either a stored email address or its unique username. */
class AdminLoginRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
