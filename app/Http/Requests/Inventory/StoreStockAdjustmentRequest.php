<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['nullable', 'string', 'max:500'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'expiration_date' => ['nullable', 'date'],
        ];
    }
}
