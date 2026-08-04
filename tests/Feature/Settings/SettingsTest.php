<?php

namespace Tests\Feature\Settings;

use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsTest extends TestCase
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
            'shop_name' => 'Settings Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345800',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345800')->firstOrFail();
    }

    public function test_owner_can_get_and_update_tenant_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/tenant/settings')
            ->assertOk()
            ->assertJsonPath('name', 'My Store')
            ->assertJsonPath('vat_adjust_on_sale', false)
            ->assertJsonStructure(['uuid', 'store_id', 'updated_at']);

        $this->putJson('/api/v1/tenant/settings', [
            'name' => 'Updated Shop',
            'phone' => '8801711111111',
            'address' => 'Dhaka',
            'default_vat_percent' => 5,
            'vat_adjust_on_sale' => true,
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Shop')
            ->assertJsonPath('phone', '8801711111111')
            ->assertJsonPath('address', 'Dhaka')
            ->assertJsonPath('default_vat_percent', 5)
            ->assertJsonPath('vat_adjust_on_sale', true);

        $this->assertDatabaseHas('stores', [
            'tenant_id' => $this->owner->tenant_id,
            'name' => 'Updated Shop',
            'phone' => '8801711111111',
        ]);
    }

    public function test_cashier_can_get_and_update_tenant_settings(): void
    {
        $cashier = $this->createTenantUser('cashier');

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/tenant/settings')->assertOk();

        $this->putJson('/api/v1/tenant/settings', [
            'phone' => '8801722222222',
        ])->assertOk()
            ->assertJsonPath('phone', '8801722222222');
    }

    public function test_staff_can_get_and_update_tenant_settings(): void
    {
        $staff = $this->createTenantUser('staff');

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/tenant/settings')->assertOk();

        $this->putJson('/api/v1/tenant/settings', [
            'phone' => '8801733333333',
        ])->assertOk()
            ->assertJsonPath('phone', '8801733333333');
    }

    public function test_registration_seeds_cash_payment_method(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/payment-methods')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cash');
    }

    public function test_owner_can_crud_payment_methods(): void
    {
        Sanctum::actingAs($this->owner);

        $create = $this->postJson('/api/v1/payment-methods', [
            'name' => 'bKash',
            'is_active' => true,
            'sort_order' => 1,
            'requires_reference' => true,
        ])->assertCreated()
            ->assertJsonPath('name', 'bKash')
            ->assertJsonPath('requires_reference', true);

        $id = $create->json('id');

        $this->getJson('/api/v1/payment-methods')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/payment-methods/{$id}")
            ->assertOk()
            ->assertJsonPath('name', 'bKash');

        $this->putJson("/api/v1/payment-methods/{$id}", [
            'name' => 'bKash POS',
            'sort_order' => 2,
        ])->assertOk()
            ->assertJsonPath('name', 'bKash POS')
            ->assertJsonPath('sort_order', 2);

        $this->deleteJson("/api/v1/payment-methods/{$id}")
            ->assertOk();

        $this->assertSoftDeleted('payment_methods', ['id' => $id]);
    }

    public function test_cashier_can_list_active_payment_methods_but_not_mutate(): void
    {
        $this->assertPosUserCanListButNotMutatePaymentMethods('cashier');
    }

    public function test_staff_can_list_active_payment_methods_but_not_mutate(): void
    {
        $this->assertPosUserCanListButNotMutatePaymentMethods('staff');
    }

    private function assertPosUserCanListButNotMutatePaymentMethods(string $role): void
    {
        Sanctum::actingAs($this->owner);

        $inactive = $this->postJson('/api/v1/payment-methods', [
            'name' => 'Inactive Card',
            'is_active' => false,
        ])->assertCreated();

        $inactiveId = $inactive->json('id');

        $user = $this->createTenantUser($role);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/payment-methods')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cash')
            ->assertJsonMissing(['id' => $inactiveId]);

        $methodId = PaymentMethod::query()
            ->where('store_id', $this->defaultStore($this->owner)->id)
            ->where('name', 'Cash')
            ->value('id');

        $this->postJson('/api/v1/payment-methods', [
            'name' => 'Card',
        ])->assertForbidden();

        $this->getJson("/api/v1/payment-methods/{$methodId}")
            ->assertForbidden();

        $this->putJson("/api/v1/payment-methods/{$methodId}", [
            'name' => 'Hacked',
        ])->assertForbidden();

        $this->deleteJson("/api/v1/payment-methods/{$methodId}")
            ->assertForbidden();
    }

    public function test_owner_cannot_access_another_tenants_payment_method(): void
    {
        Sanctum::actingAs($this->owner);

        $methodId = PaymentMethod::query()
            ->where('store_id', $this->defaultStore($this->owner)->id)
            ->value('id');

        $otherOwner = $this->registerOtherOwner('8801712345810');

        Sanctum::actingAs($otherOwner);

        $this->getJson("/api/v1/payment-methods/{$methodId}")
            ->assertNotFound();
    }

    private function createTenantUser(string $role): User
    {
        $mobile = match ($role) {
            'cashier' => '8801712345801',
            'staff' => '8801712345802',
            default => '8801712345899',
        };

        return $this->createBranchUser($this->owner, $role, $mobile);
    }

    private function registerOtherOwner(string $mobile): User
    {
        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Other Shop',
            'owner_name' => 'Other Owner',
            'mobile' => $mobile,
            'pin' => '123456',
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }
}
