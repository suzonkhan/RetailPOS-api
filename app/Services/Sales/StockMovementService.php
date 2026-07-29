<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;

class StockMovementService
{
    public function adjust(
        Store $store,
        Product $product,
        float $quantityDelta,
        string $type,
        string $referenceType,
        int $referenceId,
        ?ProductVariant $variant = null,
    ): StockMovement {
        $product->refresh();

        if ($variant !== null) {
            $variant->refresh();
            $newQuantity = (float) $variant->stock_quantity + $quantityDelta;

            if ($newQuantity < 0) {
                abort(422, 'Insufficient stock for variant: '.$product->name);
            }

            $variant->stock_quantity = $newQuantity;
            $variant->save();

            $product->stock_quantity = round(
                (float) $product->variants()->where('is_active', true)->sum('stock_quantity'),
                3
            );
            $product->save();

            return StockMovement::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => $type,
                'quantity_delta' => $quantityDelta,
                'quantity_after' => $newQuantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        }

        $newQuantity = (float) $product->stock_quantity + $quantityDelta;

        if ($newQuantity < 0) {
            abort(422, 'Insufficient stock for product: '.$product->name);
        }

        $product->stock_quantity = $newQuantity;
        $product->save();

        return StockMovement::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'type' => $type,
            'quantity_delta' => $quantityDelta,
            'quantity_after' => $newQuantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }
}
