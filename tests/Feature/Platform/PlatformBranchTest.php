<?php

namespace Tests\Feature\Platform;

use App\Models\Store;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformBranchTest extends TestCase
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

    public function test_super_admin_lists_branches_with_owner(): void
    {
        $owner = $this->registerOwner('8801712345801', ['owner_name' => 'Branch Owner']);
        $admin = $this->createSuperAdmin('8801711111301');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/branches');

        $response->assertOk()
            ->assertJsonPath('data.0.name', $this->defaultStore($owner)->name)
            ->assertJsonPath('data.0.owner.name', 'Branch Owner')
            ->assertJsonPath('data.0.owner.mobile', '8801712345801')
            ->assertJsonPath('data.0.tenant.id', $owner->tenant_id);
    }

    public function test_super_admin_can_filter_branches_by_status(): void
    {
        $owner = $this->registerOwner('8801712345802');
        $this->defaultStore($owner)->update(['status' => Store::STATUS_SUSPENDED]);

        $this->registerOwner('8801712345803');
        $admin = $this->createSuperAdmin('8801711111302');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/branches?status=suspended');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(Store::STATUS_SUSPENDED, $response->json('data.0.status'));
    }

    public function test_super_admin_can_search_branches_by_owner_mobile(): void
    {
        $this->registerOwner('8801712345804');
        $owner = $this->registerOwner('8801712345805');
        $admin = $this->createSuperAdmin('8801711111303');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/branches?search=8801712345805');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($owner->tenant_id, $response->json('data.0.tenant.id'));
    }

    public function test_super_admin_can_filter_branches_by_plan(): void
    {
        $startupOwner = $this->registerOwner('8801712345807');
        $plusOwner = $this->registerOwner('8801712345808');
        $admin = $this->createSuperAdmin('8801711111304');

        $this->upgradeTenantPlan($plusOwner, 'startup-plus');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/branches?plan_slug=startup-plus');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('startup-plus', $response->json('data.0.plan.slug'));
        $this->assertSame($plusOwner->tenant_id, $response->json('data.0.tenant.id'));
    }

    public function test_tenant_owner_cannot_list_platform_branches(): void
    {
        $owner = $this->registerOwner('8801712345806');

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/platform/branches')
            ->assertForbidden();
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
