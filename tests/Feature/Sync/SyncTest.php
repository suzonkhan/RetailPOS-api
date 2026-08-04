<?php

namespace Tests\Feature\Sync;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PlanSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Sync Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345940',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345940')->firstOrFail();
        $this->deviceId = (string) Str::uuid();
    }

    public function test_sync_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/sync/pull?since=2020-01-01T00:00:00Z&device_id='.$this->deviceId)
            ->assertUnauthorized();

        $this->postJson('/api/v1/sync/push', [
            'device_id' => $this->deviceId,
            'entities' => ['sales' => [], 'customers' => []],
        ])->assertUnauthorized();
    }

    public function test_pull_returns_changes_since_cursor_with_tombstones(): void
    {
        Sanctum::actingAs($this->owner);

        $since = Carbon::parse('2020-01-01T00:00:00Z');

        $this->postJson('/api/v1/devices', [
            'device_id' => $this->deviceId,
            'name' => 'POS Tablet',
        ])->assertCreated();

        Carbon::setTestNow('2026-06-01T10:00:00Z');

        $pullBefore = $this->getJson('/api/v1/sync/pull?'.$this->pullQuery($since))
            ->assertOk()
            ->assertJsonStructure(['synced_at', 'settings', 'payment_methods', 'products']);

        $syncedAt = $pullBefore->json('synced_at');

        Carbon::setTestNow('2026-06-01T10:00:05Z');

        $categoryId = $this->createCategory();
        $product = $this->postJson('/api/v1/products', [
            'name' => 'Sync Product',
            'category_id' => $categoryId,
            'selling_price' => 50,
            'uom' => 'pcs',
            'manage_inventory' => true,
        ])->assertCreated();

        $productId = $product->json('id');
        $productUuid = $product->json('uuid');

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $productId,
            'quantity_delta' => 5,
            'unit_cost' => 0,
            'reason' => 'Test seed',
        ])->assertCreated();

        $paymentMethod = $this->postJson('/api/v1/payment-methods', [
            'name' => 'Sync Cash',
        ])->assertCreated();

        $paymentMethodUuid = $paymentMethod->json('uuid');

        $pullAfter = $this->getJson('/api/v1/sync/pull?'.$this->pullQuery(Carbon::parse($syncedAt)))
            ->assertOk();

        $this->assertNotEmpty($pullAfter->json('products'));
        $this->assertNotEmpty($pullAfter->json('payment_methods'));

        $this->deleteJson("/api/v1/products/{$productId}")->assertOk();
        $paymentMethodId = $paymentMethod->json('id');
        $this->deleteJson("/api/v1/payment-methods/{$paymentMethodId}")->assertOk();

        $pullTombstones = $this->getJson('/api/v1/sync/pull?'.$this->pullQuery(Carbon::parse($syncedAt)))
            ->assertOk();

        $deletedProduct = collect($pullTombstones->json('products'))
            ->firstWhere('uuid', $productUuid);
        $deletedMethod = collect($pullTombstones->json('payment_methods'))
            ->firstWhere('uuid', $paymentMethodUuid);

        $this->assertNotNull($deletedProduct['deleted_at'] ?? null);
        $this->assertNotNull($deletedMethod['deleted_at'] ?? null);

        Carbon::setTestNow();
    }

    public function test_push_sale_with_new_client_uuid_and_idempotent_replay(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $product = $this->postJson('/api/v1/products', [
            'name' => 'Push Product',
            'category_id' => $categoryId,
            'selling_price' => 25,
            'uom' => 'pcs',
            'manage_inventory' => true,
        ])->assertCreated();

        $productUuid = $product->json('uuid');
        $productId = $product->json('id');

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $productId,
            'quantity_delta' => 10,
            'unit_cost' => 0,
            'reason' => 'Test seed',
        ])->assertCreated();

        $cash = PaymentMethod::query()
            ->where('tenant_id', $this->owner->tenant_id)
            ->where('name', 'Cash')
            ->first();

        if ($cash === null) {
            $cash = $this->postJson('/api/v1/payment-methods', ['name' => 'Cash Push'])->assertCreated();
            $cashUuid = $cash->json('uuid');
        } else {
            $cashUuid = $cash->uuid;
        }

        $clientUuid = (string) Str::uuid();

        $payload = [
            'device_id' => $this->deviceId,
            'device_name' => 'Auto Registered',
            'entities' => [
                'sales' => [
                    [
                        'client_uuid' => $clientUuid,
                        'items' => [
                            ['product_uuid' => $productUuid, 'quantity' => 1],
                        ],
                        'payments' => [
                            ['payment_method_uuid' => $cashUuid, 'amount' => 25],
                        ],
                    ],
                ],
                'customers' => [],
            ],
        ];

        $this->postJson('/api/v1/sync/push', $payload)
            ->assertOk()
            ->assertJsonPath('results.sales.accepted', 1)
            ->assertJsonPath('results.sales.ignored', 0);

        $this->assertDatabaseHas('devices', [
            'uuid' => $this->deviceId,
            'tenant_id' => $this->owner->tenant_id,
        ]);

        $this->assertEquals(1, Sale::query()->where('client_uuid', $clientUuid)->count());
        $this->assertEquals(9, (float) Product::query()->where('uuid', $productUuid)->first()->stock_quantity);

        $this->postJson('/api/v1/sync/push', $payload)
            ->assertOk()
            ->assertJsonPath('results.sales.accepted', 0)
            ->assertJsonPath('results.sales.ignored', 1);

        $this->assertEquals(1, Sale::query()->where('client_uuid', $clientUuid)->count());
        $this->assertEquals(1, StockMovement::query()->where('type', 'sale')->count());
    }

    public function test_push_customer_create_and_update_by_uuid(): void
    {
        Sanctum::actingAs($this->owner);

        $customerUuid = (string) Str::uuid();

        $this->postJson('/api/v1/sync/push', [
            'device_id' => $this->deviceId,
            'entities' => [
                'customers' => [
                    [
                        'uuid' => $customerUuid,
                        'name' => 'Sync Customer',
                        'mobile' => '01712345679',
                    ],
                ],
                'sales' => [],
            ],
        ])->assertOk()
            ->assertJsonPath('results.customers.accepted', 1);

        $this->assertDatabaseHas('customers', [
            'uuid' => $customerUuid,
            'name' => 'Sync Customer',
            'mobile' => '8801712345679',
        ]);

        $this->postJson('/api/v1/sync/push', [
            'device_id' => $this->deviceId,
            'entities' => [
                'customers' => [
                    [
                        'uuid' => $customerUuid,
                        'name' => 'Sync Customer Updated',
                    ],
                ],
                'sales' => [],
            ],
        ])->assertOk()
            ->assertJsonPath('results.customers.accepted', 1);

        $this->assertDatabaseHas('customers', [
            'uuid' => $customerUuid,
            'name' => 'Sync Customer Updated',
        ]);
    }

    public function test_pull_requires_registered_device(): void
    {
        Sanctum::actingAs($this->owner);

        $unknownDevice = (string) Str::uuid();

        $this->getJson('/api/v1/sync/pull?since=2020-01-01T00:00:00Z&device_id='.$unknownDevice)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id']);
    }

    public function test_push_rejects_foreign_tenant_product_uuid(): void
    {
        Sanctum::actingAs($this->owner);

        $otherOwner = $this->registerOtherOwner('8801712345941');
        Sanctum::actingAs($otherOwner);

        $categoryId = $this->createCategoryAs($otherOwner);
        $otherProductUuid = $this->postJson('/api/v1/products', [
            'name' => 'Other Tenant Product',
            'category_id' => $categoryId,
            'uom' => 'pcs',
        ])->assertCreated()->json('uuid');

        Sanctum::actingAs($this->owner);

        $cash = PaymentMethod::query()
            ->where('tenant_id', $this->owner->tenant_id)
            ->first();

        $this->postJson('/api/v1/sync/push', [
            'device_id' => $this->deviceId,
            'entities' => [
                'sales' => [
                    [
                        'client_uuid' => (string) Str::uuid(),
                        'items' => [
                            ['product_uuid' => $otherProductUuid, 'quantity' => 1],
                        ],
                        'payments' => [
                            ['payment_method_uuid' => $cash->uuid, 'amount' => 10],
                        ],
                    ],
                ],
                'customers' => [],
            ],
        ])->assertOk()
            ->assertJsonPath('results.sales.rejected', 1)
            ->assertJsonPath('results.sales.errors.0.message', 'Product not found for this store.');
    }

    public function test_staff_can_sync(): void
    {
        $staff = $this->createTenantUser('staff');
        Sanctum::actingAs($staff);

        $deviceId = (string) Str::uuid();

        $this->postJson('/api/v1/devices', [
            'device_id' => $deviceId,
            'name' => 'Staff Device',
        ])->assertCreated();

        $this->getJson('/api/v1/sync/pull?since=2020-01-01T00:00:00Z&device_id='.$deviceId)
            ->assertOk()
            ->assertJsonStructure(['synced_at', 'settings']);
    }

    private function pullQuery(Carbon $since): string
    {
        return http_build_query([
            'since' => $since->utc()->format('Y-m-d\TH:i:s\Z'),
            'device_id' => $this->deviceId,
        ]);
    }

    private function createCategory(): int
    {
        return $this->createCategoryAs($this->owner);
    }

    private function createCategoryAs(User $user): int
    {
        Sanctum::actingAs($user);

        return (int) $this->postJson('/api/v1/categories', ['name' => 'Cat '.Str::random(4)])
            ->assertCreated()
            ->json('id');
    }

    private function createTenantUser(string $role): User
    {
        $mobile = $role === 'staff' ? '8801712345942' : '8801712345998';

        return $this->createBranchUser($this->owner, $role, $mobile);
    }

    private function registerOtherOwner(string $mobile): User
    {
        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Other Sync Shop',
            'owner_name' => 'Other',
            'mobile' => $mobile,
            'pin' => '123456',
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }
}
