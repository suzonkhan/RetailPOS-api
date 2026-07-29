<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'max:2048'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
