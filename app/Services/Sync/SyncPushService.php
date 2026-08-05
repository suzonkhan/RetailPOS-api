<?php

namespace App\Services\Sync;

use App\Models\Customer;
use App\Models\Device;
use App\Models\DuePayment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SyncBatch;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Services\Sales\DuePaymentService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Services\Sales\SalesScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncPushService
{
    public function __construct(
        private readonly SalesScopeService $scope,
        private readonly SaleService $sales,
        private readonly CustomerService $customers,
        private readonly DuePaymentService $duePayments,
        private readonly SaleReturnService $saleReturns,
        private readonly SyncUuidResolver $resolver,
        private readonly DeviceRegistrationService $devices,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function push(User $user, Device $device, array $payload): array
    {
        return DB::transaction(function () use ($user, $device, $payload) {
            $entities = $payload['entities'] ?? [];
            $syncedAt = now();

            $results = [
                'sales' => $this->emptyEntityResult(),
                'customers' => $this->emptyEntityResult(),
                'due_payments' => $this->emptyEntityResult(),
                'sale_returns' => $this->emptyEntityResult(),
            ];

            $batch = SyncBatch::query()->create([
                'tenant_id' => $user->tenant_id,
                'device_id' => $device->id,
                'direction' => SyncBatch::DIRECTION_PUSH,
                'status' => SyncBatch::STATUS_COMPLETED,
            ]);

            foreach ($entities['customers'] ?? [] as $index => $customerData) {
                $this->pushCustomer($user, $batch, $results['customers'], $index, $customerData);
            }

            foreach ($entities['sales'] ?? [] as $index => $saleData) {
                $this->pushSale($user, $batch, $results['sales'], $index, $saleData);
            }

            foreach ($entities['due_payments'] ?? [] as $index => $dueData) {
                $this->pushDuePayment($user, $batch, $results['due_payments'], $index, $dueData);
            }

            foreach ($entities['sale_returns'] ?? [] as $index => $returnData) {
                $this->pushSaleReturn($user, $batch, $results['sale_returns'], $index, $returnData);
            }

            $accepted = $results['sales']['accepted']
                + $results['customers']['accepted']
                + $results['due_payments']['accepted']
                + $results['sale_returns']['accepted'];
            $rejected = $results['sales']['rejected']
                + $results['customers']['rejected']
                + $results['due_payments']['rejected']
                + $results['sale_returns']['rejected'];
            $ignored = $results['sales']['ignored']
                + $results['customers']['ignored']
                + $results['due_payments']['ignored']
                + $results['sale_returns']['ignored'];

            $batch->update([
                'accepted_count' => $accepted,
                'rejected_count' => $rejected,
                'ignored_count' => $ignored,
                'status' => $rejected > 0 ? SyncBatch::STATUS_PARTIAL : SyncBatch::STATUS_COMPLETED,
                'summary' => ['results' => $results],
            ]);

            $this->devices->touchSync($device);

            return [
                'device_id' => $device->uuid,
                'synced_at' => $syncedAt->utc()->format('Y-m-d\TH:i:s\Z'),
                'results' => $results,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $customerData
     * @param  array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}  $result
     */
    private function pushCustomer(User $user, SyncBatch $batch, array &$result, int $index, array $customerData): void
    {
        $uuid = $customerData['uuid'] ?? null;

        if ($uuid === null) {
            $this->rejectCustomer($batch, $result, $index, 'unknown', 'Customer uuid is required.');

            return;
        }

        try {
            $existing = Customer::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('uuid', $uuid)
                ->first();

            if ($existing !== null) {
                $this->scope->authorizeCustomer($user, $existing);
                $this->customers->update($existing, $customerData);
                $this->accept($batch, $result, 'customers', $uuid, SyncLog::STATUS_ACCEPTED, 'Updated.');
            } else {
                $this->customers->storeForUser($user, array_merge($customerData, [
                    'uuid' => $uuid,
                ]));

                $this->accept($batch, $result, 'customers', $uuid, SyncLog::STATUS_ACCEPTED, 'Created.');
            }
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Validation failed.';
            $this->rejectCustomer($batch, $result, $index, $uuid, $message);
        }
    }

    /**
     * @param  array<string, mixed>  $saleData
     * @param  array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}  $result
     */
    private function pushSale(User $user, SyncBatch $batch, array &$result, int $index, array $saleData): void
    {
        $clientUuid = $saleData['client_uuid'] ?? null;

        if ($clientUuid === null) {
            $this->rejectSale($batch, $result, $index, 'unknown', 'Sale client_uuid is required.');

            return;
        }

        try {
            $existing = Sale::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('client_uuid', $clientUuid)
                ->first();

            if ($existing !== null) {
                $this->ignore($batch, $result, 'sales', $clientUuid, 'Duplicate client_uuid (idempotent).');

                return;
            }

            $store = $this->scope->resolveStore($user);
            $mapped = $this->mapSalePayload($store, $saleData);

            $outcome = $this->sales->createForUser($user, $mapped);

            if ($outcome['created']) {
                $this->accept($batch, $result, 'sales', $clientUuid, SyncLog::STATUS_ACCEPTED, 'Created.');
            } else {
                $this->ignore($batch, $result, 'sales', $clientUuid, 'Duplicate client_uuid (idempotent).');
            }
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Validation failed.';
            $this->rejectSale($batch, $result, $index, $clientUuid, $message);
        }
    }

    /**
     * @param  array<string, mixed>  $dueData
     * @param  array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}  $result
     */
    private function pushDuePayment(User $user, SyncBatch $batch, array &$result, int $index, array $dueData): void
    {
        $uuid = $dueData['uuid'] ?? $dueData['client_uuid'] ?? null;

        if ($uuid === null) {
            $this->rejectEntity($batch, $result, $index, 'due_payments', 'unknown', 'uuid', 'Due payment uuid is required.');

            return;
        }

        try {
            $existing = DuePayment::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('uuid', $uuid)
                ->first();

            if ($existing !== null) {
                $this->ignore($batch, $result, 'due_payments', $uuid, 'Duplicate uuid (idempotent).');

                return;
            }

            $store = $this->scope->resolveStore($user);
            $customerId = $this->resolver->resolveCustomerId($store, [
                'customer_id' => $dueData['customer_id'] ?? null,
                'customer_uuid' => $dueData['customer_uuid'] ?? null,
            ]);

            if ($customerId === null) {
                throw ValidationException::withMessages([
                    'customer_uuid' => ['Customer is required.'],
                ]);
            }

            $customer = Customer::query()->whereKey($customerId)->firstOrFail();

            $payload = [
                'amount' => $dueData['amount'],
                'reference' => $dueData['reference'] ?? null,
                'customer_due_id' => $dueData['customer_due_id'] ?? null,
            ];

            if (! empty($dueData['payment_method_id']) || ! empty($dueData['payment_method_uuid'])) {
                $payload['payment_method_id'] = $this->resolver->resolvePaymentMethodId($store, $dueData);
            }

            $payment = $this->duePayments->recordForCustomer($user, $customer, $payload);
            $payment->uuid = $uuid;
            $payment->save();

            $this->accept($batch, $result, 'due_payments', $uuid, SyncLog::STATUS_ACCEPTED, 'Created.');
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Validation failed.';
            $this->rejectEntity($batch, $result, $index, 'due_payments', $uuid, 'uuid', $message);
        }
    }

    /**
     * @param  array<string, mixed>  $returnData
     * @param  array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}  $result
     */
    private function pushSaleReturn(User $user, SyncBatch $batch, array &$result, int $index, array $returnData): void
    {
        $uuid = $returnData['uuid'] ?? $returnData['client_uuid'] ?? null;

        if ($uuid === null) {
            $this->rejectEntity($batch, $result, $index, 'sale_returns', 'unknown', 'uuid', 'Sale return uuid is required.');

            return;
        }

        try {
            $existing = SaleReturn::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('uuid', $uuid)
                ->first();

            if ($existing !== null) {
                $this->ignore($batch, $result, 'sale_returns', $uuid, 'Duplicate uuid (idempotent).');

                return;
            }

            $store = $this->scope->resolveStore($user);
            $sale = $this->resolveSaleForReturn($store->id, $user->tenant_id, $returnData);

            $items = [];
            foreach ($returnData['items'] ?? [] as $item) {
                if (! empty($item['sale_item_id'])) {
                    $items[] = [
                        'sale_item_id' => (int) $item['sale_item_id'],
                        'quantity' => $item['quantity'],
                    ];

                    continue;
                }

                $saleItem = $this->resolveSaleItem($sale, $store->id, $item);
                $items[] = [
                    'sale_item_id' => $saleItem->id,
                    'quantity' => $item['quantity'],
                ];
            }

            $saleReturn = $this->saleReturns->createForSale($user, $sale, [
                'notes' => $returnData['notes'] ?? null,
                'items' => $items,
            ]);
            $saleReturn->uuid = $uuid;
            $saleReturn->save();

            $this->accept($batch, $result, 'sale_returns', $uuid, SyncLog::STATUS_ACCEPTED, 'Created.');
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Validation failed.';
            $this->rejectEntity($batch, $result, $index, 'sale_returns', $uuid, 'uuid', $message);
        }
    }

    /**
     * @param  array<string, mixed>  $returnData
     */
    private function resolveSaleForReturn(int $storeId, int $tenantId, array $returnData): Sale
    {
        $query = Sale::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId);

        if (! empty($returnData['sale_id'])) {
            $sale = (clone $query)->whereKey($returnData['sale_id'])->first();
        } elseif (! empty($returnData['sale_uuid'])) {
            $sale = (clone $query)->where('uuid', $returnData['sale_uuid'])->first();
        } elseif (! empty($returnData['sale_client_uuid'])) {
            $sale = (clone $query)->where('client_uuid', $returnData['sale_client_uuid'])->first();
        } else {
            throw ValidationException::withMessages([
                'sale_uuid' => ['Sale reference is required (sale_id, sale_uuid, or sale_client_uuid).'],
            ]);
        }

        if ($sale === null) {
            throw ValidationException::withMessages([
                'sale_uuid' => ['Sale not found for this store.'],
            ]);
        }

        return $sale;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveSaleItem(Sale $sale, int $storeId, array $item): SaleItem
    {
        $productId = $this->resolver->resolveProductId(
            \App\Models\Store::query()->findOrFail($storeId),
            $item
        );

        $query = SaleItem::query()
            ->where('sale_id', $sale->id)
            ->where('product_id', $productId);

        if (! empty($item['product_variant_id']) || ! empty($item['product_variant_uuid'])) {
            $variantId = $this->resolver->resolveProductVariantId(
                \App\Models\Store::query()->findOrFail($storeId),
                $item,
                $productId
            );
            $query->where('product_variant_id', $variantId);
        }

        $saleItem = $query->first();

        if ($saleItem === null) {
            throw ValidationException::withMessages([
                'items' => ['Sale line not found for product on this sale.'],
            ]);
        }

        return $saleItem;
    }

    /**
     * @param  array<string, mixed>  $saleData
     * @return array<string, mixed>
     */
    private function mapSalePayload($store, array $saleData): array
    {
        $items = [];
        foreach ($saleData['items'] as $item) {
            $productId = $this->resolver->resolveProductId($store, $item);
            $mapped = [
                'product_id' => $productId,
                'quantity' => $item['quantity'],
            ];

            $variantId = $this->resolver->resolveProductVariantId($store, $item, $productId);
            if ($variantId !== null) {
                $mapped['product_variant_id'] = $variantId;
            }

            if (array_key_exists('unit_price', $item)) {
                $mapped['unit_price'] = $item['unit_price'];
            }

            $items[] = $mapped;
        }

        $payments = [];
        foreach ($saleData['payments'] as $payment) {
            $payments[] = [
                'payment_method_id' => $this->resolver->resolvePaymentMethodId($store, $payment),
                'amount' => $payment['amount'],
                'reference' => $payment['reference'] ?? null,
            ];
        }

        return [
            'client_uuid' => $saleData['client_uuid'],
            'customer_id' => $this->resolver->resolveCustomerId($store, $saleData),
            'items' => $items,
            'payments' => $payments,
            'change_amount' => $saleData['change_amount'] ?? 0,
            'discount_amount' => $saleData['discount_amount'] ?? 0,
        ];
    }

    /**
     * @return array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}
     */
    private function emptyEntityResult(): array
    {
        return [
            'accepted' => 0,
            'rejected' => 0,
            'ignored' => 0,
            'errors' => [],
        ];
    }

    /**
     * @param  array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}  $result
     */
    private function accept(SyncBatch $batch, array &$result, string $entityType, string $key, string $status, string $message): void
    {
        $result['accepted']++;
        SyncLog::query()->create([
            'sync_batch_id' => $batch->id,
            'entity_type' => $entityType,
            'entity_key' => $key,
            'status' => $status,
            'message' => $message,
        ]);
    }

    /**
     * @param  array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}  $result
     */
    private function ignore(SyncBatch $batch, array &$result, string $entityType, string $key, string $message): void
    {
        $result['ignored']++;
        SyncLog::query()->create([
            'sync_batch_id' => $batch->id,
            'entity_type' => $entityType,
            'entity_key' => $key,
            'status' => SyncLog::STATUS_IGNORED,
            'message' => $message,
        ]);
    }

    /**
     * @param  array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}  $result
     */
    private function rejectCustomer(SyncBatch $batch, array &$result, int $index, string $key, string $message): void
    {
        $result['rejected']++;
        $result['errors'][] = [
            'index' => $index,
            'uuid' => $key !== 'unknown' ? $key : null,
            'message' => $message,
        ];
        SyncLog::query()->create([
            'sync_batch_id' => $batch->id,
            'entity_type' => 'customers',
            'entity_key' => $key,
            'status' => SyncLog::STATUS_REJECTED,
            'message' => $message,
        ]);
    }

    /**
     * @param  array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}  $result
     */
    private function rejectSale(SyncBatch $batch, array &$result, int $index, string $key, string $message): void
    {
        $this->rejectEntity($batch, $result, $index, 'sales', $key, 'client_uuid', $message);
    }

    /**
     * @param  array{accepted: int, rejected: int, ignored: int, errors: array<int, array<string, mixed>>}  $result
     */
    private function rejectEntity(
        SyncBatch $batch,
        array &$result,
        int $index,
        string $entityType,
        string $key,
        string $keyField,
        string $message,
    ): void {
        $result['rejected']++;
        $error = [
            'index' => $index,
            'message' => $message,
        ];
        if ($key !== 'unknown') {
            $error[$keyField] = $key;
        }
        $result['errors'][] = $error;
        SyncLog::query()->create([
            'sync_batch_id' => $batch->id,
            'entity_type' => $entityType,
            'entity_key' => $key,
            'status' => SyncLog::STATUS_REJECTED,
            'message' => $message,
        ]);
    }
}
