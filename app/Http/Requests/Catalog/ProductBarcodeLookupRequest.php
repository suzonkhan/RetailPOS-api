<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class ProductBarcodeLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('barcode')) {
            $this->merge([
                'barcode' => trim((string) $this->input('barcode')),
            ]);
        }
    }
}
