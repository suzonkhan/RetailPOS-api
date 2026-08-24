<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\Inventory\LotService;

class StockMovementService
{
    public function __construct(
        private readonly LotService $lots,
    ) {}

    public function adjust(
        Store $store,
        Product $product,
        float $quantityDelta,
        string $type,
        string $referenceType,
        int $referenceId,
        ?ProductVariant $variant = null,
    ): StockMovement {
        $quantityAfter = $variant !== null
            ? $this->lots->sumSellableRemaining((int) $product->id, (int) $variant->id)
            : ($product->has_variants
                ? $this->lots->sumSellableRemaining((int) $product->id)
                : $this->lots->sumSellableRemaining((int) $product->id, simpleLotsOnly: true));

        return StockMovement::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'type' => $type,
            'quantity_delta' => $quantityDelta,
            'quantity_after' => $quantityAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }
}
