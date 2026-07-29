<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacTest extends TestCase
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

    public function test_staff_cannot_access_sales_report(): void
    {
        $staff = $this->createTenantUser('staff', '8801712345690', '8801712345681');

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/reports/sales-summary')
            ->assertForbidden();
    }

    public function test_cashier_cannot_access_sales_report(): void
    {
        $cashier = $this->createTenantUser('cashier', '8801712345691', '8801712345682');

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/reports/sales-summary')
            ->assertForbidden();
    }

    public function test_owner_can_access_sales_report(): void
    {
        $owner = $this->registerOwner('8801712345695');

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/reports/sales-summary')
            ->assertOk()
            ->assertJsonPath('currency', 'BDT');
    }

    public function test_staff_cannot_access_subscription(): void
    {
        $staff = $this->createTenantUser('staff', '8801712345692', '8801712345683');

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/tenant/subscription')
            ->assertForbidden();
    }

    public function test_owner_can_access_subscription(): void
    {
        $owner = $this->registerOwner('8801712345693');

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/tenant/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.is_trial', true);
    }

    public function test_cashier_cannot_manage_users(): void
    {
        $cashier = $this->createTenantUser('cashier', '8801712345694', '8801712345684');

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    private function registerOwner(string $mobile): User
    {
        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'RBAC Shop',
            'owner_name' => 'Owner',
            'mobile' => $mobile,
            'pin' => '123456',
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }

    private function createTenantUser(string $role, string $mobile, string $ownerMobile): User
    {
        $owner = $this->registerOwner($ownerMobile);

        $owner->tenant->update([
            'plan_id' => \App\Models\Plan::query()->where('slug', 'startup-plus')->value('id'),
        ]);

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => ucfirst($role).' User',
            'mobile' => $mobile,
            'pin' => '654321',
            'role' => $role,
        ]);

        $response->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }
}
