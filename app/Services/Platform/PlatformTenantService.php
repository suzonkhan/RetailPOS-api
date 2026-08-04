<?php

namespace App\Services\Platform;

use App\Models\Plan;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationService;
use App\Services\Branch\BranchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformTenantService
{
    public function __construct(
        private readonly BranchService $branchService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Tenant::query()->with(['stores.plan'])->withCount('stores');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->whereHas('stores', fn ($q) => $q->where('status', $filters['status']));
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
    public function create(array $data): Tenant
    {
        $user = app(RegistrationService::class)->register($data);

        return $user->tenant->fresh(['stores.plan']);
    }

    public function resetOwnerPin(Tenant $tenant, string $pin): User
    {
        $owner = $this->findOwner($tenant);

        if ($owner === null) {
            abort(404, 'Tenant owner not found.');
        }

        $owner->update(['pin_hash' => $pin]);

        return $owner->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data) {
            $branch = $this->resolveBranch($tenant, $data['branch_id'] ?? null);

            if (isset($data['status'])) {
                if ($data['status'] === Store::STATUS_SUSPENDED) {
                    $this->branchService->suspend($branch);
                } elseif ($data['status'] === Store::STATUS_ACTIVE) {
                    $this->branchService->resume($branch);
                }
            }

            if (isset($data['plan_slug'])) {
                $plan = Plan::query()
                    ->where('slug', $data['plan_slug'])
                    ->where('is_active', true)
                    ->firstOrFail();

                $branch->update(['plan_id' => $plan->id]);
            }

            if (isset($data['trial_ends_at'])) {
                $branch->update([
                    'trial_ends_at' => $data['trial_ends_at'],
                    'status' => Store::STATUS_TRIAL,
                    'data_purge_scheduled_at' => null,
                ]);
            } elseif (isset($data['extend_trial_days'])) {
                $base = $branch->trial_ends_at !== null && $branch->trial_ends_at->isFuture()
                    ? $branch->trial_ends_at->copy()
                    : now();

                $branch->update([
                    'trial_ends_at' => $base->addDays((int) $data['extend_trial_days']),
                    'status' => Store::STATUS_TRIAL,
                    'data_purge_scheduled_at' => null,
                ]);
            }

            return $tenant->fresh(['stores.plan']);
        });
    }

    private function resolveBranch(Tenant $tenant, ?int $branchId): Store
    {
        if ($branchId !== null) {
            $branch = Store::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($branchId)
                ->first();

            if ($branch === null) {
                throw ValidationException::withMessages([
                    'branch_id' => ['Branch not found for this tenant.'],
                ]);
            }

            return $branch;
        }

        $branch = Store::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_default', true)
            ->first();

        if ($branch === null) {
            $branch = Store::query()->where('tenant_id', $tenant->id)->orderBy('id')->first();
        }

        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch_id' => ['Tenant has no branches.'],
            ]);
        }

        return $branch;
    }
}
