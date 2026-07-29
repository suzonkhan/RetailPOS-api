<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductVariationTest extends TestCase
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
            'shop_name' => 'Variation Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345999',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345999')->firstOrFail();
    }

    public function test_owner_can_manage_variation_attributes(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/variation-attributes', [
            'name' => 'Size',
            'values' => ['Small', 'Large'],
        ])->assertCreated()
            ->assertJsonPath('name', 'Size');

        $attributeId = $response->json('id');

        $this->getJson('/api/v1/variation-attributes')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/variation-attributes/{$attributeId}", [
            'name' => 'Size (EU)',
        ])->assertOk()
            ->assertJsonPath('name', 'Size (EU)');
    }

    public function test_owner_can_setup_product_variation_matrix(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->postJson('/api/v1/categories', ['name' => 'Apparel'])
            ->assertCreated()
            ->json('id');

        $sizeAttr = $this->postJson('/api/v1/variation-attributes', [
            'name' => 'Size',
            'values' => ['S', 'L'],
        ])->assertCreated();

        $colorAttr = $this->postJson('/api/v1/variation-attributes', [
            'name' => 'Color',
            'values' => ['Red', 'Blue'],
        ])->assertCreated();

        $valueIds = collect($sizeAttr->json('values'))
            ->merge($colorAttr->json('values'))
            ->pluck('id')
            ->all();

        $product = $this->postJson('/api/v1/products', [
            'name' => 'Classic Tee',
            'sku' => 'TEE-001',
            'category_id' => $categoryId,
            'selling_price' => 800,
            'cost_price' => 500,
            'uom' => 'pcs',
        ])->assertCreated();

        $productId = $product->json('id');

        $setup = $this->putJson("/api/v1/products/{$productId}/variations/setup", [
            'has_variants' => true,
            'attribute_value_ids' => $valueIds,
        ])->assertOk();

        $this->assertTrue($setup->json('has_variants'));
        $this->assertCount(4, $setup->json('variants'));

        $productModel = Product::query()->findOrFail($productId);
        $this->assertTrue($productModel->has_variants);
        $this->assertSame(4, ProductVariant::query()->where('product_id', $productId)->count());
    }

    public function test_sale_requires_variant_for_variant_product(): void
    {
        Sanctum::actingAs($this->owner);

        [$productId, $variantId] = $this->createVariantProductWithStock();

        $paymentMethodId = $this->createPaymentMethod('Cash');

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $paymentMethodId, 'amount' => 800],
            ],
        ])->assertStatus(422);

        $this->postJson('/api/v1/sales', [
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'product_variant_id' => $variantId, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method_id' => $paymentMethodId, 'amount' => 800],
            ],
        ])->assertCreated();

        $variant = ProductVariant::query()->findOrFail($variantId);
        $this->assertEquals(4.0, (float) $variant->fresh()->stock_quantity);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function createVariantProductWithStock(): array
    {
        $categoryId = $this->postJson('/api/v1/categories', ['name' => 'Shirts'])->json('id');

        $sizeAttr = $this->postJson('/api/v1/variation-attributes', [
            'name' => 'Size',
            'values' => ['M'],
        ]);

        $valueId = $sizeAttr->json('values.0.id');

        $productId = $this->postJson('/api/v1/products', [
            'name' => 'Polo',
            'sku' => 'POLO-1',
            'category_id' => $categoryId,
            'selling_price' => 800,
            'cost_price' => 500,
            'uom' => 'pcs',
        ])->json('id');

        $setup = $this->putJson("/api/v1/products/{$productId}/variations/setup", [
            'has_variants' => true,
            'attribute_value_ids' => [$valueId],
        ]);

        $variantId = $setup->json('variants.0.id');

        $this->putJson("/api/v1/products/{$productId}/variants/bulk", [
            'variants' => [
                [
                    'id' => $variantId,
                    'sku' => 'POLO-1-M',
                    'stock_quantity' => 5,
                ],
            ],
        ])->assertOk();

        return [(int) $productId, (int) $variantId];
    }

    private function createPaymentMethod(string $name): int
    {
        return (int) $this->postJson('/api/v1/payment-methods', [
            'name' => $name,
            'sort_order' => 1,
        ])->assertCreated()->json('id');
    }
}
