<?php

namespace App\Services\Platform;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PlatformTenantService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Tenant::query()->with(['plan', 'store']);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findOwner(Tenant $tenant): ?User
    {
        $owner = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_platform_admin', false)
            ->whereHas('roles', fn ($query) => $query->where('name', 'owner'))
            ->orderBy('id')
            ->first();

        if ($owner !== null) {
            return $owner;
        }

        return User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_platform_admin', false)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data) {
            if (isset($data['status'])) {
                if ($data['status'] === Tenant::STATUS_SUSPENDED) {
                    $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
                } elseif ($data['status'] === Tenant::STATUS_ACTIVE) {
                    $this->activate($tenant);
                }
            }

            if (isset($data['plan_slug'])) {
                $plan = Plan::query()
                    ->where('slug', $data['plan_slug'])
                    ->where('is_active', true)
                    ->firstOrFail();

                $tenant->update(['plan_id' => $plan->id]);
            }

            if (isset($data['trial_ends_at'])) {
                $tenant->update([
                    'trial_ends_at' => $data['trial_ends_at'],
                    'status' => Tenant::STATUS_TRIAL,
                ]);
            } elseif (isset($data['extend_trial_days'])) {
                $base = $tenant->trial_ends_at !== null && $tenant->trial_ends_at->isFuture()
                    ? $tenant->trial_ends_at->copy()
                    : now();

                $tenant->update([
                    'trial_ends_at' => $base->addDays((int) $data['extend_trial_days']),
                    'status' => Tenant::STATUS_TRIAL,
                ]);
            }

            return $tenant->fresh(['plan', 'store']);
        });
    }

    private function activate(Tenant $tenant): void
    {
        if ($tenant->trial_ends_at !== null && $tenant->trial_ends_at->isFuture()) {
            $tenant->update(['status' => Tenant::STATUS_TRIAL]);
        } elseif ($tenant->current_period_ends_at !== null && $tenant->current_period_ends_at->isFuture()) {
            $tenant->update(['status' => Tenant::STATUS_ACTIVE]);
        } else {
            $tenant->update(['status' => Tenant::STATUS_EXPIRED]);
        }

        $tenant->refresh();
        $tenant->syncSubscriptionStatus();
    }
}
