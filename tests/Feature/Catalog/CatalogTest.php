<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CatalogTest extends TestCase
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
            'shop_name' => 'Catalog Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345900',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345900')->firstOrFail();
    }

    public function test_owner_can_crud_categories_suppliers_brands(): void
    {
        Sanctum::actingAs($this->owner);

        $category = $this->postJson('/api/v1/categories', [
            'name' => 'Beverages',
            'sort_order' => 1,
        ])->assertCreated()
            ->assertJsonPath('name', 'Beverages');

        $categoryId = Category::query()->where('name', 'Beverages')->value('id');

        $supplier = $this->postJson('/api/v1/suppliers', [
            'name' => 'Acme Supply',
            'phone' => '8801711111111',
        ])->assertCreated()
            ->assertJsonPath('name', 'Acme Supply');

        $supplierId = $supplier->json('id');

        $brand = $this->postJson('/api/v1/brands', [
            'name' => 'FreshCo',
        ])->assertCreated()
            ->assertJsonPath('name', 'FreshCo');

        $brandId = $brand->json('id');

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/categories/{$categoryId}")
            ->assertOk()
            ->assertJsonPath('uuid', $category->json('uuid'));

        $this->putJson("/api/v1/categories/{$categoryId}", [
            'name' => 'Drinks',
        ])->assertOk()
            ->assertJsonPath('name', 'Drinks');

        $this->getJson("/api/v1/suppliers/{$supplierId}")->assertOk();
        $this->getJson("/api/v1/brands/{$brandId}")->assertOk();

        $this->deleteJson("/api/v1/brands/{$brandId}")->assertOk();
        $this->assertSoftDeleted('brands', ['id' => $brandId]);
    }

    public function test_owner_can_crud_products_with_vat_and_images(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Snacks');
        $supplierId = $this->createSupplier('Local Vendor');
        $brandId = $this->createBrand('House');

        $product = $this->postJson('/api/v1/products', [
            'name' => 'Mango Juice',
            'sku' => 'MJ-001',
            'barcode' => '8801000000001',
            'category_id' => $categoryId,
            'supplier_id' => $supplierId,
            'brand_id' => $brandId,
            'selling_price' => 120,
            'cost_price' => 80,
            'min_stock_quantity' => 10,
            'manage_inventory' => true,
            'uom' => 'pcs',
            'vat_rate' => 5,
            'vat_type' => 'percent',
        ])->assertCreated()
            ->assertJsonPath('name', 'Mango Juice')
            ->assertJsonPath('vat_rate', 5)
            ->assertJsonPath('vat_type', 'percent')
            ->assertJsonPath('sku', 'MJ-001')
            ->assertJsonPath('uom', 'pcs')
            ->assertJsonPath('min_stock_quantity', 10)
            ->assertJsonPath('stock_quantity', 0)
            ->assertJsonPath('fractional_qty', false);

        $productId = Product::query()->where('sku', 'MJ-001')->value('id');

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'tenant_id' => $this->owner->tenant_id,
            'vat_rate' => 5,
            'vat_type' => 'percent',
        ]);

        Storage::fake('public');

        $image = $this->post("/api/v1/products/{$productId}/images", [
            'image' => UploadedFile::fake()->image('product.jpg'),
            'sort_order' => 0,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['id', 'uuid', 'url', 'path']);

        $imageId = $image->json('id');

        $this->getJson("/api/v1/products/{$productId}/images")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/products/{$productId}", [
            'vat_rate' => 10,
            'vat_type' => 'fixed',
        ])->assertOk()
            ->assertJsonPath('vat_rate', 10)
            ->assertJsonPath('vat_type', 'fixed');

        $this->deleteJson("/api/v1/products/{$productId}/images/{$imageId}")
            ->assertOk();

        $this->deleteJson("/api/v1/products/{$productId}")->assertOk();
        $this->assertSoftDeleted('products', ['id' => $productId]);
    }

    public function test_product_gets_auto_generated_unique_barcode_when_omitted(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Auto Barcode');

        $first = $this->postJson('/api/v1/products', [
            'name' => 'No Barcode One',
            'category_id' => $categoryId,
            'selling_price' => 50,
            'uom' => 'pcs',
        ])->assertCreated();

        $firstBarcode = $first->json('barcode');
        $this->assertNotEmpty($firstBarcode);
        $this->assertMatchesRegularExpression('/^\d{13}$/', $firstBarcode);

        $second = $this->postJson('/api/v1/products', [
            'name' => 'No Barcode Two',
            'category_id' => $categoryId,
            'selling_price' => 60,
            'uom' => 'pcs',
            'barcode' => null,
        ])->assertCreated();

        $secondBarcode = $second->json('barcode');
        $this->assertNotEmpty($secondBarcode);
        $this->assertNotSame($firstBarcode, $secondBarcode);

        $this->getJson('/api/v1/products/'.$first->json('id'))
            ->assertOk()
            ->assertJsonPath('barcode', $firstBarcode);
    }

    public function test_product_negotiable_and_ask_qty_flags_round_trip(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Flags');

        $product = $this->postJson('/api/v1/products', [
            'name' => 'Negotiable Rice',
            'category_id' => $categoryId,
            'uom' => 'kg',
            'selling_price' => 100,
            'is_negotiable' => true,
            'ask_qty_on_add' => true,
        ])->assertCreated()
            ->assertJsonPath('is_negotiable', true)
            ->assertJsonPath('ask_qty_on_add', true)
            ->assertJsonPath('fractional_qty', true);

        $productId = $product->json('id');

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'is_negotiable' => true,
            'ask_qty_on_add' => true,
        ]);

        $this->putJson("/api/v1/products/{$productId}", [
            'is_negotiable' => false,
            'ask_qty_on_add' => false,
        ])->assertOk()
            ->assertJsonPath('is_negotiable', false)
            ->assertJsonPath('ask_qty_on_add', false);
    }

    public function test_product_list_supports_search_and_filters(): void
    {
        Sanctum::actingAs($this->owner);

        $catA = $this->createCategory('Cat A');
        $catB = $this->createCategory('Cat B');
        $supplier = $this->createSupplier('Sup A');
        $brand = $this->createBrand('Brand X');

        $this->postJson('/api/v1/products', [
            'name' => 'Alpha Widget',
            'sku' => 'AW-1',
            'barcode' => '111',
            'category_id' => $catA,
            'supplier_id' => $supplier,
            'brand_id' => $brand,
            'uom' => 'pcs',
        ])->assertCreated();

        $this->postJson('/api/v1/products', [
            'name' => 'Beta Gadget',
            'sku' => 'BG-2',
            'barcode' => '222',
            'category_id' => $catB,
            'uom' => 'pcs',
        ])->assertCreated();

        $this->getJson('/api/v1/products?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Widget');

        $this->getJson('/api/v1/products?search=BG-2')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/products?search=111')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/products?category_id={$catB}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Gadget');

        $this->getJson("/api/v1/products?supplier_id={$supplier}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/products?brand_id={$brand}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_plan_limits_reject_category_and_product_create(): void
    {
        Sanctum::actingAs($this->owner);

        $plan = $this->owner->tenant->plan;
        $plan->update([
            'max_categories' => 1,
            'max_products' => 1,
        ]);

        $this->postJson('/api/v1/categories', ['name' => 'First'])->assertCreated();

        $this->postJson('/api/v1/categories', ['name' => 'Second'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $categoryId = Category::query()->where('tenant_id', $this->owner->tenant_id)->value('id');

        $this->postJson('/api/v1/products', [
            'name' => 'Only Product',
            'category_id' => $categoryId,
            'uom' => 'pcs',
        ])->assertCreated();

        $this->postJson('/api/v1/products', [
            'name' => 'Extra Product',
            'category_id' => $categoryId,
            'uom' => 'pcs',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_tenant_usage_shows_live_catalog_counts(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Usage Cat');

        $this->postJson('/api/v1/products', [
            'name' => 'Usage Product',
            'category_id' => $categoryId,
            'uom' => 'pcs',
        ])->assertCreated();

        $this->getJson('/api/v1/tenant/usage')
            ->assertOk()
            ->assertJsonPath('usage.categories.current', 1)
            ->assertJsonPath('usage.products.current', 1)
            ->assertJsonPath('usage.categories.max', $this->defaultStore($this->owner)->plan->max_categories)
            ->assertJsonPath('usage.products.max', $this->defaultStore($this->owner)->plan->max_products);
    }

    public function test_cashier_and_staff_can_manage_catalog(): void
    {
        foreach (['cashier', 'staff'] as $role) {
            $user = $this->createTenantUser($role);

            Sanctum::actingAs($user);

            $this->postJson('/api/v1/categories', [
                'name' => ucfirst($role).' Category',
            ])->assertCreated();
        }
    }

    public function test_user_without_catalog_manage_gets_forbidden(): void
    {
        Role::findByName('staff', 'web')->revokePermissionTo('catalog.manage');

        $staff = $this->createTenantUser('staff');

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/categories')->assertForbidden();
        $this->postJson('/api/v1/categories', ['name' => 'Blocked'])->assertForbidden();
    }

    public function test_product_uom_min_stock_and_low_stock_filter(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Grocery');

        $riceId = $this->createProduct($categoryId, [
            'name' => 'Rice',
            'manage_inventory' => true,
            'min_stock_quantity' => 10,
            'uom' => 'kg',
        ]);

        $this->seedStock($riceId, 8, 50, now()->addYear()->toDateString());

        $this->getJson("/api/v1/products/{$riceId}")
            ->assertOk()
            ->assertJsonPath('uom', 'kg')
            ->assertJsonPath('fractional_qty', true)
            ->assertJsonPath('is_low_stock', true);

        $biscuitId = $this->createProduct($categoryId, [
            'name' => 'Biscuit',
            'manage_inventory' => true,
            'min_stock_quantity' => 5,
            'uom' => 'pcs',
        ]);

        $this->seedStock($biscuitId, 50, 20);

        $this->getJson("/api/v1/products/{$biscuitId}")
            ->assertOk()
            ->assertJsonPath('is_low_stock', false);

        $this->getJson('/api/v1/products?low_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rice');
    }

    public function test_invalid_uom_rejected_on_create(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Test');

        $this->postJson('/api/v1/products', [
            'name' => 'Bad UOM',
            'category_id' => $categoryId,
            'uom' => 'gallon',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['uom']);
    }

    public function test_expired_product_filter(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Perishables');

        $expiredId = $this->createProduct($categoryId, [
            'name' => 'Expired Milk',
            'uom' => 'L',
        ]);

        $this->seedStock($expiredId, 5, 40, now()->subDay()->toDateString());

        $this->getJson("/api/v1/products/{$expiredId}")
            ->assertOk()
            ->assertJsonPath('is_expired', true);

        $freshId = $this->createProduct($categoryId, [
            'name' => 'Fresh Milk',
            'uom' => 'L',
        ]);

        $this->seedStock($freshId, 5, 40, now()->addWeek()->toDateString());

        $this->getJson('/api/v1/products?expired=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Expired Milk')
            ->assertJsonPath('data.0.is_expired', true);
    }

    public function test_owner_cannot_access_another_tenants_catalog_records(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Private');
        $supplierId = $this->createSupplier('Private Sup');
        $brandId = $this->createBrand('Private Brand');
        $productId = $this->createProduct($categoryId, [
            'supplier_id' => $supplierId,
            'brand_id' => $brandId,
        ]);

        $otherOwner = $this->registerOtherOwner('8801712345910');

        Sanctum::actingAs($otherOwner);

        $this->getJson("/api/v1/categories/{$categoryId}")->assertNotFound();
        $this->getJson("/api/v1/suppliers/{$supplierId}")->assertNotFound();
        $this->getJson("/api/v1/brands/{$brandId}")->assertNotFound();
        $this->getJson("/api/v1/products/{$productId}")->assertNotFound();
    }

    private function createCategory(string $name): int
    {
        Sanctum::actingAs($this->owner);

        return (int) $this->postJson('/api/v1/categories', ['name' => $name])
            ->assertCreated()
            ->json('id');
    }

    private function createSupplier(string $name): int
    {
        Sanctum::actingAs($this->owner);

        return (int) $this->postJson('/api/v1/suppliers', ['name' => $name])
            ->assertCreated()
            ->json('id');
    }

    private function createBrand(string $name): int
    {
        Sanctum::actingAs($this->owner);

        return (int) $this->postJson('/api/v1/brands', ['name' => $name])
            ->assertCreated()
            ->json('id');
    }

    private function createProduct(int $categoryId, array $extra = []): int
    {
        Sanctum::actingAs($this->owner);

        $stockQuantity = $extra['stock_quantity'] ?? null;
        $expirationDate = $extra['expiration_date'] ?? null;
        unset($extra['stock_quantity'], $extra['expiration_date']);

        $productId = (int) $this->postJson('/api/v1/products', array_merge([
            'name' => 'Test Product',
            'category_id' => $categoryId,
            'uom' => 'pcs',
        ], $extra))
            ->assertCreated()
            ->json('id');

        if ($stockQuantity !== null && (float) $stockQuantity > 0) {
            $this->seedStock(
                $productId,
                (float) $stockQuantity,
                (float) ($extra['cost_price'] ?? 0),
                $expirationDate,
            );
        }

        return $productId;
    }

    private function seedStock(
        int $productId,
        float $quantity,
        float $unitCost = 0,
        ?string $expirationDate = null,
    ): void {
        Sanctum::actingAs($this->owner);

        $payload = [
            'product_id' => $productId,
            'quantity_delta' => $quantity,
            'unit_cost' => $unitCost,
            'reason' => 'Test seed',
        ];

        if ($expirationDate !== null) {
            $payload['expiration_date'] = $expirationDate;
        }

        $this->postJson('/api/v1/stock-adjustments', $payload)->assertCreated();
    }

    private function createTenantUser(string $role): User
    {
        $mobile = match ($role) {
            'cashier' => '8801712345901',
            'staff' => '8801712345902',
            default => '8801712345999',
        };

        return $this->createBranchUser($this->owner, $role, $mobile);
    }

    private function registerOtherOwner(string $mobile): User
    {
        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Other Catalog Shop',
            'owner_name' => 'Other Owner',
            'mobile' => $mobile,
            'pin' => '123456',
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }
}
