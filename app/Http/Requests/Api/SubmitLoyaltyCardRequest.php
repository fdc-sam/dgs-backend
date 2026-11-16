<?php

namespace App\Http\Requests\Api;

use App\Models\LoyaltyCardRequest;
use App\Services\PaymentService;
use Illuminate\Validation\Rule;

/**
 * A loyalty card through a friend: the friend's QR code, how the card was paid to the salon (GCash or bank) and the
 * payment's reference number. Older app versions also send a receipt screenshot and "cash_given"; both are ignored.
 */
class SubmitLoyaltyCardRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reference_number'))) {
            $this->merge(['reference_number' => PaymentService::normalizeReference($this->input('reference_number'))]);
        }
        // Older app versions only knew GCash.
        if (! $this->filled('payment_method')) {
            $this->merge(['payment_method' => LoyaltyCardRequest::METHOD_GCASH]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'payment_method' => ['required', Rule::in([LoyaltyCardRequest::METHOD_GCASH, LoyaltyCardRequest::METHOD_BANK])],
            'reference_number' => ['required', 'string', 'regex:/^[A-Z0-9]{6,30}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => "Scan your friend's QR code first.",
            'payment_method.in' => 'Choose GCash or bank transfer.',
            'reference_number.required' => 'Enter the reference number of your payment.',
            'reference_number.regex' => 'Enter the reference number exactly as shown on your receipt (6 to 30 letters or numbers).',
        ];
    }
}
