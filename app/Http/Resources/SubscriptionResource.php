<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this->tenant;
        $plan = $tenant?->plan;

        if ($tenant === null) {
            return [];
        }

        $tenant->syncSubscriptionStatus();
        $tenant->refresh();

        $isTrial = $tenant->isOnTrial();

        return [
            'status' => $tenant->status,
            'is_trial' => $isTrial,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'trial_days_remaining' => $tenant->trialDaysRemaining(),
            'subscribed_at' => $tenant->subscribed_at?->toIso8601String(),
            'current_period_ends_at' => $tenant->current_period_ends_at?->toIso8601String(),
            'billing_cycle' => $tenant->billing_cycle,
            'requires_payment' => $tenant->requiresSubscriptionPayment(),
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
        ];
    }
}
