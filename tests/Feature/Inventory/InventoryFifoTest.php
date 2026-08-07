<?php

namespace Tests\Feature\Inventory;

use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SaleItemLotAllocation;
use App\Models\StockLot;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryFifoTest extends TestCase
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
            'shop_name' => 'Inventory Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345950',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345950')->firstOrFail();
    }

    public function test_purchase_creates_lot_and_increases_stock(): void
    {
        Sanctum::actingAs($this->owner);

        $productId = $this->createProduct(['stock_quantity' => 0, 'cost_price' => 50]);
        $supplierId = $this->createSupplier();

        $response = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplierId,
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => 100,
                    'unit_cost' => 95,
                    'expiration_date' => now()->addMonths(6)->toDateString(),
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('purchase_number', 'PUR-0001')
            ->assertJsonPath('total', 9500)
            ->assertJsonPath('supplier.id', $supplierId);

        $product = Product::query()->findOrFail($productId);
        $this->assertEquals(100.0, (float) $product->stock_quantity);
        $this->assertEquals(95.0, (float) $product->cost_price);

        $this->assertDatabaseHas('stock_lots', [
            'product_id' => $productId,
            'quantity_remaining' => 100,
            'unit_cost' => 95,
        ]);

        $this->getJson('/api/v1/purchases/'.$response->json('id'))
            ->assertOk()
            ->assertJsonCount(1, 'items');

        $purchaseId = $response->json('id');

        $this->assertDatabaseHas('expenses', [
            'purchase_id' => $purchaseId,
            'amount' => 9500,
            'title' => 'PUR-0001',
            'expense_category_id' => ExpenseCategory::query()
                ->where('name', ExpenseCategory::PURCHASES_NAME)
                ->value('id'),
        ]);

        $this->getJson('/api/v1/expenses?purchase_id='.$purchaseId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total_amount', 9500);
    }

    public function test_sale_splits_across_fifo_lots_with_blended_unit_cost(): void
    {
        Sanctum::actingAs($this->owner);

        $productId = $this->createProduct([
            'stock_quantity' => 0,
            'selling_price' => 120,
            'cost_price' => 80,
        ]);

        $this->postJson('/api/v1/purchases', [
            'items' => [
                ['product_id' => $productId, 'quantity' => 6, 'unit_cost' => 80],
            ],
        ])->assertCreated();

        $this->postJson('/api/v1/purchases', [
            'items' => [
                ['product_id' => $productId, 'quantity' => 100, 'unit_cost' => 95],
            ],
        ])->assertCreated();

        $cashId = $this->cashPaymentMethodId();

        $sale = $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 10],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 1200],
            ],
        ])->assertCreated();

        $item = $sale->json('items.0');
        $this->assertEquals(86.0, (float) $item['unit_cost']);

        $saleItemId = $item['id'];
        $allocations = SaleItemLotAllocation::query()
            ->where('sale_item_id', $saleItemId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $allocations);
        $this->assertEquals(6.0, (float) $allocations[0]->quantity);
        $this->assertEquals(80.0, (float) $allocations[0]->unit_cost);
        $this->assertEquals(4.0, (float) $allocations[1]->quantity);
        $this->assertEquals(95.0, (float) $allocations[1]->unit_cost);

        $lots = StockLot::query()->where('product_id', $productId)->orderBy('id')->get();
        $this->assertEquals(0.0, (float) $lots[0]->quantity_remaining);
        $this->assertEquals(96.0, (float) $lots[1]->quantity_remaining);
    }

    public function test_expired_lot_is_skipped_and_sale_blocked_when_only_expired_stock(): void
    {
        Sanctum::actingAs($this->owner);

        $productId = $this->createProduct([
            'stock_quantity' => 0,
            'selling_price' => 100,
        ]);

        $this->postJson('/api/v1/purchases', [
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => 5,
                    'unit_cost' => 40,
                    'expiration_date' => now()->subDay()->toDateString(),
                ],
            ],
        ])->assertCreated();

        $cashId = $this->cashPaymentMethodId();

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 100],
            ],
        ])->assertStatus(422);
    }

    public function test_return_restores_lot_quantities(): void
    {
        Sanctum::actingAs($this->owner);

        $productId = $this->createProduct([
            'stock_quantity' => 0,
            'selling_price' => 100,
        ]);

        $this->postJson('/api/v1/purchases', [
            'items' => [
                ['product_id' => $productId, 'quantity' => 10, 'unit_cost' => 50],
            ],
        ])->assertCreated();

        $cashId = $this->cashPaymentMethodId();

        $sale = $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 4],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 400],
            ],
        ])->assertCreated();

        $saleId = $sale->json('id');
        $saleItemId = $sale->json('items.0.id');

        $this->postJson("/api/v1/sales/{$saleId}/returns", [
            'items' => [
                ['sale_item_id' => $saleItemId, 'quantity' => 2],
            ],
        ])->assertCreated();

        $lot = StockLot::query()->where('product_id', $productId)->firstOrFail();
        $this->assertEquals(8.0, (float) $lot->quantity_remaining);
        $this->assertEquals(8.0, (float) Product::query()->findOrFail($productId)->stock_quantity);
    }

    public function test_negative_adjustment_consumes_fifo_including_expired(): void
    {
        Sanctum::actingAs($this->owner);

        $productId = $this->createProduct(['stock_quantity' => 0]);

        $this->postJson('/api/v1/purchases', [
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => 5,
                    'unit_cost' => 10,
                    'expiration_date' => now()->subDay()->toDateString(),
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $productId,
            'quantity_delta' => -5,
            'reason' => 'Expired write-off',
        ])->assertCreated();

        $this->assertEquals(0.0, (float) Product::query()->findOrFail($productId)->stock_quantity);
        $this->assertEquals(
            0.0,
            (float) StockLot::query()->where('product_id', $productId)->sum('quantity_remaining')
        );
    }

    public function test_profit_summary_uses_sale_item_unit_cost(): void
    {
        Sanctum::actingAs($this->owner);

        $productId = $this->createProduct([
            'stock_quantity' => 0,
            'selling_price' => 100,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $this->postJson('/api/v1/purchases', [
            'items' => [
                ['product_id' => $productId, 'quantity' => 10, 'unit_cost' => 40],
            ],
        ])->assertCreated();

        $cashId = $this->cashPaymentMethodId();

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 2],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 200],
            ],
        ])->assertCreated();

        $this->getJson('/api/v1/reports/profit-summary?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('cogs', 80)
            ->assertJsonPath('gross_revenue', 200)
            ->assertJsonPath('gross_profit', 120)
            ->assertJsonPath('expenses_total', 400)
            ->assertJsonPath('net_profit', -280);
    }

    public function test_purchase_can_be_deleted_when_stock_unused(): void
    {
        Sanctum::actingAs($this->owner);

        $productId = $this->createProduct(['stock_quantity' => 0, 'cost_price' => 50]);

        $purchaseId = $this->postJson('/api/v1/purchases', [
            'items' => [
                ['product_id' => $productId, 'quantity' => 10, 'unit_cost' => 80],
            ],
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/v1/purchases/{$purchaseId}")
            ->assertOk()
            ->assertJsonPath('message', 'Purchase deleted successfully.');

        $this->assertSoftDeleted('purchases', ['id' => $purchaseId]);
        $this->assertSoftDeleted('expenses', ['purchase_id' => $purchaseId]);
        $this->assertDatabaseMissing('stock_lots', ['product_id' => $productId]);
        $this->assertEquals(0.0, (float) Product::query()->findOrFail($productId)->stock_quantity);

        $this->getJson("/api/v1/purchases/{$purchaseId}")->assertNotFound();
    }

    public function test_cannot_delete_purchase_after_stock_is_sold(): void
    {
        Sanctum::actingAs($this->owner);

        $productId = $this->createProduct([
            'stock_quantity' => 0,
            'selling_price' => 100,
            'cost_price' => 50,
        ]);

        $purchaseId = $this->postJson('/api/v1/purchases', [
            'items' => [
                ['product_id' => $productId, 'quantity' => 10, 'unit_cost' => 80],
            ],
        ])->assertCreated()->json('id');

        $cashId = $this->cashPaymentMethodId();

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 2],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 200],
            ],
        ])->assertCreated();

        $this->deleteJson("/api/v1/purchases/{$purchaseId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['purchase']);

        $this->assertDatabaseHas('purchases', [
            'id' => $purchaseId,
            'deleted_at' => null,
        ]);
        $this->assertEquals(8.0, (float) Product::query()->findOrFail($productId)->stock_quantity);
    }

    public function test_staff_cannot_manage_purchases(): void
    {
        Sanctum::actingAs($this->owner);

        $staff = $this->createBranchUser($this->owner, 'staff', '8801712345951', 'startup-plus');

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/purchases')->assertForbidden();
        $this->getJson('/api/v1/inventory/lots')->assertForbidden();
    }

    public function test_inventory_lots_expired_filter(): void
    {
        Sanctum::actingAs($this->owner);

        $productId = $this->createProduct(['stock_quantity' => 0]);

        $this->postJson('/api/v1/purchases', [
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => 3,
                    'unit_cost' => 10,
                    'expiration_date' => now()->subDay()->toDateString(),
                ],
            ],
        ])->assertCreated();

        $this->getJson('/api/v1/inventory/lots?expired=1&has_remaining=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_expired', true);
    }

    public function test_inventory_lots_support_catalog_filters(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryA = $this->createCategory();
        $categoryB = $this->postJson('/api/v1/categories', [
            'name' => 'Other '.Str::random(4),
        ])->assertCreated()->json('id');
        $supplier = $this->createSupplier();
        $brand = $this->postJson('/api/v1/brands', [
            'name' => 'Brand '.Str::random(4),
        ])->assertCreated()->json('id');

        $matchedId = $this->postJson('/api/v1/products', [
            'category_id' => $categoryA,
            'supplier_id' => $supplier,
            'brand_id' => $brand,
            'name' => 'Filtered Milk',
            'sku' => 'FLT-MILK',
            'barcode' => '8801666000001',
            'selling_price' => 100,
            'cost_price' => 50,
            'uom' => 'pcs',
            'manage_inventory' => true,
        ])->assertCreated()->json('id');

        $otherId = $this->postJson('/api/v1/products', [
            'category_id' => $categoryB,
            'name' => 'Other Juice',
            'sku' => 'OTH-JUICE',
            'barcode' => '8801666000002',
            'selling_price' => 80,
            'cost_price' => 40,
            'uom' => 'pcs',
            'manage_inventory' => true,
        ])->assertCreated()->json('id');

        foreach ([$matchedId, $otherId] as $productId) {
            $this->postJson('/api/v1/purchases', [
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 2,
                        'unit_cost' => 10,
                    ],
                ],
            ])->assertCreated();
        }

        $this->getJson("/api/v1/inventory/lots?has_remaining=1&category_id={$categoryA}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $matchedId);

        $this->getJson("/api/v1/inventory/lots?has_remaining=1&supplier_id={$supplier}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/inventory/lots?has_remaining=1&brand_id={$brand}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/inventory/lots?has_remaining=1&search=8801666000001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.barcode', '8801666000001');

        $this->getJson('/api/v1/inventory/lots?has_remaining=1&search=Filtered')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.name', 'Filtered Milk');
    }

    private function createCategory(): int
    {
        return $this->postJson('/api/v1/categories', [
            'name' => 'General '.Str::random(4),
        ])->assertCreated()->json('id');
    }

    private function createSupplier(): int
    {
        return $this->postJson('/api/v1/suppliers', [
            'name' => 'Vendor '.Str::random(4),
        ])->assertCreated()->json('id');
    }

    private function createProduct(array $overrides = []): int
    {
        $categoryId = $this->createCategory();

        return $this->postJson('/api/v1/products', array_merge([
            'category_id' => $categoryId,
            'name' => 'Biscuits '.Str::random(4),
            'selling_price' => 100,
            'cost_price' => 50,
            'stock_quantity' => 0,
            'uom' => 'pcs',
            'manage_inventory' => true,
        ], $overrides))->assertCreated()->json('id');
    }

    private function cashPaymentMethodId(): int
    {
        return PaymentMethod::query()
            ->where('store_id', $this->defaultStore($this->owner)->id)
            ->where('is_credit', false)
            ->value('id');
    }
}
