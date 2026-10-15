<?php

namespace App\Http\Requests\Admin;

use App\Enums\DiscountType;
use App\Enums\PromotionStatus;
use Illuminate\Validation\Rule;

class PromotionRequest extends AdminRequest
{
    public function rules(): array
    {
        $type = $this->input('discount_type');

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'alpha_dash', 'max:170', Rule::unique('promotions', 'slug')->ignore($this->route('promotion'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'discount_value' => [Rule::requiredIf(in_array($type, ['percentage', 'fixed'], true)), 'nullable', 'numeric', 'min:0',
                $type === 'percentage' ? 'max:100' : 'max:999999'],
            'promo_price' => [Rule::requiredIf($type === 'special_price'), 'nullable', 'numeric', 'min:0', 'max:999999'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'terms_and_conditions' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(PromotionStatus::class)],
            'image' => $this->imageRules(),
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'exists:services,id'],
            'branches' => ['nullable', 'array'],
            'branches.*' => ['string', 'exists:branches,id'],
        ];
    }
}
