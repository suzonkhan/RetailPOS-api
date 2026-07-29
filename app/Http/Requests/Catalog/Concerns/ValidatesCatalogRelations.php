<?php

namespace App\Http\Requests\Catalog\Concerns;

use App\Services\Catalog\CatalogScopeService;
use Illuminate\Validation\Rule;

trait ValidatesCatalogRelations
{
    protected function prepareCatalogRelationIds(): void
    {
        $merged = [];

        foreach (['supplier_id', 'brand_id'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if ($value === '' || $value === 'null' || $value === false) {
                $merged[$field] = null;
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    protected function catalogRelationRules(bool $categoryRequired = true): array
    {
        $user = $this->user();
        $store = app(CatalogScopeService::class)->resolveStore($user);
        $storeId = $store->id;

        $categoryRule = $categoryRequired
            ? ['required', 'integer', Rule::exists('categories', 'id')->where('store_id', $storeId)]
            : ['sometimes', 'integer', Rule::exists('categories', 'id')->where('store_id', $storeId)];

        return [
            'category_id' => $categoryRule,
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('store_id', $storeId)],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('store_id', $storeId)],
        ];
    }

    protected function vatRules(): array
    {
        $vatTypes = config('retail360.vat_types', ['percent', 'fixed']);

        return [
            'vat_rate' => ['nullable', 'numeric', 'min:0'],
            'vat_type' => ['nullable', 'string', Rule::in($vatTypes), 'required_with:vat_rate'],
        ];
    }
}
