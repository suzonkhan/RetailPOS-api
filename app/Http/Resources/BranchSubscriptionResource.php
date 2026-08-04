<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Store */
class BranchSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->syncSubscriptionStatus();
        $this->resource->refresh();

        $plan = $this->plan;

        return [
            'branch_id' => $this->id,
            'branch_name' => $this->name,
            'status' => $this->status,
            'is_trial' => $this->isOnTrial(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'trial_days_remaining' => $this->trialDaysRemaining(),
            'subscribed_at' => $this->subscribed_at?->toIso8601String(),
            'current_period_ends_at' => $this->current_period_ends_at?->toIso8601String(),
            'billing_cycle' => $this->billing_cycle,
            'requires_payment' => $this->requiresSubscriptionPayment(),
            'data_purge_scheduled_at' => $this->data_purge_scheduled_at?->toIso8601String(),
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
