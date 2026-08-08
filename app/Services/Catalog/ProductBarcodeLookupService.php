<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;

class ProductBarcodeLookupService
{
    private const MAX_RESULTS = 50;

    public function __construct(
        private readonly CatalogScopeService $catalogScope,
    ) {}

    /**
     * Find active products/variants with an exact barcode match across all stores.
     * Used to suggest catalog fields when adding a product (AI Catalog Builder / admin form).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lookup(User $user, string $barcode): Collection
    {
        $barcode = trim($barcode);
        $ownStoreId = (int) $this->catalogScope->resolveStore($user)->id;

        $fromProducts = Product::query()
            ->where('barcode', $barcode)
            ->where('is_active', true)
            ->with(['category:id,name', 'brand:id,name', 'store:id,name'])
            ->orderBy('id')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (Product $product) => $this->mapProduct($product, $ownStoreId));

        $remaining = self::MAX_RESULTS - $fromProducts->count();

        $fromVariants = collect();
        if ($remaining > 0) {
            $fromVariants = ProductVariant::query()
                ->where('barcode', $barcode)
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->with([
                    'product:id,name,description,sku,uom,vat_rate,vat_type,category_id,brand_id,store_id,selling_price,cost_price',
                    'product.category:id,name',
                    'product.brand:id,name',
                    'product.store:id,name',
                    'options.attribute',
                ])
                ->orderBy('id')
                ->limit($remaining)
                ->get()
                ->map(fn (ProductVariant $variant) => $this->mapVariant($variant, $ownStoreId));
        }

        return $fromProducts->concat($fromVariants)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProduct(Product $product, int $ownStoreId): array
    {
        return [
            'name' => $product->name,
            'barcode' => $product->barcode,
            'sku' => $product->sku,
            'description' => $product->description,
            'selling_price' => (float) $product->selling_price,
            'category_name' => $product->category?->name,
            'brand_name' => $product->brand?->name,
            'uom' => $product->uom ?? 'pcs',
            'vat_rate' => $product->vat_rate !== null ? (float) $product->vat_rate : null,
            'vat_type' => $product->vat_type,
            'store_name' => $product->store?->name,
            'is_own_store' => (int) $product->store_id === $ownStoreId,
            'source' => 'product',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapVariant(ProductVariant $variant, int $ownStoreId): array
    {
        $product = $variant->product;
        $label = $variant->buildLabel();
        $name = $product?->name ?? 'Product';
        if ($label !== '') {
            $name .= ' ('.$label.')';
        }

        return [
            'name' => $name,
            'barcode' => $variant->barcode,
            'sku' => $variant->sku ?? $product?->sku,
            'description' => $product?->description,
            'selling_price' => $variant->resolvedSellingPrice(),
            'category_name' => $product?->category?->name,
            'brand_name' => $product?->brand?->name,
            'uom' => $product?->uom ?? 'pcs',
            'vat_rate' => $product?->vat_rate !== null ? (float) $product->vat_rate : null,
            'vat_type' => $product?->vat_type,
            'store_name' => $product?->store?->name,
            'is_own_store' => (int) $variant->store_id === $ownStoreId,
            'source' => 'variant',
        ];
    }
}
