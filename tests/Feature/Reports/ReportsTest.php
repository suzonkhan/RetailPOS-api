<?php

namespace Tests\Feature\Reports;

use App\Models\CustomerDue;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsTest extends TestCase
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
            'shop_name' => 'Reports Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345930',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345930')->firstOrFail();
    }

    public function test_sales_summary_aggregates_sales_returns_and_dues(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 10,
            'vat_rate' => 10,
            'vat_type' => 'percent',
        ]);

        $cashId = $this->createPaymentMethod('Cash');
        $dueId = $this->createPaymentMethod('Due', isCredit: true);
        $customerId = $this->createCustomer();

        $sale = $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'items' => [
                ['product_id' => $productId, 'quantity' => 2],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 110],
                ['payment_method_id' => $dueId, 'amount' => 110],
            ],
        ])->assertCreated();

        $saleId = $sale->json('id');
        $saleItemId = $sale->json('items.0.id');

        $this->postJson("/api/v1/sales/{$saleId}/returns", [
            'items' => [
                ['sale_item_id' => $saleItemId, 'quantity' => 1],
            ],
        ])->assertCreated();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/v1/reports/sales-summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('currency', 'BDT')
            ->assertJsonPath('from', $from)
            ->assertJsonPath('to', $to)
            ->assertJsonPath('sale_count', 1)
            ->assertJsonPath('gross_revenue', 220)
            ->assertJsonPath('vat_total', 20)
            ->assertJsonPath('returns_total', 110)
            ->assertJsonPath('net_revenue', 110)
            ->assertJsonPath('discounts_total', 0)
            ->assertJsonPath('average_order_value', 110)
            ->assertJsonPath('outstanding_dues', 110);
    }

    public function test_sales_summary_respects_date_filters(): void
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
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 50],
            ],
        ])->assertCreated();

        $sale = Sale::query()->latest('id')->firstOrFail();
        $sale->timestamps = false;
        $sale->created_at = now()->subMonths(3);
        $sale->save();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/v1/reports/sales-summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('sale_count', 0)
            ->assertJsonPath('gross_revenue', 0);

        $oldFrom = now()->subMonths(3)->startOfMonth()->toDateString();
        $oldTo = now()->subMonths(2)->endOfMonth()->toDateString();

        $this->getJson("/api/v1/reports/sales-summary?from={$oldFrom}&to={$oldTo}")
            ->assertOk()
            ->assertJsonPath('sale_count', 1)
            ->assertJsonPath('gross_revenue', 50);
    }

    public function test_sales_trend_top_products_and_payment_breakdown(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productA = $this->createProduct($categoryId, [
            'name' => 'Alpha Widget',
            'selling_price' => 100,
            'stock_quantity' => 10,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);
        $productB = $this->createProduct($categoryId, [
            'name' => 'Beta Gadget',
            'selling_price' => 20,
            'stock_quantity' => 10,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);

        $cashId = $this->createPaymentMethod('Cash');
        $bkashId = $this->createPaymentMethod('bKash', requiresReference: true);

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productA, 'quantity' => 1],
                ['product_id' => $productB, 'quantity' => 3],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 120],
                ['payment_method_id' => $bkashId, 'amount' => 40, 'reference' => 'BK1'],
            ],
        ])->assertCreated();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $today = now()->toDateString();

        $this->getJson("/api/v1/reports/sales-trend?from={$from}&to={$to}&period=day")
            ->assertOk()
            ->assertJsonPath('period', 'day')
            ->assertJsonFragment([
                'label' => $today,
                'sale_count' => 1,
                'gross_revenue' => 160,
                'net_revenue' => 160,
            ]);

        $this->getJson("/api/v1/reports/top-products?from={$from}&to={$to}&sort_by=revenue&limit=5")
            ->assertOk()
            ->assertJsonPath('sort_by', 'revenue')
            ->assertJsonPath('data.0.product_id', $productA)
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.0.revenue', 100)
            ->assertJsonPath('data.1.product_id', $productB)
            ->assertJsonPath('data.1.quantity', 3);

        $this->getJson("/api/v1/reports/payment-breakdown?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['total' => 120])
            ->assertJsonFragment(['total' => 40]);
    }

    public function test_profit_summary_includes_expenses_and_net_profit(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 100,
            'stock_quantity' => 10,
            'cost_price' => 40,
            'vat_rate' => 0,
            'vat_type' => 'percent',
            'manage_inventory' => true,
        ]);
        $cashId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/purchases', [
            'supplier_id' => null,
            'purchased_at' => now()->toDateString(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 10, 'unit_cost' => 40],
            ],
        ])->assertCreated();

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 2],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 200],
            ],
        ])->assertCreated();

        $expenseCategoryId = $this->postJson('/api/v1/expense-categories', [
            'name' => 'Rent',
        ])->assertCreated()->json('id');

        $this->postJson('/api/v1/expenses', [
            'title' => 'Shop rent',
            'amount' => 50,
            'expense_category_id' => $expenseCategoryId,
            'expense_date' => now()->toDateString(),
        ])->assertCreated();

        $from = now()->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/v1/reports/profit-summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('gross_revenue', 200)
            ->assertJsonPath('cogs', 80)
            ->assertJsonPath('gross_profit', 120)
            ->assertJsonPath('profit_margin_percent', 60)
            ->assertJsonPath('expenses_total', 50)
            ->assertJsonPath('net_profit', 70);
    }

    public function test_reports_exclude_other_tenant_data(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 75,
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
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 75],
            ],
        ])->assertCreated();

        $other = $this->registerOtherOwner('8801712345931');
        Sanctum::actingAs($other);

        $otherCategoryId = $this->createCategoryAs($other);
        $otherProductId = $this->createProductAs($other, $otherCategoryId, [
            'selling_price' => 999,
            'stock_quantity' => 5,
            'vat_rate' => 0,
            'vat_type' => 'percent',
        ]);
        $otherCashId = $this->createPaymentMethodAs($other, 'Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $otherProductId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $otherCashId, 'amount' => 999],
            ],
        ])->assertCreated();

        Sanctum::actingAs($this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/v1/reports/sales-summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('sale_count', 1)
            ->assertJsonPath('gross_revenue', 75);

        Sanctum::actingAs($other);

        $this->getJson("/api/v1/reports/sales-summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('sale_count', 1)
            ->assertJsonPath('gross_revenue', 999);
    }

    public function test_staff_cannot_access_reports(): void
    {
        $staff = $this->createTenantUser('staff');

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/reports/sales-summary')->assertForbidden();
        $this->getJson('/api/v1/reports/sales-trend')->assertForbidden();
        $this->getJson('/api/v1/reports/top-products')->assertForbidden();
        $this->getJson('/api/v1/reports/payment-breakdown')->assertForbidden();
        $this->getJson('/api/v1/reports/business-summary')->assertForbidden();
        $this->getJson('/api/v1/reports/current-stock')->assertForbidden();
    }

    public function test_cashier_cannot_access_reports(): void
    {
        $cashier = $this->createTenantUser('cashier');

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/reports/sales-summary')->assertForbidden();
        $this->getJson('/api/v1/reports/business-summary')->assertForbidden();
        $this->getJson('/api/v1/reports/profit-summary')->assertForbidden();
    }

    public function test_phase_a_print_reports(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productFast = $this->createProduct($categoryId, [
            'name' => 'Fast Mover',
            'sku' => 'FAST-1',
            'barcode' => '111',
            'selling_price' => 100,
            'cost_price' => 40,
            'stock_quantity' => 5,
            'min_stock_quantity' => 20,
            'vat_rate' => 0,
            'vat_type' => 'percent',
            'manage_inventory' => true,
        ]);
        $productSlow = $this->createProduct($categoryId, [
            'name' => 'Slow Mover',
            'sku' => 'SLOW-1',
            'selling_price' => 50,
            'cost_price' => 20,
            'stock_quantity' => 8,
            'min_stock_quantity' => 2,
            'vat_rate' => 0,
            'vat_type' => 'percent',
            'manage_inventory' => true,
        ]);

        $cashId = $this->createPaymentMethod('Cash');
        $customerId = $this->createCustomer();
        $dueId = $this->createPaymentMethod('Due', isCredit: true);

        $this->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'items' => [
                ['product_id' => $productFast, 'quantity' => 5, 'unit_cost' => 40],
            ],
        ])->assertCreated();

        $sale = $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'items' => [
                ['product_id' => $productFast, 'quantity' => 2],
            ],
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 100],
                ['payment_method_id' => $dueId, 'amount' => 100],
            ],
        ])->assertCreated();

        $expenseCategoryId = $this->postJson('/api/v1/expense-categories', [
            'name' => 'Utilities',
        ])->assertCreated()->json('id');

        $this->postJson('/api/v1/expenses', [
            'title' => 'Electricity',
            'amount' => 25,
            'expense_category_id' => $expenseCategoryId,
            'expense_date' => now()->toDateString(),
        ])->assertCreated();

        $from = now()->toDateString();
        $to = now()->toDateString();
        $saleId = $sale->json('id');

        $this->getJson("/api/v1/reports/business-summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('total_orders', 1)
            ->assertJsonPath('total_sales', 200)
            ->assertJsonPath('total_expenses', 25)
            ->assertJsonPath('low_stock_items', 1);

        $this->getJson("/api/v1/reports/daily-sales?date={$from}")
            ->assertOk()
            ->assertJsonPath('data.0.invoice_no', '#'.$saleId)
            ->assertJsonPath('data.0.grand_total', 200)
            ->assertJsonPath('data.0.discount', 0);

        $this->getJson("/api/v1/reports/product-sales?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('data.0.product_id', $productFast)
            ->assertJsonPath('data.0.quantity_sold', 2)
            ->assertJsonPath('data.0.sales_amount', 200);

        $this->getJson('/api/v1/reports/current-stock')
            ->assertOk()
            ->assertJsonFragment(['product_id' => $productSlow, 'sku' => 'SLOW-1']);

        $this->getJson("/api/v1/reports/stock-ledger?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonStructure(['data' => [['date', 'product', 'reference', 'stock_in', 'stock_out', 'balance_stock']]]);

        $this->getJson('/api/v1/reports/low-stock')
            ->assertOk()
            ->assertJsonPath('data.0.product_id', $productFast)
            ->assertJsonPath('data.0.suggested_reorder_quantity', 12);

        $this->getJson("/api/v1/reports/expenses?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('total_amount', 25)
            ->assertJsonPath('data.0.description', 'Electricity');

        $this->getJson('/api/v1/reports/customer-dues')
            ->assertOk()
            ->assertJsonPath('data.0.total_due', 100)
            ->assertJsonPath('data.0.customer_id', $customerId);

        $this->getJson("/api/v1/reports/slow-moving-products?from={$from}&to={$to}&limit=10")
            ->assertOk()
            ->assertJsonPath('data.0.product_id', $productSlow)
            ->assertJsonPath('data.0.quantity_sold', 0);
    }

    public function test_soft_deleted_sales_are_excluded(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 30,
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
            'payments' => [
                ['payment_method_id' => $cashId, 'amount' => 30],
            ],
        ])->assertCreated();

        Sale::query()->latest('id')->firstOrFail()->delete();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/v1/reports/sales-summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('sale_count', 0);
    }

    public function test_settled_dues_are_not_counted_as_outstanding(): void
    {
        Sanctum::actingAs($this->owner);

        $customerId = $this->createCustomer();
        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'selling_price' => 60,
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
                ['payment_method_id' => $dueId, 'amount' => 60],
            ],
        ])->assertCreated();

        $this->postJson("/api/v1/customers/{$customerId}/due-payments", [
            'amount' => 60,
            'payment_method_id' => $cashId,
        ])->assertCreated();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/v1/reports/sales-summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('outstanding_dues', 0);

        $this->assertDatabaseHas('customer_dues', [
            'customer_id' => $customerId,
            'status' => CustomerDue::STATUS_SETTLED,
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

    private function createProduct(int $categoryId, array $extra = []): int
    {
        return $this->createProductAs($this->owner, $categoryId, $extra);
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

    private function createPaymentMethod(string $name, bool $requiresReference = false, bool $isCredit = false): int
    {
        return $this->createPaymentMethodAs($this->owner, $name, $requiresReference, $isCredit);
    }

    private function createPaymentMethodAs(
        User $user,
        string $name,
        bool $requiresReference = false,
        bool $isCredit = false,
    ): int {
        Sanctum::actingAs($user);

        return (int) $this->postJson('/api/v1/payment-methods', [
            'name' => $name.' '.Str::random(4),
            'requires_reference' => $requiresReference,
            'is_credit' => $isCredit,
        ])->assertCreated()
            ->json('id');
    }

    private function createCustomer(): int
    {
        Sanctum::actingAs($this->owner);

        return (int) $this->postJson('/api/v1/customers', [
            'name' => 'Report Customer',
            'mobile' => '88017'.random_int(10000000, 99999999),
        ])->assertCreated()
            ->json('id');
    }

    private function createTenantUser(string $role): User
    {
        $this->owner->tenant->update([
            'plan_id' => Plan::query()->where('slug', 'startup-plus')->value('id'),
        ]);

        $mobile = '8801712345932';

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => ucfirst($role),
            'mobile' => $mobile,
            'pin' => '111111',
            'role' => $role,
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }

    private function registerOtherOwner(string $mobile): User
    {
        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Other Reports Shop',
            'owner_name' => 'Other',
            'mobile' => $mobile,
            'pin' => '123456',
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }
}
