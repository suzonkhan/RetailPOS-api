<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\LotService;
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

        return DB::transaction(function () use ($user, $sale, $data) {
            $store = $this->scope->resolveStore($user);

            $subtotal = 0.0;
            $vatTotal = 0.0;
            $total = 0.0;

            $saleReturn = SaleReturn::query()->create([
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
                    ->first();

                if ($saleItem === null) {
                    throw ValidationException::withMessages([
                        'items' => ['Invalid sale line for this sale.'],
                    ]);
                }

                $returnQty = (float) $itemData['quantity'];

                $alreadyReturned = (float) SaleReturnItem::query()
                    ->where('sale_item_id', $saleItem->id)
                    ->sum('quantity');

                if ($alreadyReturned + $returnQty > (float) $saleItem->quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Return quantity exceeds sold quantity for {$saleItem->product_name}."],
                    ]);
                }

                $ratio = $returnQty / (float) $saleItem->quantity;
                $lineSubtotal = round((float) $saleItem->line_subtotal * $ratio, 2);
                $vatAmount = round((float) $saleItem->vat_amount * $ratio, 2);
                $lineTotal = round((float) $saleItem->line_total * $ratio, 2);

                $product = Product::query()
                    ->where('id', $saleItem->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

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
                    );

                    $this->lots->refreshProductStockMeta($product);
                }
            }

            $saleReturn->update([
                'subtotal' => round($subtotal, 2),
                'vat_total' => round($vatTotal, 2),
                'total' => round($total, 2),
            ]);

            $sale->update([
                'updated_by' => $user->id,
                'status' => $this->resolveStatusAfterReturn($sale),
            ]);

            return $saleReturn->load('items');
        });
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
