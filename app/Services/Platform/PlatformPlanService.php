<?php

namespace App\Services\Platform;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformPlanService
{
    public function list(): Collection
    {
        return Plan::query()
            ->withCount('tenants')
            ->orderBy('monthly_price')
            ->get();
    }

    public function create(array $data): Plan
    {
        $yearlyPrice = $data['yearly_price'] ?? ($data['monthly_price'] * 12);

        if (! empty($data['is_trial_default'])) {
            $this->clearTrialDefault();
        }

        return Plan::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'monthly_price' => $data['monthly_price'],
            'yearly_price' => $yearlyPrice,
            'max_users' => $data['max_users'],
            'max_stores' => $data['max_stores'] ?? 1,
            'max_categories' => $data['max_categories'],
            'max_products' => $data['max_products'],
            'is_active' => $data['is_active'] ?? true,
            'is_trial_default' => $data['is_trial_default'] ?? false,
        ]);
    }

    public function update(Plan $plan, array $data): Plan
    {
        if (array_key_exists('is_active', $data) && $data['is_active'] === false && $plan->tenants()->exists()) {
            throw ValidationException::withMessages([
                'is_active' => 'Cannot deactivate a plan that is assigned to tenants.',
            ]);
        }

        if (! empty($data['is_trial_default'])) {
            $this->clearTrialDefault($plan->id);
        }

        $payload = collect($data)->only([
            'name',
            'monthly_price',
            'yearly_price',
            'max_users',
            'max_stores',
            'max_categories',
            'max_products',
            'is_active',
            'is_trial_default',
        ])->all();

        if (isset($payload['monthly_price']) && ! array_key_exists('yearly_price', $payload)) {
            $payload['yearly_price'] = $payload['monthly_price'] * 12;
        }

        $plan->update($payload);

        return $plan->fresh()->loadCount('tenants');
    }

    public function suggestSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Plan::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function clearTrialDefault(?int $exceptPlanId = null): void
    {
        Plan::query()
            ->when($exceptPlanId !== null, fn ($q) => $q->where('id', '!=', $exceptPlanId))
            ->update(['is_trial_default' => false]);
    }
}
