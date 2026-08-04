<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Validation\ValidationException;

class CatalogPlanLimitService
{
    public function categoryCount(Store $store): int
    {
        return Category::query()
            ->where('store_id', $store->id)
            ->count();
    }

    public function productCount(Store $store): int
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->count();
    }

    public function canAddCategory(Store $store): bool
    {
        $plan = $store->plan;

        if ($plan === null) {
            return false;
        }

        return $this->categoryCount($store) < $plan->max_categories;
    }

    public function canAddProduct(Store $store): bool
    {
        $plan = $store->plan;

        if ($plan === null) {
            return false;
        }

        return $this->productCount($store) < $plan->max_products;
    }

    public function assertCanAddCategory(Store $store): void
    {
        if ($this->canAddCategory($store)) {
            return;
        }

        throw ValidationException::withMessages([
            'name' => ['Your plan category limit has been reached. Upgrade to add more categories.'],
        ]);
    }

    public function assertCanAddProduct(Store $store): void
    {
        if ($this->canAddProduct($store)) {
            return;
        }

        throw ValidationException::withMessages([
            'name' => ['Your plan product limit has been reached. Upgrade to add more products.'],
        ]);
    }
}
