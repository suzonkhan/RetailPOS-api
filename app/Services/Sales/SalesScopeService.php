<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;

class SalesScopeService
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

        if ($store === null || (int) $model->store_id !== (int) $store->id) {
            abort(404);
        }
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
