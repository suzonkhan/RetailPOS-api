<?php

namespace App\Http\Resources\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\CatalogPlanLimitService;
use App\Services\Platform\PlatformTenantService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class PlatformTenantDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->syncSubscriptionStatus();
        $this->resource->refresh();

        $plan = $this->plan;
        $owner = app(PlatformTenantService::class)->findOwner($this->resource);
        $usage = $this->usagePayload($this->resource, $plan, $owner);

        $isTrial = $this->resource->isOnTrial();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'trial_days_remaining' => $this->trialDaysRemaining(),
            'subscribed_at' => $this->subscribed_at?->toIso8601String(),
            'current_period_ends_at' => $this->current_period_ends_at?->toIso8601String(),
            'billing_cycle' => $this->billing_cycle,
            'created_at' => $this->created_at?->toIso8601String(),
            'is_trial' => $isTrial,
            'requires_payment' => $this->requiresSubscriptionPayment(),
            'store' => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ] : null,
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'monthly_price' => $plan->monthly_price,
                'yearly_price' => $plan->yearly_price,
                'max_users' => $plan->max_users,
                'max_categories' => $plan->max_categories,
                'max_products' => $plan->max_products,
            ] : null,
            'owner' => $owner ? [
                'name' => $owner->name,
                'mobile' => $owner->mobile,
            ] : null,
            'usage' => $usage,
        ];
    }

    /**
     * @return array<string, array{current: int, max: int|null}>
     */
    private function usagePayload(Tenant $tenant, $plan, ?User $owner): array
    {
        if ($plan === null) {
            return [
                'users' => ['current' => 0, 'max' => null],
                'categories' => ['current' => 0, 'max' => null],
                'products' => ['current' => 0, 'max' => null],
            ];
        }

        $limits = app(CatalogPlanLimitService::class);
        $userCount = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_platform_admin', false)
            ->count();

        return [
            'users' => [
                'current' => $userCount,
                'max' => $plan->max_users,
            ],
            'categories' => [
                'current' => $limits->categoryCount($tenant),
                'max' => $plan->max_categories,
            ],
            'products' => [
                'current' => $limits->productCount($tenant),
                'max' => $plan->max_products,
            ],
        ];
    }
}
