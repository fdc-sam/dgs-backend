<?php

namespace App\Http\Requests\Admin;

use App\Enums\RecordStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ServiceRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'string', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'alpha_dash', 'max:170', Rule::unique('services', 'slug')->ignore($this->route('service'))],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'regular_price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'loyalty_discount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'first_visit_discount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'first_visit_discount_enabled' => ['boolean'],
            'first_visit_discount_period' => ['nullable', 'integer', 'min:1', 'max:1200', 'required_if:first_visit_discount_enabled,1'],
            'first_visit_discount_unit' => ['required_if:first_visit_discount_enabled,1', Rule::in(['days', 'months'])],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'is_featured' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['required', Rule::enum(RecordStatus::class)],
            'image' => $this->imageRules(),

            // GCash downpayment: a QR generated in GCash for exactly this amount.
            'deposit_amount' => ['nullable', 'numeric', 'min:1', 'max:999999', 'lte:regular_price'],
            'gcash_qr' => $this->imageRules(),
            'remove_gcash_qr' => ['boolean'],

            'branches' => ['nullable', 'array'],
            'branches.*.enabled' => ['nullable', 'boolean'],
            'branches.*.custom_price' => ['nullable', 'numeric', 'min:0', 'max:999999'],

            'staff' => ['nullable', 'array'],
            'staff.*' => ['string', 'exists:staff_profiles,id'],

            // Inventory used each time the service is done; quantity is in the product's unit.
            'products' => ['nullable', 'array', 'max:30'],
            'products.*.product_id' => ['required', 'string', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'products.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:99999'],

            'variants' => ['nullable', 'array', 'max:20'],
            'variants.*.id' => ['nullable', 'string'],
            'variants.*.name' => ['required_with:variants.*.price', 'nullable', 'string', 'max:120'],
            'variants.*.price' => ['required_with:variants.*.name', 'nullable', 'numeric', 'min:0', 'max:999999'],
            'variants.*.duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'variants.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'variants.*.name' => 'option name', 'variants.*.price' => 'option price', 'branches.*.custom_price' => 'branch price',
            'deposit_amount' => 'downpayment', 'gcash_qr' => 'GCash QR',
            'products.*.product_id' => 'product', 'products.*.quantity' => 'quantity used',
        ];
    }

    public function messages(): array
    {
        return [
            'deposit_amount.lte' => 'The downpayment cannot be more than the regular price.',
            'products.*.product_id.distinct' => 'Each product can be added only once.',
        ];
    }

    /** The amount and its QR only make sense together. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $removing = $this->boolean('remove_gcash_qr');
            $hasQr = ! $removing && ($this->hasFile('gcash_qr') || $this->route('service')?->gcash_qr);
            $hasAmount = $this->filled('deposit_amount');

            if ($hasAmount && ! $hasQr && ! $removing) {
                $validator->errors()->add('gcash_qr', 'Upload the GCash QR you generated for this downpayment.');
            }
            if ($this->hasFile('gcash_qr') && ! $hasAmount) {
                $validator->errors()->add('deposit_amount', 'Enter the amount you set in this GCash QR.');
            }
        }];
    }
}
