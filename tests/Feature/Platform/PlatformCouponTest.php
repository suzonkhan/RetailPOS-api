<?php

namespace Tests\Feature\Platform;

use App\Models\Coupon;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformCouponTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PlanSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_unauthenticated_cannot_list_platform_coupons(): void
    {
        $this->getJson('/api/v1/platform/coupons')
            ->assertUnauthorized();
    }

    public function test_tenant_owner_cannot_list_platform_coupons(): void
    {
        $owner = $this->registerOwner();

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/platform/coupons')
            ->assertForbidden();
    }

    public function test_super_admin_lists_coupons(): void
    {
        Coupon::query()->create([
            'code' => 'WELCOME10',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'is_active' => true,
        ]);

        $admin = $this->createSuperAdmin('8801711111301');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/coupons');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.code', 'WELCOME10');
    }

    public function test_super_admin_can_create_coupon(): void
    {
        $admin = $this->createSuperAdmin('8801711111302');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/platform/coupons', [
            'code' => 'save20',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 20,
            'max_uses' => 50,
            'applicable_plans' => ['startup'],
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('code', 'SAVE20')
            ->assertJsonPath('type', Coupon::TYPE_PERCENT)
            ->assertJsonPath('value', 20)
            ->assertJsonPath('max_uses', 50)
            ->assertJsonPath('applicable_plans', ['startup']);

        $this->assertDatabaseHas('coupons', [
            'code' => 'SAVE20',
            'value' => 20,
        ]);
    }

    public function test_super_admin_cannot_create_percent_coupon_over_100(): void
    {
        $admin = $this->createSuperAdmin('8801711111303');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/platform/coupons', [
            'code' => 'TOOBIG',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 150,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['value']);
    }

    public function test_super_admin_can_update_coupon(): void
    {
        $coupon = Coupon::query()->create([
            'code' => 'FLAT50',
            'type' => Coupon::TYPE_FIXED,
            'value' => 50,
            'is_active' => true,
        ]);

        $admin = $this->createSuperAdmin('8801711111304');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/platform/coupons/'.$coupon->id, [
            'value' => 75,
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('value', 75)
            ->assertJsonPath('is_active', false);
    }

    private function registerOwner(string $mobile = '8801712345699'): User
    {
        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Platform Shop '.$mobile,
            'owner_name' => 'Owner',
            'mobile' => $mobile,
            'pin' => '123456',
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }

    private function createSuperAdmin(string $mobile): User
    {
        Role::findOrCreate('super_admin', 'web');

        $user = User::query()->create([
            'name' => 'Platform Admin',
            'mobile' => $mobile,
            'pin_hash' => '123456',
            'is_platform_admin' => true,
            'tenant_id' => null,
        ]);

        $user->syncRoles(['super_admin']);

        return $user;
    }
}
