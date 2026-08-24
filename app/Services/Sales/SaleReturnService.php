<?php

namespace App\Services\Sales;

use App\Models\CustomerDue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\LotService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReturnService
{
    public function __construct(
        private readonly SalesScopeService $scope,
        private readonly StockMovementService $stockMovement,
        private readonly LotService $lots,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForSale(User $user, Sale $sale, array $data): SaleReturn
    {
        $this->scope->authorizeSale($user, $sale);

        try {
            return DB::transaction(function () use ($user, $sale, $data) {
                $store = $this->scope->resolveStore($user);

                $sale = Sale::query()
                    ->whereKey($sale->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! empty($data['uuid'])) {
                    $existing = SaleReturn::query()
                        ->where('tenant_id', $user->tenant_id)
                        ->where('uuid', $data['uuid'])
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return $existing->load('items');
                    }
                }

                $grossTotal = round((float) SaleItem::query()
                    ->where('sale_id', $sale->id)
                    ->sum('line_total'), 2);
                $paidShare = $grossTotal > 0.0001
                    ? ((float) $sale->total / $grossTotal)
                    : 1.0;

                $subtotal = 0.0;
                $vatTotal = 0.0;
                $total = 0.0;

                $saleReturn = SaleReturn::query()->create([
                    'uuid' => $data['uuid'] ?? null,
                    'tenant_id' => $user->tenant_id,
                    'store_id' => $store->id,
                    'sale_id' => $sale->id,
                    'user_id' => $user->id,
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($data['items'] as $itemData) {
                    $saleItem = SaleItem::query()
                        ->where('sale_id', $sale->id)
                        ->where('id', $itemData['sale_item_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($saleItem === null) {
                        throw ValidationException::withMessages([
                            'items' => ['Invalid sale line for this sale.'],
                        ]);
                    }

                    $returnQty = (float) $itemData['quantity'];

                    $alreadyReturnedRows = SaleReturnItem::query()
                        ->where('sale_item_id', $saleItem->id)
                        ->get();

                    $alreadyReturned = (float) $alreadyReturnedRows->sum('quantity');

                    if ($alreadyReturned + $returnQty > (float) $saleItem->quantity + 0.0001) {
                        throw ValidationException::withMessages([
                            'items' => ["Return quantity exceeds sold quantity for {$saleItem->product_name}."],
                        ]);
                    }

                    [$lineSubtotal, $vatAmount, $lineTotal] = $this->prorateReturnAmounts(
                        $saleItem,
                        $returnQty,
                        $alreadyReturned,
                        $alreadyReturnedRows,
                        $paidShare,
                    );

                    $product = Product::query()
                        ->where('id', $saleItem->product_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $variant = $saleItem->product_variant_id
                        ? ProductVariant::query()
                            ->whereKey($saleItem->product_variant_id)
                            ->lockForUpdate()
                            ->first()
                        : null;

                    if ($product->manage_inventory) {
                        $this->lots->restoreAllocations($saleItem, $returnQty, $alreadyReturned);
                    }

                    SaleReturnItem::query()->create([
                        'sale_return_id' => $saleReturn->id,
                        'sale_item_id' => $saleItem->id,
                        'product_id' => $saleItem->product_id,
                        'quantity' => $returnQty,
                        'unit_price' => $saleItem->unit_price,
                        'line_subtotal' => $lineSubtotal,
                        'vat_rate' => $saleItem->vat_rate,
                        'vat_amount' => $vatAmount,
                        'line_total' => $lineTotal,
                    ]);

                    $subtotal += $lineSubtotal;
                    $vatTotal += $vatAmount;
                    $total += $lineTotal;

                    if ($product->manage_inventory) {
                        $this->stockMovement->adjust(
                            $store,
                            $product,
                            $returnQty,
                            StockMovement::TYPE_RETURN,
                            SaleReturn::class,
                            $saleReturn->id,
                            $variant,
                        );

                        $this->lots->refreshProductStockMeta($product, $variant);
                    }
                }

                $saleReturn->update([
                    'subtotal' => round($subtotal, 2),
                    'vat_total' => round($vatTotal, 2),
                    'total' => round($total, 2),
                ]);

                $this->reduceSaleDues($sale, round($total, 2));

                $sale->update([
                    'updated_by' => $user->id,
                    'status' => $this->resolveStatusAfterReturn($sale),
                ]);

                return $saleReturn->load('items');
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! empty($data['uuid'])) {
                $existing = SaleReturn::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('uuid', $data['uuid'])
                    ->first();

                if ($existing !== null) {
                    return $existing->load('items');
                }
            }

            throw $e;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SaleReturnItem>  $alreadyReturnedRows
     * @return array{0: float, 1: float, 2: float}
     */
    private function prorateReturnAmounts(
        SaleItem $saleItem,
        float $returnQty,
        float $alreadyReturned,
        $alreadyReturnedRows,
        float $paidShare,
    ): array {
        $paidLineTotal = round((float) $saleItem->line_total * $paidShare, 2);
        $paidLineSubtotal = round((float) $saleItem->line_subtotal * $paidShare, 2);
        $paidLineVat = round($paidLineTotal - $paidLineSubtotal, 2);

        $alreadySubtotal = round((float) $alreadyReturnedRows->sum('line_subtotal'), 2);
        $alreadyVat = round((float) $alreadyReturnedRows->sum('vat_amount'), 2);
        $alreadyTotal = round((float) $alreadyReturnedRows->sum('line_total'), 2);

        $remainingQty = round((float) $saleItem->quantity - $alreadyReturned, 3);
        $remainingSubtotal = round($paidLineSubtotal - $alreadySubtotal, 2);
        $remainingVat = round($paidLineVat - $alreadyVat, 2);
        $remainingTotal = round($paidLineTotal - $alreadyTotal, 2);

        $isLastChunk = $remainingQty <= $returnQty + 0.0001;

        if ($isLastChunk) {
            return [
                round($remainingSubtotal, 2),
                round($remainingVat, 2),
                round($remainingTotal, 2),
            ];
        }

        $chunk = $remainingQty > 0 ? ($returnQty / $remainingQty) : 0;
        $lineTotal = round($remainingTotal * $chunk, 2);
        $lineSubtotal = round($remainingSubtotal * $chunk, 2);
        $vatAmount = round($lineTotal - $lineSubtotal, 2);

        return [$lineSubtotal, $vatAmount, $lineTotal];
    }

    private function reduceSaleDues(Sale $sale, float $refundAmount): void
    {
        if ($refundAmount <= 0 || $sale->customer_id === null) {
            return;
        }

        $dues = CustomerDue::query()
            ->where('sale_id', $sale->id)
            ->where('status', CustomerDue::STATUS_OPEN)
            ->where('balance', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $refundAmount;

        foreach ($dues as $due) {
            if ($remaining <= 0) {
                break;
            }

            $apply = round(min($remaining, (float) $due->balance), 2);
            $newBalance = round((float) $due->balance - $apply, 2);
            $due->balance = max(0, $newBalance);

            if ($due->balance <= 0.01) {
                $due->balance = 0;
                $due->status = CustomerDue::STATUS_SETTLED;
            }

            $due->save();
            $remaining = round($remaining - $apply, 2);
        }
    }

    private function resolveStatusAfterReturn(Sale $sale): string
    {
        $items = SaleItem::query()
            ->where('sale_id', $sale->id)
            ->get();

        $fullyReturned = true;

        foreach ($items as $item) {
            $returnedQty = (float) SaleReturnItem::query()
                ->where('sale_item_id', $item->id)
                ->sum('quantity');

            if ($returnedQty + 0.0001 < (float) $item->quantity) {
                $fullyReturned = false;
                break;
            }
        }

        return $fullyReturned
            ? Sale::STATUS_RETURNED
            : Sale::STATUS_PARTIALLY_RETURNED;
    }
}
