<?php

namespace Tests\Concerns;

use App\Models\Plan;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;

trait CreatesTenantUsers
{
    protected function registerOwner(string $mobile = '8801712345678', array $overrides = []): User
    {
        $payload = array_merge([
            'owner_name' => $overrides['owner_name'] ?? 'Owner One',
            'mobile' => $mobile,
            'pin' => '123456',
        ], $overrides);

        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail()->load(['tenant', 'defaultStore']);
    }

    protected function defaultStore(User $user): Store
    {
        return $user->defaultStore
            ?? Store::query()->where('tenant_id', $user->tenant_id)->where('is_default', true)->firstOrFail();
    }

    protected function activateDefaultBranch(User $user, ?string $planSlug = 'startup'): Store
    {
        $store = $this->defaultStore($user);
        $plan = Plan::query()->where('slug', $planSlug)->firstOrFail();

        $store->update([
            'plan_id' => $plan->id,
            'status' => Store::STATUS_ACTIVE,
            'trial_ends_at' => null,
            'subscribed_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
            'billing_cycle' => Store::BILLING_MONTHLY,
        ]);

        return $store->fresh(['plan']);
    }

    protected function expireDefaultBranch(User $user): Store
    {
        $store = $this->defaultStore($user);

        $store->update([
            'status' => Store::STATUS_EXPIRED,
            'trial_ends_at' => now()->subDay(),
            'current_period_ends_at' => null,
        ]);

        return $store->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function renewCheckoutPayload(User $user, string $planSlug = 'startup', string $billingCycle = 'monthly'): array
    {
        return [
            'intent' => SubscriptionInvoice::INTENT_RENEW,
            'store_id' => $this->defaultStore($user)->id,
            'plan_slug' => $planSlug,
            'billing_cycle' => $billingCycle,
        ];
    }

    protected function upgradeTenantPlan(User $user, string $planSlug): void
    {
        $plan = Plan::query()->where('slug', $planSlug)->firstOrFail();
        $store = $this->defaultStore($user);

        $store->update(['plan_id' => $plan->id]);
        $user->tenant?->update(['plan_id' => $plan->id]);
    }

    protected function createBranchUser(User $owner, string $role, string $mobile, string $planSlug = 'startup-plus'): User
    {
        $this->upgradeTenantPlan($owner, $planSlug);
        $this->activateDefaultBranch($owner, $planSlug);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => ucfirst($role).' User',
            'mobile' => $mobile,
            'pin' => '654321',
            'role' => $role,
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail()->load('stores');
    }
}
