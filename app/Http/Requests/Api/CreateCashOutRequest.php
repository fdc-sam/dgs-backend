<?php

namespace App\Http\Requests\Api;

/** The customer asks for part or all of their earnings, sent to a GCash number. EarningsService checks the balance. */
class CreateCashOutRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'gcash_number' => $this->normalizePhone($this->input('gcash_number')),
            'gcash_name' => is_string($this->input('gcash_name')) ? trim($this->input('gcash_name')) : $this->input('gcash_name'),
        ]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000', 'decimal:0,2'],
            'gcash_number' => ['required', 'string', 'regex:'.self::PHONE_REGEX],
            'gcash_name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Enter how much to cash out.',
            'amount.min' => 'Enter at least ₱1.',
            'amount.decimal' => 'Enter the amount in pesos and centavos, e.g. 150.50.',
            'gcash_number.required' => 'Enter the GCash number to send it to.',
            'gcash_number.regex' => 'Enter an 11-digit GCash number, e.g. 09171234567.',
            'gcash_name.required' => 'Enter the name on the GCash account.',
        ];
    }
}
