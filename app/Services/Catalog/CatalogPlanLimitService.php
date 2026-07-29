<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

class CatalogPlanLimitService
{
    public function categoryCount(Tenant $tenant): int
    {
        return Category::query()
            ->where('tenant_id', $tenant->id)
            ->count();
    }

    public function productCount(Tenant $tenant): int
    {
        return Product::query()
            ->where('tenant_id', $tenant->id)
            ->count();
    }

    public function canAddCategory(Tenant $tenant): bool
    {
        $plan = $tenant->plan;

        if ($plan === null) {
            return false;
        }

        return $this->categoryCount($tenant) < $plan->max_categories;
    }

    public function canAddProduct(Tenant $tenant): bool
    {
        $plan = $tenant->plan;

        if ($plan === null) {
            return false;
        }

        return $this->productCount($tenant) < $plan->max_products;
    }

    public function assertCanAddCategory(Tenant $tenant): void
    {
        if ($this->canAddCategory($tenant)) {
            return;
        }

        throw ValidationException::withMessages([
            'name' => ['Your plan category limit has been reached. Upgrade to add more categories.'],
        ]);
    }

    public function assertCanAddProduct(Tenant $tenant): void
    {
        if ($this->canAddProduct($tenant)) {
            return;
        }

        throw ValidationException::withMessages([
            'name' => ['Your plan product limit has been reached. Upgrade to add more products.'],
        ]);
    }
}
