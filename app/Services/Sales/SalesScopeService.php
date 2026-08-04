<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Services\Branch\BranchScopeService;

class SalesScopeService
{
    public function __construct(
        private readonly BranchScopeService $branchScope,
    ) {}

    public function resolveStore(User $user): Store
    {
        return $this->branchScope->resolveBranch($user);
    }

    public function authorizeStoreResource(User $user, object $model): void
    {
        $this->branchScope->authorizeBranchResource($user, $model);
    }

    public function authorizeCustomer(User $user, Customer $customer): void
    {
        $this->authorizeStoreResource($user, $customer);
    }

    public function authorizeSale(User $user, Sale $sale): void
    {
        $this->authorizeStoreResource($user, $sale);
    }

    public function productBelongsToStore(int $productId, Store $store): bool
    {
        return Product::query()
            ->where('id', $productId)
            ->where('store_id', $store->id)
            ->exists();
    }

    public function customerBelongsToStore(?int $customerId, Store $store): bool
    {
        if ($customerId === null) {
            return true;
        }

        return Customer::query()
            ->where('id', $customerId)
            ->where('store_id', $store->id)
            ->exists();
    }
}
