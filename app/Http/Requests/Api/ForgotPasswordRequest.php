<?php

namespace App\Http\Requests\Api;

class ForgotPasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}
