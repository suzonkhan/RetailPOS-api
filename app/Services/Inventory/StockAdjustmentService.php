<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Catalog\CatalogScopeService;
use App\Services\Catalog\ProductVariantService;
use App\Services\Sales\StockMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjustmentService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly LotService $lots,
        private readonly StockMovementService $stockMovement,
        private readonly ProductVariantService $variantService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForUser(User $user, array $data): StockAdjustment
    {
        $store = $this->catalogScope->resolveStore($user);

        return DB::transaction(function () use ($user, $store, $data) {
            $product = Product::query()
                ->where('store_id', $store->id)
                ->where('id', $data['product_id'])
                ->lockForUpdate()
                ->first();

            if ($product === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['Product is invalid for this store.'],
                ]);
            }

            $variant = null;
            if ($product->has_variants) {
                $variantId = $data['product_variant_id'] ?? null;
                if ($variantId === null) {
                    throw ValidationException::withMessages([
                        'product_variant_id' => ["Variant is required for \"{$product->name}\"."],
                    ]);
                }
                $variant = $this->variantService->resolveVariantForSale($product, (int) $variantId);
            }

            $delta = (float) $data['quantity_delta'];

            if (abs($delta) < 0.0001) {
                throw ValidationException::withMessages([
                    'quantity_delta' => ['Quantity delta must not be zero.'],
                ]);
            }

            if ($delta > 0) {
                if (! array_key_exists('unit_cost', $data) || $data['unit_cost'] === null) {
                    throw ValidationException::withMessages([
                        'unit_cost' => ['Unit cost is required when increasing stock.'],
                    ]);
                }

                $this->lots->createLot(
                    $store,
                    $product,
                    $delta,
                    (float) $data['unit_cost'],
                    now(),
                    $data['expiration_date'] ?? null,
                    null,
                    $variant,
                );

                if ($variant !== null) {
                    $variant->cost_price = (float) $data['unit_cost'];
                    $variant->save();
                } else {
                    $product->cost_price = (float) $data['unit_cost'];
                    $product->save();
                }
            } else {
                $this->lots->allocateFifo($product, abs($delta), skipExpired: false, variant: $variant);
            }

            $adjustment = StockAdjustment::query()->create([
                'tenant_id' => $user->tenant_id,
                'store_id' => $store->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity_delta' => $delta,
                'reason' => $data['reason'] ?? null,
                'unit_cost' => $data['unit_cost'] ?? null,
                'expiration_date' => $data['expiration_date'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->stockMovement->adjust(
                $store,
                $product,
                $delta,
                StockMovement::TYPE_ADJUSTMENT,
                StockAdjustment::class,
                $adjustment->id,
                $variant,
            );

            $this->lots->refreshProductStockMeta($product, $variant);

            return $adjustment->load(['product', 'creator']);
        });
    }
}
