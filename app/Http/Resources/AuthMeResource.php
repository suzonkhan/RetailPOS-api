<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class AuthMeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this->tenant;
        $plan = $tenant?->plan;

        return [
            'user' => [
                'id' => $this->id,
                'name' => $this->name,
                'mobile' => $this->mobile,
                'is_platform_admin' => $this->is_platform_admin,
            ],
            'role' => $this->primaryRole(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
            ] : null,
            'store' => $tenant?->store ? [
                'id' => $tenant->store->id,
                'name' => $tenant->store->name,
            ] : null,
            'subscription' => $this->subscriptionPayload($tenant, $plan),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subscriptionPayload(?Tenant $tenant, $plan): ?array
    {
        if ($tenant === null) {
            return null;
        }

        $isTrial = $tenant->isOnTrial();
        $trialDaysRemaining = $tenant->trialDaysRemaining();

        return [
            'status' => $tenant->status,
            'is_trial' => $isTrial,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'trial_days_remaining' => $trialDaysRemaining,
            'subscribed_at' => $tenant->subscribed_at?->toIso8601String(),
            'current_period_ends_at' => $tenant->current_period_ends_at?->toIso8601String(),
            'billing_cycle' => $tenant->billing_cycle,
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'monthly_price' => $plan->monthly_price,
                'yearly_price' => $plan->yearly_price,
            ] : null,
        ];
    }
}
