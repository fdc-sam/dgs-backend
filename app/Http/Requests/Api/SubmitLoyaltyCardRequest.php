<?php

namespace App\Http\Requests\Api;

use App\Services\PaymentService;

/** A loyalty card through a friend: the friend's QR code, the GCash receipt for the salon's share and the cash promise. */
class SubmitLoyaltyCardRequest extends ApiRequest
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
            'code' => ['required', 'string', 'max:64'],
            'reference_number' => ['required', 'string', 'regex:/^[A-Z0-9]{6,30}$/'],
            'proof' => ['required', 'file', 'image', 'mimes:'.implode(',', config('salon.uploads.mimes')), 'max:'.config('salon.uploads.max_kilobytes'), 'dimensions:max_width=8000,max_height=8000'],
            'cash_given' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => "Scan your friend's QR code first.",
            'reference_number.regex' => 'Enter the Ref No. exactly as shown on your GCash receipt.',
            'proof.required' => 'Attach a screenshot of your GCash receipt.',
            'cash_given.accepted' => "Confirm that you gave your friend their share in cash.",
        ];
    }
}
