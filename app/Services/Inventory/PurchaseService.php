<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Catalog\CatalogScopeService;
use App\Services\Catalog\ProductVariantService;
use App\Services\Sales\StockMovementService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly LotService $lots,
        private readonly StockMovementService $stockMovement,
        private readonly ProductVariantService $variantService,
    ) {}

    public function listForUser(User $user, array $filters): LengthAwarePaginator
    {
        $store = $this->catalogScope->resolveStore($user);

        $query = Purchase::query()
            ->where('store_id', $store->id)
            ->with(['supplier', 'creator', 'items']);

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('purchased_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('purchased_at', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('purchase_number', 'like', $term)
                    ->orWhere('notes', 'like', $term);
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForUser(User $user, array $data): Purchase
    {
        $store = $this->catalogScope->resolveStore($user);

        return DB::transaction(function () use ($user, $store, $data) {
            $supplierId = $data['supplier_id'] ?? null;

            if ($supplierId !== null) {
                $supplierExists = Supplier::query()
                    ->where('store_id', $store->id)
                    ->where('id', $supplierId)
                    ->exists();

                if (! $supplierExists) {
                    throw ValidationException::withMessages([
                        'supplier_id' => ['Vendor is invalid for this store.'],
                    ]);
                }
            }

            $purchasedAt = isset($data['purchased_at'])
                ? Carbon::parse($data['purchased_at'])
                : now();

            $subtotal = 0.0;
            $linePayloads = [];

            foreach ($data['items'] as $itemData) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->where('id', $itemData['product_id'])
                    ->lockForUpdate()
                    ->first();

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more products are invalid for this store.'],
                    ]);
                }

                $variant = null;
                if ($product->has_variants) {
                    $variantId = $itemData['product_variant_id'] ?? null;
                    if ($variantId === null) {
                        throw ValidationException::withMessages([
                            'items' => ["Variant is required for \"{$product->name}\"."],
                        ]);
                    }
                    $variant = $this->variantService->resolveVariantForSale($product, (int) $variantId);
                } elseif (! empty($itemData['product_variant_id'])) {
                    throw ValidationException::withMessages([
                        'items' => ["Product \"{$product->name}\" does not use variants."],
                    ]);
                }

                $quantity = (float) $itemData['quantity'];
                $unitCost = (float) $itemData['unit_cost'];
                $lineTotal = round($quantity * $unitCost, 2);
                $subtotal += $lineTotal;

                $linePayloads[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'expiration_date' => $itemData['expiration_date'] ?? null,
                ];
            }

            $subtotal = round($subtotal, 2);

            $purchase = Purchase::query()->create([
                'tenant_id' => $user->tenant_id,
                'store_id' => $store->id,
                'supplier_id' => $supplierId,
                'purchase_number' => $this->nextPurchaseNumber($store->id),
                'purchased_at' => $purchasedAt,
                'notes' => $data['notes'] ?? null,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'created_by' => $user->id,
            ]);

            foreach ($linePayloads as $payload) {
                /** @var Product $product */
                $product = $payload['product'];
                /** @var ProductVariant|null $variant */
                $variant = $payload['variant'];

                $item = PurchaseItem::query()->create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'product_sku' => $variant?->sku ?? $product->sku,
                    'variant_label' => $variant?->buildLabel(),
                    'quantity' => $payload['quantity'],
                    'unit_cost' => $payload['unit_cost'],
                    'line_total' => $payload['line_total'],
                    'expiration_date' => $payload['expiration_date'],
                ]);

                $this->lots->createLot(
                    $store,
                    $product,
                    $payload['quantity'],
                    $payload['unit_cost'],
                    $purchasedAt,
                    $payload['expiration_date'],
                    $item,
                    $variant,
                );

                $this->stockMovement->adjust(
                    $store,
                    $product,
                    $payload['quantity'],
                    StockMovement::TYPE_PURCHASE,
                    Purchase::class,
                    $purchase->id,
                    $variant,
                );

                if ($variant !== null) {
                    $variant->cost_price = $payload['unit_cost'];
                    $variant->save();
                } else {
                    $product->cost_price = $payload['unit_cost'];
                    $product->save();
                }

                $this->lots->refreshProductStockMeta($product, $variant);
            }

            return $purchase->load(['supplier', 'creator', 'items.product']);
        });
    }

    public function findForUser(User $user, Purchase $purchase): Purchase
    {
        $store = $this->catalogScope->resolveStore($user);

        if ((int) $purchase->store_id !== (int) $store->id) {
            abort(404);
        }

        return $purchase->load(['supplier', 'creator', 'items.product']);
    }

    private function nextPurchaseNumber(int $storeId): string
    {
        $last = Purchase::query()
            ->where('store_id', $storeId)
            ->withTrashed()
            ->orderByDesc('id')
            ->value('purchase_number');

        $next = 1;

        if ($last !== null && preg_match('/(\d+)$/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return 'PUR-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
