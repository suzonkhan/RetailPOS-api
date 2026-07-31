<?php

namespace Tests\Feature\Platform;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformTenantTest extends TestCase
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

    public function test_unauthenticated_cannot_list_tenants(): void
    {
        $this->getJson('/api/v1/platform/tenants')
            ->assertUnauthorized();
    }

    public function test_tenant_owner_cannot_list_platform_tenants(): void
    {
        $owner = $this->registerOwner();

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/platform/tenants')
            ->assertForbidden();
    }

    public function test_super_admin_lists_tenants(): void
    {
        $owner = $this->registerOwner('8801712345601');
        $admin = $this->createSuperAdmin('8801711111111');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/tenants');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($owner->tenant->name, $response->json('data.0.name'));
        $this->assertSame('8801712345601', $response->json('data.0.owner.mobile'));
    }

    public function test_super_admin_can_filter_tenants_by_status(): void
    {
        $owner = $this->registerOwner('8801712345602');
        $owner->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->registerOwner('8801712345603');
        $admin = $this->createSuperAdmin('8801711111112');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/tenants?status=suspended');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(Tenant::STATUS_SUSPENDED, $response->json('data.0.status'));
    }

    public function test_super_admin_shows_tenant_detail_with_usage(): void
    {
        $owner = $this->registerOwner('8801712345604');
        $admin = $this->createSuperAdmin('8801711111113');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/platform/tenants/'.$owner->tenant_id)
            ->assertOk()
            ->assertJsonPath('id', $owner->tenant_id)
            ->assertJsonPath('owner.mobile', '8801712345604')
            ->assertJsonPath('usage.users.current', 1)
            ->assertJsonPath('usage.users.max', $owner->tenant->plan->max_users)
            ->assertJsonPath('plan.slug', 'startup');
    }

    public function test_super_admin_can_suspend_tenant(): void
    {
        $owner = $this->registerOwner('8801712345605');
        $admin = $this->createSuperAdmin('8801711111114');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/platform/tenants/'.$owner->tenant_id, [
            'status' => Tenant::STATUS_SUSPENDED,
        ])
            ->assertOk()
            ->assertJsonPath('status', Tenant::STATUS_SUSPENDED);

        $this->assertSame(
            Tenant::STATUS_SUSPENDED,
            $owner->tenant->fresh()->status
        );
    }

    public function test_super_admin_can_activate_suspended_tenant(): void
    {
        $owner = $this->registerOwner('8801712345606');
        $owner->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $admin = $this->createSuperAdmin('8801711111115');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/platform/tenants/'.$owner->tenant_id, [
            'status' => Tenant::STATUS_ACTIVE,
        ])
            ->assertOk()
            ->assertJsonPath('status', Tenant::STATUS_TRIAL);

        $this->assertSame(
            Tenant::STATUS_TRIAL,
            $owner->tenant->fresh()->status
        );
    }

    public function test_super_admin_can_change_plan(): void
    {
        $owner = $this->registerOwner('8801712345607');
        $admin = $this->createSuperAdmin('8801711111116');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/platform/tenants/'.$owner->tenant_id, [
            'plan_slug' => 'startup-plus',
        ])
            ->assertOk()
            ->assertJsonPath('plan.slug', 'startup-plus');

        $this->assertSame(
            Plan::query()->where('slug', 'startup-plus')->value('id'),
            $owner->tenant->fresh()->plan_id
        );
    }

    public function test_super_admin_can_extend_trial_by_days(): void
    {
        $owner = $this->registerOwner('8801712345608');
        $owner->tenant->update([
            'status' => Tenant::STATUS_EXPIRED,
            'trial_ends_at' => now()->subDay(),
        ]);

        $admin = $this->createSuperAdmin('8801711111117');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/platform/tenants/'.$owner->tenant_id, [
            'extend_trial_days' => 14,
        ])
            ->assertOk()
            ->assertJsonPath('status', Tenant::STATUS_TRIAL);

        $tenant = $owner->tenant->fresh();
        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->status);
        $this->assertTrue($tenant->trial_ends_at->isFuture());
    }

    public function test_patch_requires_at_least_one_field(): void
    {
        $owner = $this->registerOwner('8801712345609');
        $admin = $this->createSuperAdmin('8801711111118');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/platform/tenants/'.$owner->tenant_id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    public function test_super_admin_can_create_tenant(): void
    {
        $admin = $this->createSuperAdmin('8801711111120');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/platform/tenants', [
            'shop_name' => 'New Platform Shop',
            'owner_name' => 'New Owner',
            'mobile' => '8801712345610',
            'pin' => '654321',
            'plan_slug' => 'startup-plus',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'New Platform Shop')
            ->assertJsonPath('owner.mobile', '8801712345610')
            ->assertJsonPath('plan.slug', 'startup-plus');

        $this->assertDatabaseHas('users', [
            'mobile' => '8801712345610',
        ]);
    }

    public function test_super_admin_can_reset_owner_pin(): void
    {
        $owner = $this->registerOwner('8801712345611');
        $admin = $this->createSuperAdmin('8801711111121');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/platform/tenants/'.$owner->tenant_id.'/owner-pin', [
            'pin' => '999999',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'mobile' => '8801712345611',
            'pin' => '999999',
        ])->assertOk();
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
