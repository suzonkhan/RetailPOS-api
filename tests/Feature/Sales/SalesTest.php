<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\CustomerDue;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\StoreSetting;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PlanSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Sales Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345920',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345920')->firstOrFail();
    }

    public function test_owner_can_crud_customers_and_tenant_isolation(): void
    {
        Sanctum::actingAs($this->owner);

        $customer = $this->postJson('/api/v1/customers', [
            'name' => 'Rahim Ahmed',
            'mobile' => '01712345678',
        ])->assertCreated()
            ->assertJsonPath('name', 'Rahim Ahmed')
            ->assertJsonPath('mobile', '8801712345678');

        $customerId = $customer->json('id');

        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.open_due_balance', 0);

        $this->getJson("/api/v1/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('uuid', $customer->json('uuid'))
            ->assertJsonPath('open_due_balance', 0);

        $this->putJson("/api/v1/customers/{$customerId}", [
            'name' => 'Rahim Uddin',
        ])->assertOk()
            ->assertJsonPath('name', 'Rahim Uddin');

        $otherOwner = $this->registerOtherOwner('8801712345921');

        Sanctum::actingAs($otherOwner);

        $this->getJson("/api/v1/customers/{$customerId}")->assertNotFound();

        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/customers/{$customerId}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $customerId]);
    }

    public function test_creating_customer_restores_soft_deleted_mobile(): void
    {
        Sanctum::actingAs($this->owner);

        $customerId = $this->createCustomer([
            'name' => 'Old Customer',
            'mobile' => '01914758170',
        ]);

        $this->deleteJson("/api/v1/customers/{$customerId}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $customerId]);

        $restored = $this->postJson('/api/v1/customers', [
            'name' => 'Customer 8170',
            'mobile' => '01914758170',
        ])->assertCreated()
            ->assertJsonPath('id', $customerId)
            ->assertJsonPath('name', 'Customer 8170')
            ->assertJsonPath('mobile', '8801914758170');

        $this->assertNull($restored->json('deleted_at'));
        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'mobile' => '8801914758170',
            'deleted_at' => null,
        ]);
    }

    public function test_same_customer_mobile_is_allowed_across_shops(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/customers', [
            'name' => 'Shop A Customer',
            'mobile' => '01914758170',
        ])->assertCreated()
            ->assertJsonPath('mobile', '8801914758170');

        $otherOwner = $this->registerOtherOwner('8801712345924');

        Sanctum::actingAs($otherOwner);

        $this->postJson('/api/v1/customers', [
            'name' => 'Shop B Customer',
            'mobile' => '01914758170',
        ])->assertCreated()
            ->assertJsonPath('mobile', '8801914758170')
            ->assertJsonPath('name', 'Shop B Customer');

        $this->assertEquals(2, Customer::withTrashed()->where('mobile', '8801914758170')->count());
        $this->assertEquals(
            1,
            Customer::query()->where('tenant_id', $this->owner->tenant_id)->where('mobile', '8801914758170')->count()
        );
        $this->assertEquals(
            1,
            Customer::query()->where('tenant_id', $otherOwner->tenant_id)->where('mobile', '8801914758170')->count()
        );
    }

    public function test_sale_with_split_payments_snapshots_vat(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 10,
            'vat_rate' => 5,
            'vat_type' => 'percent',
        ]);

        $cashId = $this->createPaymentMethod('Cash');
        $bkashId = $this->createPaymentMethod('bKash', requiresReference: true);

        $clientUuid = (string) Str::uuid();

        $response = $this->postJson('/api/v1/sales', [
            'client_uuid' => $clientUuid,
            'items' => [
                ['product_id' => $productId, 'quantity' => 2],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 110],
                ['payment_method_id' => $bkashId, 'amount' => 100, 'reference' => 'TRX123'],
            ],
        ])->assertCreated()
            ->assertJsonPath('order_number', 1)
            ->assertJsonPath('subtotal', 200)
            ->assertJsonPath('vat_total', 10)
            ->assertJsonPath('total', 210);

        $saleId = $response->json('id');

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId,
            'product_id' => $productId,
            'vat_rate' => 5,
            'vat_amount' => 10,
            'line_subtotal' => 200,
            'line_total' => 210,
        ]);

        $this->assertDatabaseCount('sale_payments', 2);

        $product = Product::query()->findOrFail($productId);
        $this->assertEquals(8, (float) $product->fresh()->stock_quantity);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'type' => 'sale',
            'quantity_delta' => -2,
        ]);
    }

    public function test_sale_with_discount_amount_reduces_total(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 10,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 2],
            ],
            'discount_amount' => 25,
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 175],
            ],
        ])->assertCreated()
            ->assertJsonPath('subtotal', 200)
            ->assertJsonPath('vat_total', 0)
            ->assertJsonPath('discount_amount', 25)
            ->assertJsonPath('total', 175);

        $this->assertDatabaseHas('sales', [
            'subtotal' => 200,
            'discount_amount' => 25,
            'total' => 175,
        ]);
    }

    public function test_sale_rejects_discount_above_total(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 50,
            'stock_quantity' => 5,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'discount_amount' => 60,
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 50],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_amount']);
    }

    public function test_sale_with_credit_payment_creates_customer_due(): void
    {
        Sanctum::actingAs($this->owner);

        $customerId = $this->createCustomer();
        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 50,
            'stock_quantity' => 5,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $cashId = $this->createPaymentMethod('Cash');
        $dueId = $this->createPaymentMethod('Due', isCredit: true);

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 20],
                ['payment_method_id' => $dueId, 'amount' => 30],
            ],
        ])->assertCreated()
            ->assertJsonPath('total', 50);

        $this->assertDatabaseHas('customer_dues', [
            'customer_id' => $customerId,
            'amount' => 30,
            'balance' => 30,
            'status' => CustomerDue::STATUS_OPEN,
        ]);

        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonPath('data.0.open_due_balance', 30);

        $this->getJson("/api/v1/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('open_due_balance', 30);

        $this->deleteJson("/api/v1/customers/{$customerId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer']);
    }

    public function test_customer_can_be_deleted_after_due_is_settled(): void
    {
        Sanctum::actingAs($this->owner);

        $customerId = $this->createCustomer();
        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 50,
            'stock_quantity' => 5,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $dueId = $this->createPaymentMethod('Due', isCredit: true);
        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $dueId, 'amount' => 50],
            ],
        ])->assertCreated();

        $this->deleteJson("/api/v1/customers/{$customerId}")->assertUnprocessable();

        $this->postJson("/api/v1/customers/{$customerId}/due-payments", [
            'amount' => 50,
            'payment_method_id' => $cashId,
        ])->assertCreated();

        $this->deleteJson("/api/v1/customers/{$customerId}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $customerId]);
    }

    public function test_idempotent_sale_replay_does_not_double_stock(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 25,
            'stock_quantity' => 10,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $cashId = $this->createPaymentMethod('Cash');
        $clientUuid = (string) Str::uuid();

        $payload = [
            'client_uuid' => $clientUuid,
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 25],
            ],
        ];

        $this->postJson('/api/v1/sales', $payload)->assertCreated();

        $this->postJson('/api/v1/sales', $payload)
            ->assertOk()
            ->assertJsonPath('client_uuid', $clientUuid);

        $this->assertEquals(1, Sale::query()->where('client_uuid', $clientUuid)->count());
        $this->assertEquals(9, (float) Product::query()->findOrFail($productId)->stock_quantity);
        $this->assertEquals(1, StockMovement::query()->where('product_id', $productId)->where('type', 'sale')->count());
    }

    public function test_return_restocks_product(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 40,
            'stock_quantity' => 5,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $sale = $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 2],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 80],
            ],
        ])->assertCreated()
            ->assertJsonPath('created_user.id', $this->owner->id)
            ->assertJsonPath('created_user.name', $this->owner->name)
            ->assertJsonPath('updated_user.id', $this->owner->id)
            ->assertJsonPath('updated_user.name', $this->owner->name);

        $saleId = $sale->json('id');
        $saleItemId = $sale->json('items.0.id');

        $this->assertEquals(3, (float) Product::query()->findOrFail($productId)->stock_quantity);

        $cashier = $this->createTenantUser('cashier');
        Sanctum::actingAs($cashier);

        $this->postJson("/api/v1/sales/{$saleId}/returns", [
            'items' => [
                ['sale_item_id' => $saleItemId, 'quantity' => 1],
            ],
        ])->assertCreated()
            ->assertJsonPath('total', 40);

        $this->assertEquals(4, (float) Product::query()->findOrFail($productId)->stock_quantity);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'type' => 'return',
            'quantity_delta' => 1,
        ]);

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'partially_returned',
        ]);

        $this->getJson("/api/v1/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('status', 'partially_returned')
            ->assertJsonPath('returns.0.total', 40)
            ->assertJsonPath('items.0.returned_quantity', 1)
            ->assertJsonPath('items.0.returnable_quantity', 1)
            ->assertJsonPath('created_user.name', $this->owner->name)
            ->assertJsonPath('updated_user.id', $cashier->id)
            ->assertJsonPath('updated_user.name', $cashier->name);

        $this->getJson('/api/v1/sales')
            ->assertOk()
            ->assertJsonPath('data.0.id', $saleId)
            ->assertJsonPath('data.0.status', 'partially_returned')
            ->assertJsonPath('data.0.created_user.name', $this->owner->name)
            ->assertJsonPath('data.0.updated_user.name', $cashier->name);

        $this->postJson("/api/v1/sales/{$saleId}/returns", [
            'items' => [
                ['sale_item_id' => $saleItemId, 'quantity' => 1],
            ],
        ])->assertCreated()
            ->assertJsonPath('total', 40);

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'returned',
        ]);

        $this->getJson("/api/v1/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('status', 'returned')
            ->assertJsonPath('items.0.returned_quantity', 2)
            ->assertJsonPath('items.0.returnable_quantity', 0);
    }

    public function test_due_payment_settles_balance(): void
    {
        Sanctum::actingAs($this->owner);

        $customerId = $this->createCustomer();
        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 5,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $dueMethodId = $this->createPaymentMethod('Due', isCredit: true);
        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $dueMethodId, 'amount' => 100],
            ],
        ])->assertCreated();

        $this->postJson("/api/v1/customers/{$customerId}/due-payments", [
            'amount' => 100,
            'payment_method_id' => $cashId,
        ])->assertCreated()
            ->assertJsonPath('amount', 100);

        $this->assertDatabaseHas('customer_dues', [
            'customer_id' => $customerId,
            'balance' => 0,
            'status' => CustomerDue::STATUS_SETTLED,
        ]);
    }

    public function test_due_payment_rejects_credit_payment_method(): void
    {
        Sanctum::actingAs($this->owner);

        $customerId = $this->createCustomer();
        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 5,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $dueMethodId = $this->createPaymentMethod('Due', isCredit: true);

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $dueMethodId, 'amount' => 100],
            ],
        ])->assertCreated();

        $this->postJson("/api/v1/customers/{$customerId}/due-payments", [
            'amount' => 50,
            'payment_method_id' => $dueMethodId,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method_id']);
    }

    public function test_staff_can_use_pos_and_customers(): void
    {
        $staff = $this->createTenantUser('staff');

        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/customers', [
            'name' => 'Staff Customer',
        ])->assertCreated();

        $categoryId = $this->createCategoryAs($this->owner);
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 10,
            'stock_quantity' => 1,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $cashId = PaymentMethod::query()
            ->where('tenant_id', $staff->tenant_id)
            ->where('name', 'Cash')
            ->value('id');

        if ($cashId === null) {
            Sanctum::actingAs($this->owner);
            $cashId = $this->createPaymentMethod('Cash');
            Sanctum::actingAs($staff);
        }

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 10],
            ],
        ])->assertCreated();
    }

    public function test_vat_uses_store_default_when_product_vat_empty(): void
    {
        Sanctum::actingAs($this->owner);

        StoreSetting::query()
            ->where('store_id', $this->owner->tenant->store->id)
            ->update([
                'default_vat_percent' => 10,
                'vat_adjust_on_sale' => true,
            ]);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 5,
            'vat_rate' => null,
            'vat_type' => null,
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 110],
            ],
        ])->assertCreated()
            ->assertJsonPath('vat_total', 10);

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $productId,
            'vat_rate' => 10,
            'vat_amount' => 10,
        ]);
    }

    public function test_cash_sale_stores_change_amount(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 5,
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 100],
            ],
            'change_amount' => 50,
        ])->assertCreated()
            ->assertJsonPath('total', 100)
            ->assertJsonPath('change_amount', 50);

        $this->assertDatabaseHas('sales', [
            'total' => 100,
            'change_amount' => 50,
        ]);
    }

    public function test_credit_sale_requires_customer(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 50,
            'stock_quantity' => 5,
        ]);

        $dueId = $this->createPaymentMethod('Due', isCredit: true);

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $dueId, 'amount' => 50],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_fractional_sale_deducts_stock_for_kg_product(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'name' => 'Rice',
            'selling_price' => 80,
            'stock_quantity' => 50,
            'uom' => 'kg',
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1.3],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 104],
            ],
        ])->assertCreated()
            ->assertJsonPath('total', 104);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock_quantity' => 48.7,
        ]);

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $productId,
            'quantity' => 1.3,
        ]);
    }

    public function test_expired_product_sale_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'name' => 'Expired Yogurt',
            'selling_price' => 50,
            'stock_quantity' => 10,
            'uom' => 'pcs',
            'expiration_date' => now()->subDay()->toDateString(),
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 50],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_negotiable_product_accepts_custom_unit_price(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'cost_price' => 60,
            'stock_quantity' => 10,
            'is_negotiable' => true,
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1, 'unit_price' => 90],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 90],
            ],
        ])->assertCreated()
            ->assertJsonPath('total', 90);

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $productId,
            'unit_price' => 90,
        ]);
    }

    public function test_non_negotiable_product_rejects_custom_unit_price(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 10,
            'is_negotiable' => false,
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1, 'unit_price' => 90],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 90],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_unit_price_below_cost_is_rejected_when_cost_exists(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'cost_price' => 80,
            'stock_quantity' => 10,
            'is_negotiable' => true,
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1, 'unit_price' => 70],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 70],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1, 'unit_price' => 80],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 80],
            ],
        ])->assertCreated()
            ->assertJsonPath('total', 80);
    }

    public function test_negotiable_price_below_catalog_allowed_when_cost_is_null(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'cost_price' => null,
            'stock_quantity' => 10,
            'is_negotiable' => true,
        ]);

        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1, 'unit_price' => 50],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 50],
            ],
        ])->assertCreated()
            ->assertJsonPath('total', 50);
    }

    public function test_tenant_order_numbers_start_at_one_and_increment(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 50,
            'stock_quantity' => 10,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);
        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 50],
            ],
        ])->assertCreated()
            ->assertJsonPath('order_number', 1);

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 50],
            ],
        ])->assertCreated()
            ->assertJsonPath('order_number', 2);

        $this->assertEquals(2, $this->owner->tenant->fresh()->last_order_number);
    }

    public function test_order_numbers_are_isolated_per_tenant(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 50,
            'stock_quantity' => 10,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);
        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 50],
            ],
        ])->assertCreated()
            ->assertJsonPath('order_number', 1);

        $otherOwner = $this->registerOtherOwner('8801712345924');
        Sanctum::actingAs($otherOwner);

        $otherCategoryId = $this->createCategoryAs($otherOwner);
        $otherProductId = $this->createProductAs($otherOwner, $otherCategoryId, [
            'selling_price' => 50,
            'stock_quantity' => 10,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);
        $otherCashId = $this->createPaymentMethodAs($otherOwner, 'Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $otherProductId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $otherCashId, 'amount' => 50],
            ],
        ])->assertCreated()
            ->assertJsonPath('order_number', 1);
    }

    public function test_sales_list_supports_filters(): void
    {
        Sanctum::actingAs($this->owner);

        $customerId = $this->createCustomer(['mobile' => '8801712345991']);
        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 20,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);
        $cashId = $this->createPaymentMethod('Cash');
        $dueMethodId = $this->createPaymentMethod('Due', isCredit: true);
        $cashier = $this->createTenantUser('cashier');

        $paidSaleResponse = $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 100],
            ],
        ])->assertCreated();

        $paidSaleId = $paidSaleResponse->json('id');
        $paidOrderNumber = $paidSaleResponse->json('order_number');

        Sanctum::actingAs($cashier);
        $dueSaleId = $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $dueMethodId, 'amount' => 100],
            ],
        ])->assertCreated()->json('id');

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/sales?order_id={$paidOrderNumber}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paidSaleId)
            ->assertJsonPath('data.0.order_number', $paidOrderNumber);

        $this->getJson("/api/v1/sales?user_id={$cashier->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $dueSaleId);

        $this->getJson('/api/v1/sales?customer_mobile=01712345991')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/sales?status=completed')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $today = now()->toDateString();
        $this->getJson("/api/v1/sales?from={$today}&to={$today}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/sales?payment=due')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $dueSaleId);

        $this->getJson('/api/v1/sales?payment=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paidSaleId);

        $this->getJson('/api/v1/sales')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->owner->id, 'name' => $this->owner->name]);
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

    private function createProductAs(User $user, int $categoryId, array $extra = []): int
    {
        Sanctum::actingAs($user);

        $stockQuantity = $extra['stock_quantity'] ?? null;
        $expirationDate = $extra['expiration_date'] ?? null;
        unset($extra['stock_quantity'], $extra['expiration_date']);

        $productId = (int) $this->postJson('/api/v1/products', array_merge([
            'name' => 'Product '.Str::random(4),
            'category_id' => $categoryId,
            'uom' => 'pcs',
            'manage_inventory' => true,
        ], $extra))
            ->assertCreated()
            ->json('id');

        if ($stockQuantity !== null && (float) $stockQuantity > 0) {
            $payload = [
                'product_id' => $productId,
                'quantity_delta' => (float) $stockQuantity,
                'unit_cost' => (float) ($extra['cost_price'] ?? 0),
                'reason' => 'Test seed',
            ];

            if ($expirationDate !== null) {
                $payload['expiration_date'] = $expirationDate;
            }

            $this->postJson('/api/v1/stock-adjustments', $payload)->assertCreated();
        }

        return $productId;
    }

    private function createPaymentMethodAs(User $user, string $name, bool $requiresReference = false, bool $isCredit = false): int
    {
        Sanctum::actingAs($user);

        return (int) $this->postJson('/api/v1/payment-methods', [
            'name' => $name.' '.Str::random(4),
            'requires_reference' => $requiresReference,
            'is_credit' => $isCredit,
        ])->assertCreated()
            ->json('id');
    }

    private function createProduct(int $categoryId, array $extra = []): int
    {
        return $this->createProductAs($this->owner, $categoryId, $extra);
    }

    private function createPaymentMethod(string $name, bool $requiresReference = false, bool $isCredit = false): int
    {
        return $this->createPaymentMethodAs($this->owner, $name, $requiresReference, $isCredit);
    }

    private function createCustomer(array $extra = []): int
    {
        Sanctum::actingAs($this->owner);

        return (int) $this->postJson('/api/v1/customers', array_merge([
            'name' => 'Due Customer',
            'mobile' => '88017'.random_int(10000000, 99999999),
        ], $extra))->assertCreated()
            ->json('id');
    }

    private function createTenantUser(string $role): User
    {
        $mobile = match ($role) {
            'cashier' => '8801712345922',
            'staff' => '8801712345923',
            default => '8801712345998',
        };

        return $this->createBranchUser($this->owner, $role, $mobile);
    }

    private function registerOtherOwner(string $mobile): User
    {
        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Other Sales Shop',
            'owner_name' => 'Other',
            'mobile' => $mobile,
            'pin' => '123456',
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }
}
