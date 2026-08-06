<?php

namespace App\Services\Sales;

use App\Models\CustomerDue;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\LotService;
use App\Services\Catalog\ProductVariantService;
use App\Support\MobileNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private readonly SalesScopeService $scope,
        private readonly VatLineCalculator $vatCalculator,
        private readonly StockMovementService $stockMovement,
        private readonly LotService $lots,
        private readonly ProductVariantService $variantService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForUser(User $user, array $data): array
    {
        $store = $this->scope->resolveStore($user);
        $clientUuid = $data['client_uuid'];

        return DB::transaction(function () use ($user, $store, $data, $clientUuid) {
            $existing = Sale::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('client_uuid', $clientUuid)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'sale' => $existing->load(['items', 'payments.paymentMethod', 'customer', 'user', 'updatedBy']),
                    'created' => false,
                ];
            }

            $sale = $this->createSale($user, $store, $data);

            return [
                'sale' => $sale->load(['items', 'payments.paymentMethod', 'customer', 'user', 'updatedBy']),
                'created' => true,
            ];
        });
    }

    public function listForUser(User $user, array $filters)
    {
        $store = $this->scope->resolveStore($user);

        $query = Sale::query()
            ->where('store_id', $store->id)
            ->with(['customer', 'user', 'updatedBy']);

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        $orderId = $filters['order_id'] ?? $filters['id'] ?? null;
        if ($orderId !== null && $orderId !== '') {
            $query->where('order_number', (int) $orderId);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['status'])) {
            $status = (string) $filters['status'];
            $allowed = [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
                Sale::STATUS_RETURNED,
            ];
            if (in_array($status, $allowed, true)) {
                $query->where('status', $status);
            }
        }

        if (! empty($filters['customer_mobile'])) {
            $raw = trim((string) $filters['customer_mobile']);
            $digits = preg_replace('/\D/', '', $raw) ?? '';

            if ($digits !== '') {
                $normalized = MobileNormalizer::normalize($raw);

                $query->whereHas('customer', function ($q) use ($normalized, $digits) {
                    $q->where('mobile', $normalized)
                        ->orWhere('mobile', 'like', "%{$digits}%");
                });
            }
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', \App\Support\AppTimezone::startOfDay($filters['from']));
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', \App\Support\AppTimezone::endOfDay($filters['to']));
        }

        $payment = $filters['payment'] ?? null;
        if ($payment === 'due') {
            $query->whereHas('dues', function ($q) {
                $q->where('status', CustomerDue::STATUS_OPEN)
                    ->where('balance', '>', 0);
            });
        } elseif ($payment === 'paid') {
            $query->whereDoesntHave('dues', function ($q) {
                $q->where('status', CustomerDue::STATUS_OPEN)
                    ->where('balance', '>', 0);
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createSale(User $user, $store, array $data): Sale
    {
        $settings = StoreSetting::query()->where('store_id', $store->id)->first();
        $customerId = $data['customer_id'] ?? null;

        if (! $this->scope->customerBelongsToStore($customerId, $store)) {
            throw ValidationException::withMessages([
                'customer_id' => ['Customer is invalid for this store.'],
            ]);
        }

        $linePayloads = [];
        $subtotal = 0.0;
        $vatTotal = 0.0;
        $total = 0.0;

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

            if ($product->manage_inventory) {
                $this->lots->ensureOpeningLotForProduct($product, $variant);
                $allocations = $this->lots->allocateFifo($product, $quantity, true, $variant);
                $unitCost = $this->lots->blendedUnitCost($allocations);
            } else {
                $allocations = [];
                $unitCost = $variant !== null
                    ? ($variant->resolvedCostPrice() ?? 0.0)
                    : (float) ($product->cost_price ?? 0);
            }

            $defaultPrice = $variant !== null
                ? $variant->resolvedSellingPrice()
                : (float) $product->selling_price;

            $unitPrice = array_key_exists('unit_price', $itemData)
                ? (float) $itemData['unit_price']
                : $defaultPrice;

            if (array_key_exists('unit_price', $itemData)
                && abs($unitPrice - $defaultPrice) > 0.01
                && ! $product->is_negotiable
            ) {
                throw ValidationException::withMessages([
                    'items' => ["Unit price cannot be changed for \"{$product->name}\" because it is not negotiable."],
                ]);
            }

            $costFloor = $variant !== null
                ? $variant->resolvedCostPrice()
                : ($product->cost_price !== null ? (float) $product->cost_price : null);

            if ($costFloor !== null && $unitPrice < $costFloor) {
                throw ValidationException::withMessages([
                    'items' => ["Unit price for \"{$product->name}\" cannot be below cost price."],
                ]);
            }

            $line = $this->vatCalculator->calculate($product, $quantity, $unitPrice, $settings);

            $linePayloads[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'allocations' => $allocations,
                'line' => $line,
            ];

            $subtotal += $line['line_subtotal'];
            $vatTotal += $line['vat_amount'];
            $total += $line['line_total'];
        }

        $subtotal = round($subtotal, 2);
        $vatTotal = round($vatTotal, 2);
        $total = round($total, 2);

        $grossTotal = $total;
        $discountAmount = round((float) ($data['discount_amount'] ?? 0), 2);

        if ($discountAmount > $grossTotal + 0.01) {
            throw ValidationException::withMessages([
                'discount_amount' => ["Discount ({$discountAmount}) cannot exceed sale total ({$grossTotal})."],
            ]);
        }

        $total = round(max(0, $grossTotal - $discountAmount), 2);

        $paymentsTotal = round(collect($data['payments'])->sum('amount'), 2);

        if (abs($paymentsTotal - $total) > 0.01) {
            throw ValidationException::withMessages([
                'payments' => ["Payment total ({$paymentsTotal}) must equal sale total ({$total})."],
            ]);
        }

        $paymentMethodIds = collect($data['payments'])->pluck('payment_method_id')->unique()->all();

        $paymentMethods = PaymentMethod::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $paymentMethodIds)
            ->get()
            ->keyBy('id');

        if ($paymentMethods->count() !== count($paymentMethodIds)) {
            throw ValidationException::withMessages([
                'payments' => ['One or more payment methods are invalid for this store.'],
            ]);
        }

        foreach ($data['payments'] as $paymentData) {
            $method = $paymentMethods->get($paymentData['payment_method_id']);

            if ($method->is_credit && $customerId === null) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Customer is required when using a credit/due payment method.'],
                ]);
            }

            if ($method->requires_reference && empty($paymentData['reference'])) {
                throw ValidationException::withMessages([
                    'payments' => ["Reference is required for payment method: {$method->name}."],
                ]);
            }
        }

        $sale = Sale::query()->create([
            'client_uuid' => $data['client_uuid'],
            'order_number' => $this->nextOrderNumber($user->tenant_id),
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'customer_id' => $customerId,
            'user_id' => $user->id,
            'updated_by' => $user->id,
            'subtotal' => $subtotal,
            'vat_total' => $vatTotal,
            'discount_amount' => $discountAmount,
            'total' => $total,
            'change_amount' => round((float) ($data['change_amount'] ?? 0), 2),
            'status' => Sale::STATUS_COMPLETED,
        ]);

        foreach ($linePayloads as $payload) {
            /** @var Product $product */
            $product = $payload['product'];
            /** @var ProductVariant|null $variant */
            $variant = $payload['variant'];
            $line = $payload['line'];

            $saleItem = SaleItem::query()->create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'product_sku' => $variant?->sku ?? $product->sku,
                'variant_label' => $variant?->buildLabel(),
                'quantity' => $payload['quantity'],
                'unit_price' => $payload['unit_price'],
                'unit_cost' => $payload['unit_cost'],
                'line_subtotal' => $line['line_subtotal'],
                'vat_rate' => $line['vat_rate'],
                'vat_type' => $line['vat_type'],
                'vat_amount' => $line['vat_amount'],
                'line_total' => $line['line_total'],
            ]);

            $this->lots->persistSaleAllocations($saleItem, $payload['allocations']);

            if ($product->manage_inventory) {
                $this->stockMovement->adjust(
                    $store,
                    $product,
                    -$payload['quantity'],
                    StockMovement::TYPE_SALE,
                    Sale::class,
                    $sale->id,
                    $variant,
                );

                $this->lots->refreshProductStockMeta($product, $variant);
            }
        }

        foreach ($data['payments'] as $paymentData) {
            $method = $paymentMethods->get($paymentData['payment_method_id']);

            $salePayment = SalePayment::query()->create([
                'sale_id' => $sale->id,
                'payment_method_id' => $method->id,
                'amount' => $paymentData['amount'],
                'reference' => $paymentData['reference'] ?? null,
            ]);

            if ($method->is_credit) {
                CustomerDue::query()->create([
                    'tenant_id' => $user->tenant_id,
                    'store_id' => $store->id,
                    'customer_id' => $customerId,
                    'sale_id' => $sale->id,
                    'sale_payment_id' => $salePayment->id,
                    'amount' => $paymentData['amount'],
                    'balance' => $paymentData['amount'],
                    'status' => CustomerDue::STATUS_OPEN,
                ]);
            }
        }

        return $sale;
    }

    private function nextOrderNumber(int $tenantId): int
    {
        $tenant = Tenant::query()
            ->whereKey($tenantId)
            ->lockForUpdate()
            ->firstOrFail();

        $next = $tenant->last_order_number + 1;
        $tenant->update(['last_order_number' => $next]);

        return $next;
    }
}
