<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantOption;
use App\Models\StockLot;
use App\Models\VariationAttributeValue;
use App\Support\BarcodeGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductVariantService
{
    private const MAX_COMBINATIONS = 200;

    public function getSetup(Product $product): array
    {
        $product->load([
            'variants.options.attribute',
            'category',
        ]);

        $selectedValueIds = DB::table('product_variation_values')
            ->where('product_id', $product->id)
            ->pluck('variation_attribute_value_id')
            ->all();

        return [
            'product' => $product,
            'has_variants' => (bool) $product->has_variants,
            'selected_value_ids' => $selectedValueIds,
            'variants' => $product->variants()->with('options.attribute')->orderBy('id')->get(),
        ];
    }

    public function setup(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $hasVariants = (bool) ($data['has_variants'] ?? false);

            if (! $hasVariants) {
                return $this->disableVariants($product);
            }

            $valueIds = collect($data['attribute_value_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($valueIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'attribute_value_ids' => ['Select at least one variation value.'],
                ]);
            }

            $values = VariationAttributeValue::query()
                ->with('attribute')
                ->whereIn('id', $valueIds)
                ->whereHas('attribute', fn ($q) => $q
                    ->where('store_id', $product->store_id)
                    ->where('is_active', true))
                ->get();

            if ($values->count() !== $valueIds->count()) {
                throw ValidationException::withMessages([
                    'attribute_value_ids' => ['One or more variation values are invalid.'],
                ]);
            }

            $grouped = $values->groupBy('variation_attribute_id');

            if ($grouped->count() < 1) {
                throw ValidationException::withMessages([
                    'attribute_value_ids' => ['Select values from at least one variation attribute.'],
                ]);
            }

            $combinations = $this->cartesianProduct($grouped);
            $comboCount = count($combinations);

            if ($comboCount > self::MAX_COMBINATIONS) {
                throw ValidationException::withMessages([
                    'attribute_value_ids' => ["Too many combinations ({$comboCount}). Maximum is ".self::MAX_COMBINATIONS.'.'],
                ]);
            }

            DB::table('product_variation_attributes')->where('product_id', $product->id)->delete();
            DB::table('product_variation_values')->where('product_id', $product->id)->delete();

            foreach ($grouped->keys() as $attributeId) {
                DB::table('product_variation_attributes')->insert([
                    'product_id' => $product->id,
                    'variation_attribute_id' => $attributeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($valueIds as $valueId) {
                DB::table('product_variation_values')->insert([
                    'product_id' => $product->id,
                    'variation_attribute_value_id' => $valueId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $existingVariants = ProductVariant::query()
                ->where('product_id', $product->id)
                ->with('options')
                ->get()
                ->keyBy('option_signature');

            $activeSignatures = [];

            foreach ($combinations as $combo) {
                $signature = $this->buildSignature($combo);
                $activeSignatures[] = $signature;

                if ($existingVariants->has($signature)) {
                    $variant = $existingVariants->get($signature);
                    if (! $variant->is_active) {
                        $variant->is_active = true;
                        $variant->save();
                    }

                    continue;
                }

                $variant = ProductVariant::query()->create([
                    'tenant_id' => $product->tenant_id,
                    'store_id' => $product->store_id,
                    'product_id' => $product->id,
                    'sku' => $this->suggestSku($product, $combo),
                    'barcode' => app(BarcodeGenerator::class)->uniqueForTenant(
                        (int) $product->tenant_id,
                        (int) $product->store_id,
                    ),
                    'selling_price' => null,
                    'cost_price' => null,
                    'stock_quantity' => 0,
                    'option_signature' => $signature,
                    'is_active' => true,
                ]);

                foreach ($combo as $value) {
                    ProductVariantOption::query()->create([
                        'product_variant_id' => $variant->id,
                        'variation_attribute_value_id' => $value->id,
                    ]);
                }
            }

            foreach ($existingVariants as $signature => $variant) {
                if (! in_array($signature, $activeSignatures, true)) {
                    if ((float) $variant->stock_quantity > 0) {
                        throw ValidationException::withMessages([
                            'attribute_value_ids' => [
                                "Cannot remove variant \"{$variant->buildLabel()}\" while it has stock.",
                            ],
                        ]);
                    }

                    $variant->is_active = false;
                    $variant->save();
                }
            }

            $product->has_variants = true;
            $product->save();

            $this->recomputeParentStock($product);

            return $product->fresh()->load(['variants.options.attribute', 'category']);
        });
    }

    public function bulkUpdate(Product $product, array $variantsData): Collection
    {
        return DB::transaction(function () use ($product, $variantsData) {
            $updated = collect();

            foreach ($variantsData as $row) {
                $variant = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('id', $row['id'])
                    ->lockForUpdate()
                    ->first();

                if ($variant === null) {
                    throw ValidationException::withMessages([
                        'variants' => ['One or more variants are invalid for this product.'],
                    ]);
                }

                $this->validateUniqueSkuBarcode($product, $variant, $row);

                $barcode = array_key_exists('barcode', $row)
                    ? $this->resolveVariantBarcode($product, $variant, $row['barcode'])
                    : $variant->barcode;

                $variant->fill([
                    'sku' => array_key_exists('sku', $row) ? ($row['sku'] ?: null) : $variant->sku,
                    'barcode' => $barcode,
                    'selling_price' => array_key_exists('selling_price', $row)
                        ? $row['selling_price']
                        : $variant->selling_price,
                    'cost_price' => array_key_exists('cost_price', $row)
                        ? $row['cost_price']
                        : $variant->cost_price,
                    'is_active' => array_key_exists('is_active', $row)
                        ? (bool) $row['is_active']
                        : $variant->is_active,
                ]);

                if (array_key_exists('stock_quantity', $row)) {
                    $this->adjustVariantStock($product, $variant, (float) $row['stock_quantity']);
                }

                $variant->save();
                $updated->push($variant->load('options.attribute'));
            }

            $this->recomputeParentStock($product);

            return $updated;
        });
    }

    public function updateVariant(Product $product, ProductVariant $variant, array $data): ProductVariant
    {
        return $this->bulkUpdate($product, [array_merge($data, ['id' => $variant->id])])->first();
    }

    public function deleteVariant(Product $product, ProductVariant $variant): void
    {
        if ((float) $variant->stock_quantity > 0) {
            throw ValidationException::withMessages([
                'variant' => ['Cannot remove variant while it has stock.'],
            ]);
        }

        $hasLots = StockLot::query()
            ->where('product_variant_id', $variant->id)
            ->where('quantity_remaining', '>', 0)
            ->exists();

        if ($hasLots) {
            throw ValidationException::withMessages([
                'variant' => ['Cannot remove variant while it has open stock lots.'],
            ]);
        }

        $variant->is_active = false;
        $variant->save();

        $this->recomputeParentStock($product);
    }

    public function recomputeParentStock(Product $product): Product
    {
        if (! $product->has_variants) {
            return $product;
        }

        $total = (float) ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->sum('stock_quantity');

        $product->stock_quantity = round($total, 3);
        $product->save();

        return $product->fresh();
    }

    public function resolveVariantForSale(Product $product, int $variantId): ProductVariant
    {
        if (! $product->has_variants) {
            throw ValidationException::withMessages([
                'items' => ["Product \"{$product->name}\" does not use variants."],
            ]);
        }

        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('id', $variantId)
            ->where('is_active', true)
            ->with(['options.attribute', 'product'])
            ->first();

        if ($variant === null) {
            throw ValidationException::withMessages([
                'items' => ["Invalid variant for \"{$product->name}\"."],
            ]);
        }

        return $variant;
    }

    /**
     * @param  Collection<int, Collection<int, VariationAttributeValue>>  $grouped
     * @return array<int, array<int, VariationAttributeValue>>
     */
    private function cartesianProduct(Collection $grouped): array
    {
        $result = [[]];

        foreach ($grouped->sortKeys() as $values) {
            $append = [];

            foreach ($result as $partial) {
                foreach ($values->sortBy('sort_order') as $value) {
                    $combo = $partial;
                    $combo[] = $value;
                    $append[] = $combo;
                }
            }

            $result = $append;
        }

        return $result;
    }

    /**
     * @param  array<int, VariationAttributeValue>  $combo
     */
    private function buildSignature(array $combo): string
    {
        $parts = collect($combo)
            ->sortBy(fn (VariationAttributeValue $v) => $v->variation_attribute_id)
            ->map(fn (VariationAttributeValue $v) => $v->variation_attribute_id.':'.$v->id)
            ->values()
            ->all();

        return implode('|', $parts);
    }

    /**
     * @param  array<int, VariationAttributeValue>  $combo
     */
    private function suggestSku(Product $product, array $combo): ?string
    {
        if (empty($product->sku)) {
            return null;
        }

        $suffix = collect($combo)
            ->sortBy(fn (VariationAttributeValue $v) => $v->variation_attribute_id)
            ->map(fn (VariationAttributeValue $v) => strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $v->value) ?: 'X'))
            ->implode('-');

        return $product->sku.'-'.$suffix;
    }

    private function disableVariants(Product $product): Product
    {
        $hasStock = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('stock_quantity', '>', 0)
            ->exists();

        if ($hasStock) {
            throw ValidationException::withMessages([
                'has_variants' => ['Cannot disable variants while any variant has stock.'],
            ]);
        }

        $hasOpenLots = StockLot::query()
            ->where('product_id', $product->id)
            ->whereNotNull('product_variant_id')
            ->where('quantity_remaining', '>', 0)
            ->exists();

        if ($hasOpenLots) {
            throw ValidationException::withMessages([
                'has_variants' => ['Cannot disable variants while open stock lots exist.'],
            ]);
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->update(['is_active' => false]);

        DB::table('product_variation_attributes')->where('product_id', $product->id)->delete();
        DB::table('product_variation_values')->where('product_id', $product->id)->delete();

        $product->has_variants = false;
        $product->save();

        return $product->fresh();
    }

    private function resolveVariantBarcode(Product $product, ProductVariant $variant, mixed $incoming): string
    {
        $value = is_string($incoming) ? trim($incoming) : '';

        if ($value !== '') {
            return $value;
        }

        if ($variant->barcode) {
            return $variant->barcode;
        }

        return app(BarcodeGenerator::class)->uniqueForTenant(
            (int) $product->tenant_id,
            (int) $product->store_id,
        );
    }

    private function validateUniqueSkuBarcode(Product $product, ProductVariant $variant, array $row): void
    {
        if (! empty($row['sku'])) {
            $exists = ProductVariant::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('sku', $row['sku'])
                ->where('id', '!=', $variant->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'sku' => ["SKU \"{$row['sku']}\" is already in use."],
                ]);
            }
        }

        if (! empty($row['barcode'])) {
            $code = trim((string) $row['barcode']);

            $productClash = Product::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('barcode', $code)
                ->exists();

            $variantClash = ProductVariant::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('barcode', $code)
                ->where('id', '!=', $variant->id)
                ->exists();

            if ($productClash || $variantClash) {
                throw ValidationException::withMessages([
                    'barcode' => ["Barcode \"{$code}\" is already in use."],
                ]);
            }
        }
    }

    private function adjustVariantStock(Product $product, ProductVariant $variant, float $newQty): void
    {
        if ($newQty < 0) {
            throw ValidationException::withMessages([
                'stock_quantity' => ['Stock cannot be negative.'],
            ]);
        }

        $variant->stock_quantity = round($newQty, 3);
    }
}
