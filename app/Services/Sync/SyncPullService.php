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
use App\Models\ProductVariant;
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
     * @param  list<string>  $include
     * @return array<string, mixed>
     */
    public function pull(
        User $user,
        Device $device,
        Carbon $since,
        array $include = [],
        bool $includeImages = false,
    ): array {
        if ($include === []) {
            $include = [
                'settings',
                'payment_methods',
                'categories',
                'suppliers',
                'brands',
                'variation_attributes',
                'products',
                'customers',
                'stock',
            ];
        }

        $includeSet = array_fill_keys($include, true);

        return DB::transaction(function () use ($user, $device, $since, $includeSet, $includeImages) {
            $store = $this->scope->resolveStore($user);
            $storeId = $store->id;
            $tenantId = $user->tenant_id;

            $settingsRows = [];
            if (isset($includeSet['settings'])) {
                ['store' => $storeModel, 'settings' => $storeSettings] = $this->settings->resolve($user);

                if ($storeSettings->updated_at->greaterThan($since)) {
                    $settingsRows[] = SyncSettingsResource::make([
                        'store' => $storeModel,
                        'settings' => $storeSettings,
                    ])->resolve();
                }
            }

            $paymentMethods = collect();
            if (isset($includeSet['payment_methods'])) {
                $paymentMethods = PaymentMethod::query()
                    ->where('store_id', $storeId)
                    ->where('updated_at', '>', $since)
                    ->withTrashed()
                    ->orderBy('sort_order')
                    ->get();
            }

            $categories = collect();
            if (isset($includeSet['categories'])) {
                $categories = Category::query()
                    ->where('store_id', $storeId)
                    ->where('updated_at', '>', $since)
                    ->withTrashed()
                    ->orderBy('sort_order')
                    ->get();
            }

            $suppliers = collect();
            if (isset($includeSet['suppliers'])) {
                $suppliers = Supplier::query()
                    ->where('store_id', $storeId)
                    ->where('updated_at', '>', $since)
                    ->withTrashed()
                    ->orderBy('name')
                    ->get();
            }

            $brands = collect();
            if (isset($includeSet['brands'])) {
                $brands = Brand::query()
                    ->where('store_id', $storeId)
                    ->where('updated_at', '>', $since)
                    ->withTrashed()
                    ->orderBy('name')
                    ->get();
            }

            $products = collect();
            if (isset($includeSet['products'])) {
                $with = ['category', 'supplier', 'brand', 'variants.options.attribute'];
                if ($includeImages) {
                    $with[] = 'primaryImage';
                }

                $products = Product::query()
                    ->where('store_id', $storeId)
                    ->where('updated_at', '>', $since)
                    ->withTrashed()
                    ->with($with)
                    ->orderBy('name')
                    ->get();
            }

            $variationAttributes = collect();
            if (isset($includeSet['variation_attributes'])) {
                $variationAttributes = VariationAttribute::query()
                    ->where('store_id', $storeId)
                    ->where('updated_at', '>', $since)
                    ->withTrashed()
                    ->with(['values' => fn ($q) => $q->orderBy('sort_order')])
                    ->orderBy('sort_order')
                    ->get();
            }

            $customers = collect();
            if (isset($includeSet['customers'])) {
                $customers = Customer::query()
                    ->where('store_id', $storeId)
                    ->where('updated_at', '>', $since)
                    ->withTrashed()
                    ->orderBy('name')
                    ->get();
            }

            $stockRows = [];
            if (isset($includeSet['stock'])) {
                $stockRows = $this->buildStockPayload($storeId, $since);
            }

            $syncedAt = now();

            $payload = [
                'synced_at' => $syncedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            ];

            if (isset($includeSet['settings'])) {
                $payload['settings'] = $settingsRows;
            }
            if (isset($includeSet['payment_methods'])) {
                $payload['payment_methods'] = PaymentMethodResource::collection($paymentMethods)->resolve();
            }
            if (isset($includeSet['categories'])) {
                $payload['categories'] = CategoryResource::collection($categories)->resolve();
            }
            if (isset($includeSet['suppliers'])) {
                $payload['suppliers'] = SupplierResource::collection($suppliers)->resolve();
            }
            if (isset($includeSet['brands'])) {
                $payload['brands'] = BrandResource::collection($brands)->resolve();
            }
            if (isset($includeSet['variation_attributes'])) {
                $payload['variation_attributes'] = VariationAttributeResource::collection($variationAttributes)->resolve();
            }
            if (isset($includeSet['products'])) {
                $payload['products'] = ProductResource::collection($products)->resolve();
            }
            if (isset($includeSet['customers'])) {
                $payload['customers'] = CustomerResource::collection($customers)->resolve();
            }
            if (isset($includeSet['stock'])) {
                $payload['stock'] = $stockRows;
            }

            $accepted = count($settingsRows)
                + $paymentMethods->count()
                + $categories->count()
                + $suppliers->count()
                + $brands->count()
                + $variationAttributes->count()
                + $products->count()
                + $customers->count()
                + count($stockRows);

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
                    'include' => array_keys($includeSet),
                    'include_images' => $includeImages,
                    'counts' => [
                        'settings' => count($settingsRows),
                        'payment_methods' => $paymentMethods->count(),
                        'categories' => $categories->count(),
                        'suppliers' => $suppliers->count(),
                        'brands' => $brands->count(),
                        'variation_attributes' => $variationAttributes->count(),
                        'products' => $products->count(),
                        'customers' => $customers->count(),
                        'stock' => count($stockRows),
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

    /**
     * Lightweight stock deltas for warm sync (no full product trees / images).
     *
     * @return list<array<string, mixed>>
     */
    private function buildStockPayload(int $storeId, Carbon $since): array
    {
        $products = Product::query()
            ->where('store_id', $storeId)
            ->where('updated_at', '>', $since)
            ->withTrashed()
            ->orderBy('id')
            ->get(['id', 'uuid', 'store_id', 'stock_quantity', 'manage_inventory', 'has_variants', 'updated_at', 'deleted_at']);

        $rows = [];

        foreach ($products as $product) {
            $rows[] = [
                'product_uuid' => $product->uuid,
                'product_variant_uuid' => null,
                'store_id' => $product->store_id,
                'stock_quantity' => (float) $product->stock_quantity,
                'manage_inventory' => (bool) $product->manage_inventory,
                'has_variants' => (bool) $product->has_variants,
                'updated_at' => $product->updated_at?->toIso8601String(),
                'deleted_at' => $product->deleted_at?->toIso8601String(),
            ];
        }

        $variants = ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->where('store_id', $storeId))
            ->where('updated_at', '>', $since)
            ->with(['product:id,uuid,store_id,manage_inventory,has_variants'])
            ->orderBy('id')
            ->get();

        foreach ($variants as $variant) {
            $product = $variant->product;
            if ($product === null) {
                continue;
            }

            $rows[] = [
                'product_uuid' => $product->uuid,
                'product_variant_uuid' => $variant->uuid,
                'store_id' => $product->store_id,
                'stock_quantity' => (float) $variant->stock_quantity,
                'manage_inventory' => (bool) $product->manage_inventory,
                'has_variants' => (bool) $product->has_variants,
                'updated_at' => $variant->updated_at?->toIso8601String(),
                'deleted_at' => null,
            ];
        }

        return $rows;
    }
}
