<?php

namespace App\Services\Plans;

use App\Models\Plan;

class TrialPlanService
{
    public function resolveTrialPlan(): Plan
    {
        $plan = Plan::query()
            ->where('is_trial_default', true)
            ->where('is_active', true)
            ->first();

        if ($plan !== null) {
            return $plan;
        }

        $slug = config('retail360.trial_plan_slug', 'startup');

        return Plan::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();
    }
}
