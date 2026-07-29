<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class SetupProductVariationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'has_variants' => ['required', 'boolean'],
            'attribute_value_ids' => ['required_if:has_variants,true', 'array'],
            'attribute_value_ids.*' => ['integer', 'exists:variation_attribute_values,id'],
        ];
    }
}
