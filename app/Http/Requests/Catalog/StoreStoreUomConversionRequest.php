<?php

namespace App\Http\Requests\Catalog;

use App\Services\Catalog\CatalogScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStoreUomConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') ?? false;
    }

    public function rules(): array
    {
        $storeId = app(CatalogScopeService::class)
            ->resolveStore($this->user())
            ->id;

        return [
            'from_uom_id' => [
                'required',
                'integer',
                Rule::exists('store_uoms', 'id')->where('store_id', $storeId),
            ],
            'to_uom_id' => [
                'required',
                'integer',
                'different:from_uom_id',
                Rule::exists('store_uoms', 'id')->where('store_id', $storeId),
            ],
            'factor' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
