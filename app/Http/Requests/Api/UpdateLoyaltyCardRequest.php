<?php

namespace App\Http\Requests\Api;

use App\Support\LoyaltyNumber;

class UpdateLoyaltyCardRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['loyalty_number' => LoyaltyNumber::normalize($this->input('loyalty_number'))]);
    }

    public function rules(): array
    {
        return ['loyalty_number' => LoyaltyNumber::rules($this->user())];
    }

    public function messages(): array
    {
        return LoyaltyNumber::messages();
    }
}
