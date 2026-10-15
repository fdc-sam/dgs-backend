<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Validation\Rule;

class ProductRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:120'],
            // From the Unit dropdown; a product's existing unit stays valid even if it is not in the list.
            'unit' => ['required', 'string', 'max:30', Rule::in(array_filter([...array_keys(Product::UNITS), $this->route('product')?->unit]))],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:999999'],
            // Opening stock (new products only; afterwards stock changes through Add stock / Count stock).
            'stock_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['unit_cost' => 'cost per unit', 'stock_quantity' => 'current stock', 'reorder_level' => 'low stock alert'];
    }
}
