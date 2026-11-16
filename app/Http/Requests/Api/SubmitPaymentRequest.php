<?php

namespace App\Http\Requests\Api;

use App\Services\PaymentService;

/** GCash receipt for an appointment's deposit: the reference number. Older app versions also send a screenshot, which is ignored. */
class SubmitPaymentRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reference_number'))) {
            $this->merge(['reference_number' => PaymentService::normalizeReference($this->input('reference_number'))]);
        }
    }

    public function rules(): array
    {
        return [
            'reference_number' => ['required', 'string', 'regex:/^[A-Z0-9]{6,30}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_number.regex' => 'Enter the Ref No. exactly as shown on your GCash receipt.',
            'reference_number.required' => 'Enter the Ref No. from your GCash receipt.',
        ];
    }
}
