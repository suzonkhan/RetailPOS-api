<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductBarcodeLookupTest extends TestCase
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
            'shop_name' => 'Lookup Shop A',
            'owner_name' => 'Owner A',
            'mobile' => '8801712345920',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345920')->firstOrFail();
    }

    public function test_barcode_lookup_requires_barcode(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/products/barcode-lookup')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['barcode']);
    }

    public function test_barcode_lookup_returns_empty_when_not_found(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/products/barcode-lookup?barcode=9999999999999')
            ->assertOk()
            ->assertJsonPath('meta.barcode', '9999999999999')
            ->assertJsonPath('meta.count', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_barcode_lookup_returns_single_match_from_own_store(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategoryFor($this->owner, 'Snacks');
        $this->createProductFor($this->owner, $categoryId, [
            'name' => 'Mango Juice',
            'barcode' => '8801000000099',
            'selling_price' => 120,
            'sku' => 'MJ-099',
            'uom' => 'pcs',
            'vat_rate' => 5,
            'vat_type' => 'percent',
        ]);

        $response = $this->getJson('/api/v1/products/barcode-lookup?barcode=8801000000099')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mango Juice')
            ->assertJsonPath('data.0.barcode', '8801000000099')
            ->assertJsonPath('data.0.selling_price', 120)
            ->assertJsonPath('data.0.category_name', 'Snacks')
            ->assertJsonPath('data.0.sku', 'MJ-099')
            ->assertJsonPath('data.0.uom', 'pcs')
            ->assertJsonPath('data.0.vat_rate', 5)
            ->assertJsonPath('data.0.is_own_store', true)
            ->assertJsonPath('data.0.source', 'product');

        $this->assertNotEmpty($response->json('data.0.store_name'));
    }

    public function test_barcode_lookup_returns_matches_from_different_stores(): void
    {
        Sanctum::actingAs($this->owner);
        $categoryA = $this->createCategoryFor($this->owner, 'Drinks A');
        $this->createProductFor($this->owner, $categoryA, [
            'name' => 'Cola 500ml',
            'barcode' => '8801000000088',
            'selling_price' => 40,
        ]);

        $other = $this->registerOwner('8801712345921', [
            'shop_name' => 'Lookup Shop B',
            'owner_name' => 'Owner B',
        ]);
        Sanctum::actingAs($other);
        $categoryB = $this->createCategoryFor($other, 'Beverages B');
        $this->createProductFor($other, $categoryB, [
            'name' => 'Cola Soft Drink',
            'barcode' => '8801000000088',
            'selling_price' => 45,
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/products/barcode-lookup?barcode=8801000000088')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonCount(2, 'data');

        $names = collect($response->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Cola 500ml', 'Cola Soft Drink'], $names);

        $categories = collect($response->json('data'))->pluck('category_name')->sort()->values()->all();
        $this->assertSame(['Beverages B', 'Drinks A'], $categories);

        $ownFlags = collect($response->json('data'))->pluck('is_own_store')->sort()->values()->all();
        $this->assertSame([false, true], $ownFlags);
    }

    public function test_barcode_lookup_includes_variant_matches(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategoryFor($this->owner, 'Apparel');

        $sizeAttr = $this->postJson('/api/v1/variation-attributes', [
            'name' => 'Size',
            'values' => ['M'],
        ])->assertCreated();

        $productId = $this->createProductFor($this->owner, $categoryId, [
            'name' => 'T-Shirt',
            'selling_price' => 500,
        ]);

        $setup = $this->putJson("/api/v1/products/{$productId}/variations/setup", [
            'has_variants' => true,
            'attribute_value_ids' => [$sizeAttr->json('values.0.id')],
        ])->assertOk();

        $variantId = $setup->json('variants.0.id');

        $this->putJson("/api/v1/products/{$productId}/variants/{$variantId}", [
            'barcode' => '8801000000077',
            'selling_price' => 550,
            'is_active' => true,
        ])->assertOk();

        $response = $this->getJson('/api/v1/products/barcode-lookup?barcode=8801000000077')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.source', 'variant')
            ->assertJsonPath('data.0.selling_price', 550)
            ->assertJsonPath('data.0.category_name', 'Apparel')
            ->assertJsonPath('data.0.is_own_store', true);

        $this->assertStringContainsString('T-Shirt', (string) $response->json('data.0.name'));
    }

    public function test_barcode_lookup_requires_catalog_manage(): void
    {
        Role::findByName('staff', 'web')->revokePermissionTo('catalog.manage');

        $staff = $this->createBranchUser($this->owner, 'staff', '8801712345922');

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/products/barcode-lookup?barcode=8801000000099')
            ->assertForbidden();
    }

    public function test_barcode_lookup_ignores_inactive_products(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategoryFor($this->owner, 'Inactive Cat');
        $productId = $this->createProductFor($this->owner, $categoryId, [
            'name' => 'Hidden Item',
            'barcode' => '8801000000066',
            'selling_price' => 10,
        ]);

        $this->putJson("/api/v1/products/{$productId}", [
            'is_active' => false,
        ])->assertOk();

        $this->getJson('/api/v1/products/barcode-lookup?barcode=8801000000066')
            ->assertOk()
            ->assertJsonPath('meta.count', 0)
            ->assertJsonCount(0, 'data');
    }

    private function createCategoryFor(User $user, string $name): int
    {
        Sanctum::actingAs($user);

        return (int) $this->postJson('/api/v1/categories', ['name' => $name])
            ->assertCreated()
            ->json('id');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createProductFor(User $user, int $categoryId, array $extra = []): int
    {
        Sanctum::actingAs($user);

        return (int) $this->postJson('/api/v1/products', array_merge([
            'name' => 'Test Product',
            'category_id' => $categoryId,
            'uom' => 'pcs',
            'selling_price' => 100,
        ], $extra))
            ->assertCreated()
            ->json('id');
    }
}
