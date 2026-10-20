<?php

namespace App\Http\Requests\Api;

class DeleteAccountRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }
}
