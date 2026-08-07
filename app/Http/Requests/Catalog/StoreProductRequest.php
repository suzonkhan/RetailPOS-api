<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ValidatesCatalogRelations;
use App\Services\Catalog\CatalogScopeService;
use App\Support\Uom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    use ValidatesCatalogRelations;

    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareCatalogRelationIds();
    }

    public function rules(): array
    {
        $storeId = app(CatalogScopeService::class)
            ->resolveStore($this->user())
            ->id;

        return array_merge($this->catalogRelationRules(), $this->vatRules(), [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('products', 'sku')->where('store_id', $storeId)],
            'barcode' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:5000'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'min_stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'uom' => ['required', 'string', 'max:16', Rule::in(Uom::codes())],
            'is_active' => ['sometimes', 'boolean'],
            'is_negotiable' => ['sometimes', 'boolean'],
            'ask_qty_on_add' => ['sometimes', 'boolean'],
            'manage_inventory' => ['sometimes', 'boolean'],
        ]);
    }
}
