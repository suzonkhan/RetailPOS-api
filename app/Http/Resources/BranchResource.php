<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Store */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->syncSubscriptionStatus();
        $this->resource->refresh();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'is_default' => $this->is_default,
            'status' => $this->status,
            'is_trial' => $this->isOnTrial(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'trial_days_remaining' => $this->trialDaysRemaining(),
            'subscribed_at' => $this->subscribed_at?->toIso8601String(),
            'current_period_ends_at' => $this->current_period_ends_at?->toIso8601String(),
            'billing_cycle' => $this->billing_cycle,
            'requires_payment' => $this->requiresSubscriptionPayment(),
            'data_purge_scheduled_at' => $this->data_purge_scheduled_at?->toIso8601String(),
            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'slug' => $this->plan->slug,
                'monthly_price' => $this->plan->monthly_price,
                'yearly_price' => $this->plan->yearly_price,
                'max_users' => $this->plan->max_users,
                'max_categories' => $this->plan->max_categories,
                'max_products' => $this->plan->max_products,
            ] : null),
        ];
    }
}
