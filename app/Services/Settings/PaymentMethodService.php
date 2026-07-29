<?php

namespace App\Services\Settings;

use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\User;

class PaymentMethodService
{
    public function storeForUser(User $user, array $data): PaymentMethod
    {
        $store = $this->resolveStore($user);

        return PaymentMethod::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
            'requires_reference' => $data['requires_reference'] ?? false,
            'is_credit' => $data['is_credit'] ?? false,
        ]);
    }

    public function update(PaymentMethod $paymentMethod, array $data): PaymentMethod
    {
        $paymentMethod->fill([
            'name' => $data['name'] ?? $paymentMethod->name,
            'is_active' => $data['is_active'] ?? $paymentMethod->is_active,
            'sort_order' => $data['sort_order'] ?? $paymentMethod->sort_order,
            'requires_reference' => $data['requires_reference'] ?? $paymentMethod->requires_reference,
            'is_credit' => $data['is_credit'] ?? $paymentMethod->is_credit,
        ]);

        $paymentMethod->save();

        return $paymentMethod;
    }

    public function resolveStore(User $user): Store
    {
        $store = $user->tenant?->store;

        if ($store === null) {
            abort(404, 'Store not found.');
        }

        return $store;
    }

    public function listForUser(User $user, bool $activeOnly = false)
    {
        $store = $this->resolveStore($user);

        $query = PaymentMethod::query()
            ->where('store_id', $store->id);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
