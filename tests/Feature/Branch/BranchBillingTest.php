<?php

namespace Tests\Feature\Branch;

use App\Models\Plan;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchBillingTest extends TestCase
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

    public function test_register_creates_default_branch_with_trial_plan(): void
    {
        $owner = $this->registerOwner('8801712345990');

        $store = $this->defaultStore($owner);

        $this->assertSame('My Store', $store->name);
        $this->assertTrue($store->is_default);
        $this->assertSame(Store::STATUS_TRIAL, $store->status);
        $this->assertSame('startup', $store->plan->slug);
        $this->assertTrue(Plan::query()->where('slug', 'startup')->value('is_trial_default'));
    }

    public function test_register_rejects_client_plan_slug(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'owner_name' => 'Owner',
            'mobile' => '8801712345991',
            'pin' => '123456',
            'plan_slug' => 'startup-pro',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_slug']);
    }

    public function test_trial_branch_blocks_staff_creation(): void
    {
        $owner = $this->registerOwner('8801712345992');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/users', [
            'name' => 'Staff One',
            'mobile' => '8801712345993',
            'pin' => '111111',
            'role' => 'staff',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile']);
    }

    public function test_active_branch_allows_one_staff_on_startup(): void
    {
        $owner = $this->registerOwner('8801712345994');
        $this->activateDefaultBranch($owner, 'startup');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/users', [
            'name' => 'Staff One',
            'mobile' => '8801712345995',
            'pin' => '111111',
            'role' => 'staff',
        ])->assertCreated();

        $this->postJson('/api/v1/users', [
            'name' => 'Staff Two',
            'mobile' => '8801712345996',
            'pin' => '222222',
            'role' => 'staff',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile']);
    }

    public function test_create_branch_quote_does_not_create_store(): void
    {
        $owner = $this->registerOwner('8801712345997');
        $countBefore = Store::query()->where('tenant_id', $owner->tenant_id)->count();

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/checkout/quote', [
            'intent' => SubscriptionInvoice::INTENT_CREATE_BRANCH,
            'plan_slug' => 'startup-plus',
            'billing_cycle' => 'monthly',
            'branch_meta' => [
                'name' => 'Branch Two',
                'phone' => '8801711111111',
            ],
        ])->assertOk()
            ->assertJsonPath('intent', SubscriptionInvoice::INTENT_CREATE_BRANCH);

        $this->assertSame($countBefore, Store::query()->where('tenant_id', $owner->tenant_id)->count());
    }

    public function test_renew_quote_includes_gap_billing_after_expiry(): void
    {
        $owner = $this->registerOwner('8801712345998');
        $store = $this->expireDefaultBranch($owner);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/checkout/quote', [
            'intent' => SubscriptionInvoice::INTENT_RENEW,
            'store_id' => $store->id,
            'plan_slug' => 'startup',
            'billing_cycle' => 'monthly',
        ])->assertOk();

        $this->assertGreaterThan(0, $response->json('gap_days'));
        $this->assertGreaterThan(0, $response->json('gap_amount'));
    }

    public function test_expired_branch_returns_402_on_catalog_routes(): void
    {
        $owner = $this->registerOwner('8801712345999');
        $this->expireDefaultBranch($owner);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/categories')->assertStatus(402);
    }

    public function test_staff_cannot_access_other_branch_via_header(): void
    {
        $owner = $this->registerOwner('8801712345900');
        $this->activateDefaultBranch($owner, 'startup-plus');

        $branchTwo = Store::query()->create([
            'tenant_id' => $owner->tenant_id,
            'plan_id' => Plan::query()->where('slug', 'startup-plus')->value('id'),
            'name' => 'Branch Two',
            'status' => Store::STATUS_ACTIVE,
            'subscribed_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
            'billing_cycle' => Store::BILLING_MONTHLY,
            'is_default' => false,
        ]);

        $staff = $this->createBranchUser($owner, 'staff', '8801712345901', 'startup-plus');

        Sanctum::actingAs($staff);

        $this->withHeader('X-Branch-Id', (string) $branchTwo->id)
            ->getJson('/api/v1/categories')
            ->assertForbidden();
    }

    public function test_owner_can_list_branches(): void
    {
        $owner = $this->registerOwner('8801712345902');

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/tenant/branches')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'My Store');
    }

    public function test_set_default_branch_persists_for_owner(): void
    {
        $owner = $this->registerOwner('8801712345903');
        $second = Store::query()->create([
            'tenant_id' => $owner->tenant_id,
            'plan_id' => Plan::query()->where('slug', 'startup')->value('id'),
            'name' => 'Second Branch',
            'status' => Store::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(15),
            'is_default' => false,
        ]);

        Sanctum::actingAs($owner);

        $this->patchJson('/api/v1/auth/me/default-branch', [
            'branch_id' => $second->id,
        ])->assertOk()
            ->assertJsonPath('default_branch.id', $second->id);

        $this->assertSame($second->id, $owner->fresh()->default_store_id);
    }
}
