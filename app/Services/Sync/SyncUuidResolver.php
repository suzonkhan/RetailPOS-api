<?php

namespace App\Services\Sync;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Validation\ValidationException;

class SyncUuidResolver
{
    public function resolveCustomerId(Store $store, array $sale): ?int
    {
        if (! empty($sale['customer_id'])) {
            return (int) $sale['customer_id'];
        }

        if (empty($sale['customer_uuid'])) {
            return null;
        }

        $customer = Customer::query()
            ->where('store_id', $store->id)
            ->where('uuid', $sale['customer_uuid'])
            ->first();

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_uuid' => ['Customer not found for this store.'],
            ]);
        }

        return $customer->id;
    }

    public function resolveProductId(Store $store, array $item): int
    {
        if (! empty($item['product_id'])) {
            $exists = Product::query()
                ->where('store_id', $store->id)
                ->where('id', $item['product_id'])
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'product_uuid' => ['Product not found for this store.'],
                ]);
            }

            return (int) $item['product_id'];
        }

        if (empty($item['product_uuid'])) {
            throw ValidationException::withMessages([
                'product_uuid' => ['Product reference is required.'],
            ]);
        }

        $product = Product::query()
            ->where('store_id', $store->id)
            ->where('uuid', $item['product_uuid'])
            ->first();

        if ($product === null) {
            throw ValidationException::withMessages([
                'product_uuid' => ['Product not found for this store.'],
            ]);
        }

        return $product->id;
    }

    public function resolveProductVariantId(Store $store, array $item, int $productId): ?int
    {
        if (empty($item['product_variant_id']) && empty($item['product_variant_uuid'])) {
            return null;
        }

        if (! empty($item['product_variant_id'])) {
            $exists = ProductVariant::query()
                ->where('store_id', $store->id)
                ->where('product_id', $productId)
                ->where('id', $item['product_variant_id'])
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'product_variant_uuid' => ['Product variant not found for this store.'],
                ]);
            }

            return (int) $item['product_variant_id'];
        }

        $variant = ProductVariant::query()
            ->where('store_id', $store->id)
            ->where('product_id', $productId)
            ->where('uuid', $item['product_variant_uuid'])
            ->first();

        if ($variant === null) {
            throw ValidationException::withMessages([
                'product_variant_uuid' => ['Product variant not found for this store.'],
            ]);
        }

        return $variant->id;
    }

    public function resolvePaymentMethodId(Store $store, array $payment): int
    {
        if (! empty($payment['payment_method_id'])) {
            $exists = PaymentMethod::query()
                ->where('store_id', $store->id)
                ->where('id', $payment['payment_method_id'])
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'payment_method_uuid' => ['Payment method not found for this store.'],
                ]);
            }

            return (int) $payment['payment_method_id'];
        }

        if (empty($payment['payment_method_uuid'])) {
            throw ValidationException::withMessages([
                'payment_method_uuid' => ['Payment method reference is required.'],
            ]);
        }

        $method = PaymentMethod::query()
            ->where('store_id', $store->id)
            ->where('uuid', $payment['payment_method_uuid'])
            ->first();

        if ($method === null) {
            throw ValidationException::withMessages([
                'payment_method_uuid' => ['Payment method not found for this store.'],
            ]);
        }

        return $method->id;
    }
}
