<?php

namespace App\Http\Requests\Api;

use App\Enums\RecordStatus;
use App\Enums\ReferralStatus;
use Closure;
use Illuminate\Validation\Rule;

class CreateReferralRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->normalizePhone($this->input('phone')),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => [
                'required', 'string', 'regex:'.self::PHONE_REGEX,
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value === $this->user()->phone) {
                        $fail('You cannot refer your own number.');
                    }
                },
                // One referral per number on this side; a referral judged not eligible frees the number again.
                Rule::unique('referrals', 'phone')->where(fn ($q) => $q
                    ->where('status', '!=', ReferralStatus::Declined->value)
                    ->where('is_test', (bool) $this->user()->is_test)),
            ],
            'service_ids' => ['required', 'array', 'min:1', 'max:10'],
            'service_ids.*' => ['string', 'distinct', Rule::exists('services', 'id')->whereNull('deleted_at')->where('status', RecordStatus::Active->value)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid mobile number, e.g. 09171234567.',
            'phone.unique' => 'This number has already been referred.',
            'service_ids.required' => 'Choose at least one service they are interested in.',
            'service_ids.min' => 'Choose at least one service they are interested in.',
        ];
    }
}
