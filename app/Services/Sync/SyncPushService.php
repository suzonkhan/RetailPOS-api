<?php

namespace App\Services\Sync;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Sale;
use App\Models\SyncBatch;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Customers\CustomerService;
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

            $accepted = $results['sales']['accepted'] + $results['customers']['accepted'];
            $rejected = $results['sales']['rejected'] + $results['customers']['rejected'];
            $ignored = $results['sales']['ignored'] + $results['customers']['ignored'];

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
        $result['rejected']++;
        $result['errors'][] = [
            'index' => $index,
            'client_uuid' => $key !== 'unknown' ? $key : null,
            'message' => $message,
        ];
        SyncLog::query()->create([
            'sync_batch_id' => $batch->id,
            'entity_type' => 'sales',
            'entity_key' => $key,
            'status' => SyncLog::STATUS_REJECTED,
            'message' => $message,
        ]);
    }
}
