<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCsvTest extends TestCase
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

        $this->owner = $this->registerOwner('8801712345800');
    }

    public function test_owner_can_download_import_template(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->get('/api/v1/products/import/template')
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('name,sku,barcode', $response->streamedContent());
    }

    public function test_owner_can_export_branch_products(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Grocery');
        $this->createProduct($categoryId, [
            'name' => 'Rice 5kg',
            'sku' => 'RICE-5',
            'selling_price' => 550,
            'uom' => 'kg',
        ]);

        $csv = $this->get('/api/v1/products/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Rice 5kg', $csv);
        $this->assertStringContainsString('RICE-5', $csv);
        $this->assertStringContainsString('Grocery', $csv);
    }

    public function test_import_creates_products_on_current_branch(): void
    {
        Sanctum::actingAs($this->owner);

        $file = $this->csvFile([
            ProductCsvTest::headers(),
            ['Tea', 'TEA-1', '8801999000001', '', 'Beverages', 'Local Supplier', 'House', '40', '25', 'pcs', '5', 'percent', '5', '1', '0', '0', '1'],
        ]);

        $this->post('/api/v1/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('errors', []);

        $store = $this->defaultStore($this->owner);

        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'TEA-1',
            'name' => 'Tea',
            'selling_price' => 40,
        ]);

        $this->assertDatabaseHas('categories', [
            'store_id' => $store->id,
            'name' => 'Beverages',
        ]);
    }

    public function test_import_upserts_by_sku_on_same_branch(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory('Snacks');
        $this->createProduct($categoryId, [
            'name' => 'Chips',
            'sku' => 'CHIP-1',
            'selling_price' => 20,
        ]);

        $file = $this->csvFile([
            self::headers(),
            ['Potato Chips', 'CHIP-1', '', '', 'Snacks', '', '', '25', '', 'pcs', '', '', '', '1', '0', '0', '0'],
        ]);

        $this->post('/api/v1/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('products', [
            'sku' => 'CHIP-1',
            'name' => 'Potato Chips',
            'selling_price' => 25,
        ]);
    }

    public function test_import_can_copy_catalog_to_another_branch(): void
    {
        Sanctum::actingAs($this->owner);
        $this->activateDefaultBranch($this->owner, 'startup-plus');

        $branchOne = $this->defaultStore($this->owner);
        $categoryId = $this->createCategory('Dairy');
        $this->createProduct($categoryId, [
            'name' => 'Milk 1L',
            'sku' => 'MILK-1',
            'barcode' => '8801888000001',
            'selling_price' => 90,
        ]);

        $branchTwo = Store::query()->create([
            'tenant_id' => $this->owner->tenant_id,
            'plan_id' => Plan::query()->where('slug', 'startup-plus')->value('id'),
            'name' => 'Branch Two',
            'status' => Store::STATUS_ACTIVE,
            'subscribed_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
            'billing_cycle' => Store::BILLING_MONTHLY,
            'is_default' => false,
        ]);

        $export = $this->withHeader('X-Branch-Id', (string) $branchOne->id)
            ->get('/api/v1/products/export')
            ->assertOk()
            ->streamedContent();

        $file = UploadedFile::fake()->createWithContent('products.csv', $export);

        $this->withHeader('X-Branch-Id', (string) $branchTwo->id)
            ->post('/api/v1/products/import', ['file' => $file], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('branch_id', $branchTwo->id);

        $this->assertDatabaseHas('products', [
            'store_id' => $branchTwo->id,
            'sku' => 'MILK-1',
            'name' => 'Milk 1L',
        ]);

        $this->assertSame(
            1,
            Product::query()->where('store_id', $branchOne->id)->where('sku', 'MILK-1')->count()
        );
        $this->assertSame(
            1,
            Product::query()->where('store_id', $branchTwo->id)->where('sku', 'MILK-1')->count()
        );
    }

    public function test_export_is_scoped_to_x_branch_id(): void
    {
        Sanctum::actingAs($this->owner);
        $this->activateDefaultBranch($this->owner, 'startup-plus');

        $branchOne = $this->defaultStore($this->owner);
        $categoryOne = $this->createCategory('A');
        $this->createProduct($categoryOne, ['name' => 'Only Branch One', 'sku' => 'B1']);

        $branchTwo = Store::query()->create([
            'tenant_id' => $this->owner->tenant_id,
            'plan_id' => Plan::query()->where('slug', 'startup-plus')->value('id'),
            'name' => 'Branch Two',
            'status' => Store::STATUS_ACTIVE,
            'subscribed_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
            'billing_cycle' => Store::BILLING_MONTHLY,
            'is_default' => false,
        ]);

        Category::query()->create([
            'tenant_id' => $this->owner->tenant_id,
            'store_id' => $branchTwo->id,
            'name' => 'B',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $categoryTwoId = Category::query()->where('store_id', $branchTwo->id)->value('id');

        Product::query()->create([
            'tenant_id' => $this->owner->tenant_id,
            'store_id' => $branchTwo->id,
            'category_id' => $categoryTwoId,
            'name' => 'Only Branch Two',
            'sku' => 'B2',
            'barcode' => '8801777000002',
            'selling_price' => 10,
            'uom' => 'pcs',
            'is_active' => true,
        ]);

        $csv = $this->withHeader('X-Branch-Id', (string) $branchTwo->id)
            ->get('/api/v1/products/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Only Branch Two', $csv);
        $this->assertStringNotContainsString('Only Branch One', $csv);
        $this->assertSame($branchOne->id, $this->defaultStore($this->owner)->id);
    }

    public function test_import_reports_row_errors(): void
    {
        Sanctum::actingAs($this->owner);

        $file = $this->csvFile([
            self::headers(),
            ['', '', '', '', '', '', '', '', '', 'pcs', '', '', '', '1', '0', '0', '0'],
        ]);

        $this->post('/api/v1/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('errors.0.row', 2);
    }

    /**
     * @return list<string>
     */
    private static function headers(): array
    {
        return [
            'name', 'sku', 'barcode', 'description', 'category', 'supplier', 'brand',
            'selling_price', 'cost_price', 'uom', 'vat_rate', 'vat_type',
            'min_stock_quantity', 'is_active', 'is_negotiable', 'ask_qty_on_add', 'manage_inventory',
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csvFile(array $rows): UploadedFile
    {
        $lines = array_map(fn (array $row) => implode(',', $row), $rows);

        return UploadedFile::fake()->createWithContent(
            'products.csv',
            implode("\n", $lines)."\n"
        );
    }

    private function createCategory(string $name): int
    {
        return (int) $this->postJson('/api/v1/categories', [
            'name' => $name,
        ])->assertCreated()->json('id');
    }

    private function createProduct(int $categoryId, array $extra = []): int
    {
        return (int) $this->postJson('/api/v1/products', array_merge([
            'name' => 'Test Product',
            'category_id' => $categoryId,
            'uom' => 'pcs',
        ], $extra))->assertCreated()->json('id');
    }
}
