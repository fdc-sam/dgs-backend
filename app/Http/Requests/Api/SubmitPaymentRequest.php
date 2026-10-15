<?php

namespace App\Http\Requests\Api;

use App\Services\PaymentService;

/** GCash receipt for an appointment's deposit: the reference number plus a screenshot. */
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
            'proof' => ['required', 'file', 'image', 'mimes:'.implode(',', config('salon.uploads.mimes')), 'max:'.config('salon.uploads.max_kilobytes'), 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_number.regex' => 'Enter the Ref No. exactly as shown on your GCash receipt.',
            'proof.required' => 'Attach a screenshot of your GCash receipt.',
        ];
    }
}
