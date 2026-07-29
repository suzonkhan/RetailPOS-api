<?php

namespace App\Services\Sync;

use App\Http\Resources\BrandResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\PaymentMethodResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\SupplierResource;
use App\Http\Resources\SyncSettingsResource;
use App\Http\Resources\VariationAttributeResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Device;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SyncBatch;
use App\Models\SyncLog;
use App\Models\User;
use App\Models\VariationAttribute;
use App\Services\Sales\SalesScopeService;
use App\Services\Settings\TenantSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SyncPullService
{
    public function __construct(
        private readonly SalesScopeService $scope,
        private readonly TenantSettingsService $settings,
        private readonly DeviceRegistrationService $devices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pull(User $user, Device $device, Carbon $since): array
    {
        return DB::transaction(function () use ($user, $device, $since) {
            $store = $this->scope->resolveStore($user);
            $tenantId = $user->tenant_id;

            ['store' => $storeModel, 'settings' => $storeSettings] = $this->settings->resolve($user);

            $settingsRows = [];
            if ($storeSettings->updated_at->greaterThan($since)) {
                $settingsRows[] = SyncSettingsResource::make([
                    'store' => $storeModel,
                    'settings' => $storeSettings,
                ])->resolve();
            }

            $paymentMethods = PaymentMethod::query()
                ->where('store_id', $store->id)
                ->where('updated_at', '>', $since)
                ->withTrashed()
                ->orderBy('sort_order')
                ->get();

            $categories = Category::query()
                ->where('tenant_id', $tenantId)
                ->where('updated_at', '>', $since)
                ->withTrashed()
                ->orderBy('sort_order')
                ->get();

            $suppliers = Supplier::query()
                ->where('tenant_id', $tenantId)
                ->where('updated_at', '>', $since)
                ->withTrashed()
                ->orderBy('name')
                ->get();

            $brands = Brand::query()
                ->where('tenant_id', $tenantId)
                ->where('updated_at', '>', $since)
                ->withTrashed()
                ->orderBy('name')
                ->get();

            $products = Product::query()
                ->where('tenant_id', $tenantId)
                ->where('updated_at', '>', $since)
                ->withTrashed()
                ->with(['category', 'supplier', 'brand', 'variants.options.attribute'])
                ->orderBy('name')
                ->get();

            $variationAttributes = VariationAttribute::query()
                ->where('tenant_id', $tenantId)
                ->where('updated_at', '>', $since)
                ->withTrashed()
                ->with(['values' => fn ($q) => $q->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get();

            $customers = Customer::query()
                ->where('tenant_id', $tenantId)
                ->where('updated_at', '>', $since)
                ->withTrashed()
                ->orderBy('name')
                ->get();

            $syncedAt = now();

            $payload = [
                'synced_at' => $syncedAt->utc()->format('Y-m-d\TH:i:s\Z'),
                'settings' => $settingsRows,
                'payment_methods' => PaymentMethodResource::collection($paymentMethods)->resolve(),
                'categories' => CategoryResource::collection($categories)->resolve(),
                'suppliers' => SupplierResource::collection($suppliers)->resolve(),
                'brands' => BrandResource::collection($brands)->resolve(),
                'variation_attributes' => VariationAttributeResource::collection($variationAttributes)->resolve(),
                'products' => ProductResource::collection($products)->resolve(),
                'customers' => CustomerResource::collection($customers)->resolve(),
            ];

            $accepted = count($settingsRows)
                + $paymentMethods->count()
                + $categories->count()
                + $suppliers->count()
                + $brands->count()
                + $variationAttributes->count()
                + $products->count()
                + $customers->count();

            $batch = SyncBatch::query()->create([
                'tenant_id' => $tenantId,
                'device_id' => $device->id,
                'direction' => SyncBatch::DIRECTION_PULL,
                'status' => SyncBatch::STATUS_COMPLETED,
                'accepted_count' => $accepted,
                'rejected_count' => 0,
                'ignored_count' => 0,
                'summary' => [
                    'since' => $since->toIso8601String(),
                    'counts' => [
                        'settings' => count($settingsRows),
                        'payment_methods' => $paymentMethods->count(),
                        'categories' => $categories->count(),
                        'suppliers' => $suppliers->count(),
                        'brands' => $brands->count(),
                        'variation_attributes' => $variationAttributes->count(),
                        'products' => $products->count(),
                        'customers' => $customers->count(),
                    ],
                ],
            ]);

            SyncLog::query()->create([
                'sync_batch_id' => $batch->id,
                'entity_type' => 'pull',
                'entity_key' => $since->toIso8601String(),
                'status' => SyncLog::STATUS_ACCEPTED,
                'message' => "Pulled {$accepted} records.",
            ]);

            $this->devices->touchSync($device);

            return $payload;
        });
    }
}
