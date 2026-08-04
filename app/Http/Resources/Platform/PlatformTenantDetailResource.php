<?php

namespace App\Http\Resources\Platform;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\CatalogPlanLimitService;
use App\Services\Platform\PlatformTenantService;
use App\Services\Users\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class PlatformTenantDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = app(PlatformTenantService::class)->findOwner($this->resource);
        $branches = $this->stores()->with('plan')->orderByDesc('is_default')->orderBy('name')->get();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'created_at' => $this->created_at?->toIso8601String(),
            'owner' => $owner ? [
                'name' => $owner->name,
                'mobile' => $owner->mobile,
            ] : null,
            'branches' => $branches->map(fn (Store $branch) => $this->branchPayload($branch))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchPayload(Store $branch): array
    {
        $branch->syncSubscriptionStatus();
        $branch->refresh();

        $plan = $branch->plan;
        $limits = app(CatalogPlanLimitService::class);
        $userService = app(UserService::class);

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'is_default' => $branch->is_default,
            'status' => $branch->status,
            'trial_ends_at' => $branch->trial_ends_at?->toIso8601String(),
            'trial_days_remaining' => $branch->trialDaysRemaining(),
            'subscribed_at' => $branch->subscribed_at?->toIso8601String(),
            'current_period_ends_at' => $branch->current_period_ends_at?->toIso8601String(),
            'billing_cycle' => $branch->billing_cycle,
            'is_trial' => $branch->isOnTrial(),
            'requires_payment' => $branch->requiresSubscriptionPayment(),
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
            ] : null,
            'usage' => $plan ? [
                'users' => [
                    'current' => $userService->branchUserCount($branch),
                    'max' => $plan->max_users,
                ],
                'categories' => [
                    'current' => $limits->categoryCount($branch),
                    'max' => $plan->max_categories,
                ],
                'products' => [
                    'current' => $limits->productCount($branch),
                    'max' => $plan->max_products,
                ],
            ] : null,
        ];
    }
}
