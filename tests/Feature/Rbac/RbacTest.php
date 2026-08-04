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

        $this->getJson('/api/v1/reports/sales-summary')->assertOk();
    }

    public function test_staff_cannot_access_subscription(): void
    {
        $staff = $this->createTenantUser('staff', '8801712345692', '8801712345683');

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/tenant/subscription')->assertForbidden();
    }

    public function test_owner_can_access_subscription(): void
    {
        $owner = $this->registerOwner('8801712345693');

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/tenant/subscription')->assertOk();
    }

    public function test_cashier_cannot_manage_users(): void
    {
        $cashier = $this->createTenantUser('cashier', '8801712345694', '8801712345684');

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    private function createTenantUser(string $role, string $mobile, string $ownerMobile): User
    {
        $owner = $this->registerOwner($ownerMobile);

        return $this->createBranchUser($owner, $role, $mobile);
    }
}
