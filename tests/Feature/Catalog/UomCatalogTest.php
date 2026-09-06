<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\StoreUom;
use App\Models\StoreUomConversion;
use App\Models\User;
use App\Support\Uom;
use App\Support\UomConverter;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UomCatalogTest extends TestCase
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
            'shop_name' => 'UOM Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345911',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345911')->firstOrFail();
    }

    public function test_registered_store_is_seeded_with_default_uoms_and_conversions(): void
    {
        Sanctum::actingAs($this->owner);

        $store = $this->defaultStore($this->owner);

        $this->assertSame(count(Uom::all()), StoreUom::query()->where('store_id', $store->id)->count());
        $this->assertSame(
            count(config('retail360.uom_conversions', [])),
            StoreUomConversion::query()->where('store_id', $store->id)->count(),
        );

        $response = $this->getJson('/api/v1/uoms')
            ->assertOk()
            ->assertJsonCount(count(Uom::all()), 'data');

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('pcs', $codes);
        $this->assertContains('kg', $codes);

        $conversions = $response->json('meta.conversions');
        $this->assertCount(2, $conversions);
        $this->assertSame('kg', $conversions[0]['from_code']);
        $this->assertSame('g', $conversions[0]['to_code']);
    }

    public function test_owner_can_create_custom_uom_and_use_it_on_product(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/uoms', [
            'code' => 'carton',
            'label' => 'Carton',
            'fractional' => false,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('code', 'carton');

        $categoryId = $this->createCategory('General');

        $this->postJson('/api/v1/products', [
            'name' => 'Canned Goods',
            'category_id' => $categoryId,
            'selling_price' => 500,
            'uom' => 'carton',
        ])->assertCreated()
            ->assertJsonPath('uom', 'carton')
            ->assertJsonPath('uom_label', 'Carton');

        $this->postJson('/api/v1/products', [
            'name' => 'Bad UOM',
            'category_id' => $categoryId,
            'selling_price' => 100,
            'uom' => 'gallon',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['uom']);
    }

    public function test_system_uom_cannot_be_deleted_and_custom_uom_blocked_when_in_use(): void
    {
        Sanctum::actingAs($this->owner);

        $pcs = StoreUom::query()
            ->where('store_id', $this->defaultStore($this->owner)->id)
            ->where('code', 'pcs')
            ->firstOrFail();

        $this->deleteJson("/api/v1/uoms/{$pcs->id}")
            ->assertUnprocessable();

        $custom = $this->postJson('/api/v1/uoms', [
            'code' => 'crate',
            'label' => 'Crate',
        ])->assertCreated()
            ->json();

        $categoryId = $this->createCategory('Crates');

        $this->postJson('/api/v1/products', [
            'name' => 'Crate Item',
            'category_id' => $categoryId,
            'selling_price' => 50,
            'uom' => 'crate',
        ])->assertCreated();

        $this->deleteJson("/api/v1/uoms/{$custom['id']}")
            ->assertUnprocessable();
    }

    public function test_conversion_pairs_can_be_created_and_duplicate_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $store = $this->defaultStore($this->owner);

        $carton = $this->postJson('/api/v1/uoms', [
            'code' => 'carton',
            'label' => 'Carton',
        ])->assertCreated()
            ->json();

        $pcs = StoreUom::query()
            ->where('store_id', $store->id)
            ->where('code', 'pcs')
            ->firstOrFail();

        $conversion = $this->postJson('/api/v1/uom-conversions', [
            'from_uom_id' => $carton['id'],
            'to_uom_id' => $pcs->id,
            'factor' => 12,
        ])->assertCreated()
            ->assertJsonPath('factor', 12)
            ->assertJsonPath('from_code', 'carton')
            ->assertJsonPath('to_code', 'pcs');

        $this->postJson('/api/v1/uom-conversions', [
            'from_uom_id' => $pcs->id,
            'to_uom_id' => $carton['id'],
            'factor' => 12,
        ])->assertUnprocessable();

        $this->deleteJson("/api/v1/uom-conversions/{$conversion->json('id')}")
            ->assertOk();
    }

    public function test_carton_pcs_conversion_math_both_ways(): void
    {
        $pairs = [
            ['from_code' => 'carton', 'to_code' => 'pcs', 'factor' => 12],
        ];

        $this->assertSame(12.0, UomConverter::toProductUomQty(1, 'pcs', 'carton', $pairs));
        $this->assertSame(1.0, UomConverter::fromProductUomQty(12, 'pcs', 'carton', $pairs));
        $this->assertSame(288.0, UomConverter::toProductUomQty(24, 'pcs', 'carton', $pairs));
        $this->assertSame(2.0, UomConverter::fromProductUomQty(24, 'pcs', 'carton', $pairs));
    }

    public function test_csv_import_accepts_store_defined_uom(): void
    {
        Sanctum::actingAs($this->owner);

        Storage::fake('local');

        $this->postJson('/api/v1/uoms', [
            'code' => 'carton',
            'label' => 'Carton',
        ])->assertCreated();

        $file = UploadedFile::fake()->createWithContent(
            'products.csv',
            implode("\n", [
                'name,sku,barcode,description,category,supplier,brand,selling_price,cost_price,uom,vat_rate,vat_type,min_stock_quantity,is_active,is_negotiable,ask_qty_on_add,manage_inventory',
                'CSV Carton,CART-1,,,Import,,,99,,carton,,,,1,0,0,0',
            ])."\n"
        );

        $this->post('/api/v1/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertDatabaseHas('products', [
            'name' => 'CSV Carton',
            'uom' => 'carton',
        ]);
    }

    public function test_sync_pull_includes_uoms_and_conversions(): void
    {
        Sanctum::actingAs($this->owner);

        $deviceId = '11111111-1111-1111-1111-111111111111';

        $this->postJson('/api/v1/devices', [
            'device_id' => $deviceId,
            'name' => 'POS',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/sync/pull?'.http_build_query([
            'since' => '2020-01-01T00:00:00Z',
            'device_id' => $deviceId,
            'include' => 'uoms,uom_conversions',
        ]))->assertOk();

        $this->assertNotEmpty($response->json('uoms'));
        $this->assertNotEmpty($response->json('uom_conversions'));
        $this->assertArrayHasKey('uuid', $response->json('uoms.0'));
        $this->assertArrayHasKey('from_code', $response->json('uom_conversions.0'));
    }

    private function createCategory(string $name): int
    {
        return (int) $this->postJson('/api/v1/categories', [
            'name' => $name,
        ])->assertCreated()
            ->json('id');
    }
}
