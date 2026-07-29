<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;

class CatalogScopeService
{
    public function resolveStore(User $user): Store
    {
        $store = $user->tenant?->store;

        if ($store === null) {
            abort(404, 'Store not found.');
        }

        return $store;
    }

    public function authorizeStoreResource(User $user, object $model): void
    {
        $store = $user->tenant?->store;

        if ($store === null || ! property_exists($model, 'store_id') || (int) $model->store_id !== (int) $store->id) {
            abort(404);
        }
    }

    public function categoryBelongsToStore(int $categoryId, Store $store): bool
    {
        return Category::query()
            ->where('id', $categoryId)
            ->where('store_id', $store->id)
            ->exists();
    }

    public function supplierBelongsToStore(?int $supplierId, Store $store): bool
    {
        if ($supplierId === null) {
            return true;
        }

        return Supplier::query()
            ->where('id', $supplierId)
            ->where('store_id', $store->id)
            ->exists();
    }

    public function brandBelongsToStore(?int $brandId, Store $store): bool
    {
        if ($brandId === null) {
            return true;
        }

        return Brand::query()
            ->where('id', $brandId)
            ->where('store_id', $store->id)
            ->exists();
    }

    public function authorizeProduct(User $user, Product $product): void
    {
        $this->authorizeStoreResource($user, $product);
    }

    public function authorizeProductImage(User $user, Product $product, ProductImage $image): void
    {
        $this->authorizeProduct($user, $product);

        if ($image->product_id !== $product->id) {
            abort(404);
        }
    }
}
