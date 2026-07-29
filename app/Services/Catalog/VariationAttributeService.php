<?php

namespace App\Services\Catalog;

use App\Models\ProductVariantOption;
use App\Models\VariationAttribute;
use App\Models\VariationAttributeValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VariationAttributeService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
    ) {}

    public function listForUser(User $user)
    {
        $store = $this->catalogScope->resolveStore($user);

        return VariationAttribute::query()
            ->where('store_id', $store->id)
            ->with(['values' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function storeForUser(User $user, array $data): VariationAttribute
    {
        $store = $this->catalogScope->resolveStore($user);

        return DB::transaction(function () use ($user, $store, $data) {
            $attribute = VariationAttribute::query()->create([
                'tenant_id' => $user->tenant_id,
                'store_id' => $store->id,
                'name' => $data['name'],
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            foreach ($data['values'] ?? [] as $index => $value) {
                $this->createValue($attribute, is_array($value) ? $value['value'] : $value, $index);
            }

            return $attribute->load('values');
        });
    }

    public function update(VariationAttribute $attribute, array $data): VariationAttribute
    {
        $attribute->fill([
            'name' => $data['name'] ?? $attribute->name,
            'sort_order' => $data['sort_order'] ?? $attribute->sort_order,
            'is_active' => $data['is_active'] ?? $attribute->is_active,
        ]);
        $attribute->save();

        return $attribute->load('values');
    }

    public function delete(VariationAttribute $attribute): void
    {
        $inUse = ProductVariantOption::query()
            ->whereIn(
                'variation_attribute_value_id',
                $attribute->values()->pluck('id')
            )
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'variation_attribute' => ['Cannot delete: values are used by product variants.'],
            ]);
        }

        $attribute->values()->delete();
        $attribute->delete();
    }

    public function addValue(VariationAttribute $attribute, array $data): VariationAttributeValue
    {
        $sortOrder = $data['sort_order']
            ?? ((int) $attribute->values()->max('sort_order')) + 1;

        return $this->createValue($attribute, $data['value'], $sortOrder, $data['is_active'] ?? true);
    }

    public function updateValue(VariationAttributeValue $value, array $data): VariationAttributeValue
    {
        $value->fill([
            'value' => $data['value'] ?? $value->value,
            'sort_order' => $data['sort_order'] ?? $value->sort_order,
            'is_active' => $data['is_active'] ?? $value->is_active,
        ]);
        $value->save();

        return $value;
    }

    public function deleteValue(VariationAttributeValue $value): void
    {
        $inUse = ProductVariantOption::query()
            ->where('variation_attribute_value_id', $value->id)
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'value' => ['Cannot delete: value is used by product variants.'],
            ]);
        }

        $value->delete();
    }

    public function authorizeAttribute(VariationAttribute $attribute, User $user): void
    {
        $store = $this->catalogScope->resolveStore($user);

        if ((int) $attribute->store_id !== (int) $store->id) {
            abort(404);
        }
    }

    public function authorizeValue(VariationAttributeValue $value, User $user): void
    {
        $value->loadMissing('attribute');
        $this->authorizeAttribute($value->attribute, $user);
    }

    private function createValue(
        VariationAttribute $attribute,
        string $valueText,
        int $sortOrder,
        bool $isActive = true,
    ): VariationAttributeValue {
        return VariationAttributeValue::query()->create([
            'variation_attribute_id' => $attribute->id,
            'value' => $valueText,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    }
}
