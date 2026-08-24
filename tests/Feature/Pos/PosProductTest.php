<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosProductTest extends TestCase
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
            'shop_name' => 'POS Catalog Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345940',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345940')->firstOrFail();
    }

    public function test_pos_products_hides_inactive_out_of_stock_and_expired(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();

        $sellableId = $this->createProduct($categoryId, [
            'name' => 'Fresh Milk',
            'manage_inventory' => true,
            'stock_quantity' => 8,
            'expiration_date' => now()->addWeek()->toDateString(),
        ]);

        $this->createProduct($categoryId, [
            'name' => 'No Date Soap',
            'manage_inventory' => true,
            'stock_quantity' => 4,
        ]);

        $this->createProduct($categoryId, [
            'name' => 'Service Fee',
            'manage_inventory' => false,
        ]);

        $this->createProduct($categoryId, [
            'name' => 'Expired Yogurt',
            'manage_inventory' => true,
            'stock_quantity' => 5,
            'expiration_date' => now()->subDay()->toDateString(),
        ]);

        $this->createProduct($categoryId, [
            'name' => 'Out of Stock Rice',
            'manage_inventory' => true,
        ]);

        $this->createProduct($categoryId, [
            'name' => 'Inactive Tea',
            'manage_inventory' => true,
            'stock_quantity' => 10,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/pos/products?per_page=50')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(
            ['Fresh Milk', 'No Date Soap', 'Service Fee'],
            $names
        );
        $this->assertContains($sellableId, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_pos_products_keeps_item_when_fresh_lots_remain_beside_expired(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $productId = $this->createProduct($categoryId, [
            'name' => 'Mixed Lots Oil',
            'manage_inventory' => true,
            'stock_quantity' => 3,
            'expiration_date' => now()->subDay()->toDateString(),
        ]);

        $this->seedStock($productId, 5, 20, now()->addMonth()->toDateString());

        $this->getJson('/api/v1/pos/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mixed Lots Oil')
            ->assertJsonPath('data.0.stock_quantity', 5)
            ->assertJsonPath('data.0.is_expired', false);
    }

    public function test_pos_products_supports_search_and_category_filter(): void
    {
        Sanctum::actingAs($this->owner);

        $groceryId = $this->createCategory('Grocery');
        $snackId = $this->createCategory('Snacks');

        $this->createProduct($groceryId, [
            'name' => 'Rice 5kg',
            'sku' => 'RICE-5',
            'barcode' => '8801000000001',
            'manage_inventory' => true,
            'stock_quantity' => 10,
        ]);
        $this->createProduct($snackId, [
            'name' => 'Chips',
            'manage_inventory' => true,
            'stock_quantity' => 10,
        ]);

        $this->getJson('/api/v1/pos/products?search=RICE-5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rice 5kg');

        $this->getJson('/api/v1/pos/products?category_id='.$snackId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Chips');
    }

    public function test_pos_products_is_scoped_to_tenant(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->createCategory();
        $this->createProduct($categoryId, [
            'name' => 'Owner Item',
            'manage_inventory' => true,
            'stock_quantity' => 2,
        ]);

        $other = $this->registerOwner('8801712345941', [
            'shop_name' => 'Other POS Shop',
            'owner_name' => 'Other Owner',
        ]);

        Sanctum::actingAs($other);

        $this->getJson('/api/v1/pos/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function createCategory(string $name = 'Grocery'): int
    {
        Sanctum::actingAs($this->owner);

        return (int) $this->postJson('/api/v1/categories', ['name' => $name])
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
}
