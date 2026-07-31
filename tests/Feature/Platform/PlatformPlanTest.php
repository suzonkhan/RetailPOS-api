<?php

namespace Tests\Feature\Platform;

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformPlanTest extends TestCase
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

    public function test_unauthenticated_cannot_list_platform_plans(): void
    {
        $this->getJson('/api/v1/platform/plans')
            ->assertUnauthorized();
    }

    public function test_tenant_owner_cannot_list_platform_plans(): void
    {
        $owner = $this->registerOwner();

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/platform/plans')
            ->assertForbidden();
    }

    public function test_super_admin_lists_all_plans_including_inactive(): void
    {
        Plan::query()->where('slug', 'startup-pro')->update(['is_active' => false]);
        $admin = $this->createSuperAdmin('8801711111201');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/plans');

        $response->assertOk();
        $response->assertJsonCount(3);
        $this->assertFalse(collect($response->json())->firstWhere('slug', 'startup-pro')['is_active']);
    }

    public function test_super_admin_can_create_plan(): void
    {
        $admin = $this->createSuperAdmin('8801711111202');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/platform/plans', [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'monthly_price' => 199,
            'max_users' => 20,
            'max_categories' => 100,
            'max_products' => 5000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Enterprise')
            ->assertJsonPath('slug', 'enterprise')
            ->assertJsonPath('yearly_price', 2388)
            ->assertJsonPath('is_active', true);

        $this->assertDatabaseHas('plans', [
            'slug' => 'enterprise',
            'monthly_price' => 199,
            'yearly_price' => 2388,
        ]);
    }

    public function test_super_admin_can_update_plan_pricing_and_limits(): void
    {
        $plan = Plan::query()->where('slug', 'startup')->firstOrFail();
        $admin = $this->createSuperAdmin('8801711111203');

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/v1/platform/plans/'.$plan->id, [
            'monthly_price' => 25,
            'max_products' => 250,
        ]);

        $response->assertOk()
            ->assertJsonPath('monthly_price', 25)
            ->assertJsonPath('yearly_price', 300)
            ->assertJsonPath('max_products', 250);
    }

    public function test_super_admin_cannot_deactivate_plan_with_tenants(): void
    {
        $this->registerOwner('8801712345690');
        $plan = Plan::query()->where('slug', 'startup')->firstOrFail();
        $admin = $this->createSuperAdmin('8801711111204');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/platform/plans/'.$plan->id, [
            'is_active' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_super_admin_can_deactivate_unused_plan(): void
    {
        $plan = Plan::query()->where('slug', 'startup-pro')->firstOrFail();
        $admin = $this->createSuperAdmin('8801711111205');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/platform/plans/'.$plan->id, [
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_public_plans_endpoint_excludes_inactive_plans(): void
    {
        Plan::query()->where('slug', 'startup-pro')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk();
        $response->assertJsonCount(2);
        $this->assertNull(collect($response->json())->firstWhere('slug', 'startup-pro'));
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
