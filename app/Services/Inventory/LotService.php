<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\SaleItemLotAllocation;
use App\Models\StockLot;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LotService
{
    /**
     * @return array<int, array{stock_lot_id: int, quantity: float, unit_cost: float}>
     */
    public function allocateFifo(
        Product $product,
        float $quantity,
        bool $skipExpired = true,
        ?ProductVariant $variant = null,
    ): array {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'items' => ['Quantity must be greater than zero.'],
            ]);
        }

        $today = now()->startOfDay();

        $lotsQuery = StockLot::query()
            ->where('product_id', $product->id)
            ->where('quantity_remaining', '>', 0);

        if ($variant !== null) {
            $lotsQuery->where('product_variant_id', $variant->id);
        } else {
            $lotsQuery->whereNull('product_variant_id');
        }

        $lots = $lotsQuery
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $allocations = [];

        foreach ($lots as $lot) {
            if ($skipExpired
                && $lot->expiration_date !== null
                && $lot->expiration_date->lt($today)) {
                continue;
            }

            $take = min($remaining, (float) $lot->quantity_remaining);

            if ($take <= 0) {
                continue;
            }

            $lot->quantity_remaining = round((float) $lot->quantity_remaining - $take, 3);
            $lot->save();

            $allocations[] = [
                'stock_lot_id' => $lot->id,
                'quantity' => $take,
                'unit_cost' => (float) $lot->unit_cost,
            ];

            $remaining = round($remaining - $take, 3);

            if ($remaining <= 0) {
                break;
            }
        }

        if ($remaining > 0.0001) {
            $label = $variant ? $product->name.' ('.$variant->buildLabel().')' : $product->name;
            $message = $skipExpired
                ? "Insufficient sellable stock for product: {$label}."
                : "Insufficient stock for product: {$label}.";

            throw ValidationException::withMessages([
                'items' => [$message],
            ]);
        }

        return $allocations;
    }

    public function createLot(
        Store $store,
        Product $product,
        float $quantity,
        float $unitCost,
        ?Carbon $receivedAt = null,
        ?string $expirationDate = null,
        ?PurchaseItem $purchaseItem = null,
        ?ProductVariant $variant = null,
    ): StockLot {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Lot quantity must be greater than zero.'],
            ]);
        }

        return StockLot::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'purchase_item_id' => $purchaseItem?->id,
            'received_at' => $receivedAt ?? now(),
            'expiration_date' => $expirationDate,
            'unit_cost' => $unitCost,
            'quantity_received' => $quantity,
            'quantity_remaining' => $quantity,
        ]);
    }

    /**
     * Restore qty onto original lots (LIFO on allocations).
     * Call before creating the SaleReturnItem; pass prior returned qty for this sale line.
     */
    public function restoreAllocations(SaleItem $saleItem, float $returnQty, float $alreadyReturned = 0): void
    {
        if ($returnQty <= 0) {
            return;
        }

        $allocations = SaleItemLotAllocation::query()
            ->where('sale_item_id', $saleItem->id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        if ($allocations->isEmpty()) {
            $product = Product::query()->lockForUpdate()->findOrFail($saleItem->product_id);
            $store = $product->store;

            $this->createLot(
                $store,
                $product,
                $returnQty,
                (float) ($saleItem->unit_cost ?? $product->cost_price ?? 0),
                now(),
                $product->expiration_date?->format('Y-m-d'),
            );

            return;
        }

        $restorable = $this->restorableByAllocation($allocations, $alreadyReturned);
        $remaining = $returnQty;

        foreach ($allocations as $allocation) {
            $canRestore = $restorable[$allocation->id] ?? 0.0;

            if ($canRestore <= 0) {
                continue;
            }

            $take = min($remaining, $canRestore);

            $lot = StockLot::query()->lockForUpdate()->findOrFail($allocation->stock_lot_id);
            $lot->quantity_remaining = round((float) $lot->quantity_remaining + $take, 3);
            $lot->save();

            $remaining = round($remaining - $take, 3);

            if ($remaining <= 0) {
                break;
            }
        }

        if ($remaining > 0.0001) {
            throw ValidationException::withMessages([
                'items' => ["Unable to restore stock for {$saleItem->product_name}."],
            ]);
        }
    }

    /**
     * @param  Collection<int, SaleItemLotAllocation>  $allocations
     * @return array<int, float>
     */
    private function restorableByAllocation(Collection $allocations, float $alreadyReturned): array
    {
        $restorable = [];

        foreach ($allocations as $allocation) {
            $restorable[$allocation->id] = (float) $allocation->quantity;
        }

        $toBurn = max(0.0, $alreadyReturned);

        foreach ($allocations->sortByDesc('id') as $allocation) {
            if ($toBurn <= 0) {
                break;
            }

            $burn = min($toBurn, $restorable[$allocation->id]);
            $restorable[$allocation->id] = round($restorable[$allocation->id] - $burn, 3);
            $toBurn = round($toBurn - $burn, 3);
        }

        return $restorable;
    }

    public function refreshProductStockMeta(Product $product, ?ProductVariant $variant = null): Product
    {
        $product->refresh();

        if ($product->has_variants) {
            if ($variant !== null) {
                $this->refreshVariantStockFromLots($variant);
            } else {
                $variants = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->get();

                foreach ($variants as $v) {
                    $this->refreshVariantStockFromLots($v);
                }
            }

            $total = (float) ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->sum('stock_quantity');

            $product->stock_quantity = round($total, 3);
            $product->save();

            return $product->fresh();
        }

        $remaining = (float) StockLot::query()
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->sum('quantity_remaining');

        $soonestExpiry = StockLot::query()
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiration_date')
            ->orderBy('expiration_date')
            ->value('expiration_date');

        $product->stock_quantity = round($remaining, 3);

        if ($remaining > 0.0001) {
            $product->expiration_date = $soonestExpiry;
        }

        $product->save();

        return $product->fresh();
    }

    public function refreshVariantStockFromLots(ProductVariant $variant): ProductVariant
    {
        $remaining = (float) StockLot::query()
            ->where('product_variant_id', $variant->id)
            ->sum('quantity_remaining');

        $variant->stock_quantity = round($remaining, 3);
        $variant->save();

        return $variant->fresh();
    }

    public function blendedUnitCost(array $allocations): float
    {
        $qty = 0.0;
        $cost = 0.0;

        foreach ($allocations as $allocation) {
            $qty += (float) $allocation['quantity'];
            $cost += (float) $allocation['quantity'] * (float) $allocation['unit_cost'];
        }

        if ($qty <= 0) {
            return 0.0;
        }

        return round($cost / $qty, 2);
    }

    public function persistSaleAllocations(SaleItem $saleItem, array $allocations): void
    {
        foreach ($allocations as $allocation) {
            SaleItemLotAllocation::query()->create([
                'sale_item_id' => $saleItem->id,
                'stock_lot_id' => $allocation['stock_lot_id'],
                'quantity' => $allocation['quantity'],
                'unit_cost' => $allocation['unit_cost'],
            ]);
        }
    }

    public function ensureOpeningLotForProduct(Product $product, ?ProductVariant $variant = null): void
    {
        $lotsQuery = StockLot::query()->where('product_id', $product->id);

        if ($variant !== null) {
            $lotsQuery->where('product_variant_id', $variant->id);
        } else {
            $lotsQuery->whereNull('product_variant_id');
        }

        if ($lotsQuery->exists()) {
            return;
        }

        $qty = $variant !== null
            ? (float) $variant->stock_quantity
            : (float) $product->stock_quantity;

        if ($qty <= 0) {
            return;
        }

        $cost = $variant !== null
            ? ($variant->resolvedCostPrice() ?? 0)
            : (float) ($product->cost_price ?? 0);

        $this->createLot(
            $product->store,
            $product,
            $qty,
            $cost,
            $product->created_at ?? now(),
            $product->expiration_date?->format('Y-m-d'),
            null,
            $variant,
        );
    }
}
