<?php

namespace App\Services\Platform;

use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PlatformBranchService
{
    public function __construct(
        private readonly PlatformTenantService $platformTenants,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Store::query()
            ->with(['plan', 'tenant'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhereHas('tenant', function (Builder $tenantQuery) use ($search) {
                        $tenantQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    })
                    ->orWhereHas('tenant.users', function (Builder $userQuery) use ($search) {
                        $userQuery->where('is_platform_admin', false)
                            ->where(function (Builder $ownerQuery) use ($search) {
                                $ownerQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('mobile', 'like', "%{$search}%");
                            });
                    });
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['plan_slug'])) {
            $query->whereHas('plan', fn (Builder $planQuery) => $planQuery->where('slug', $filters['plan_slug']));
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    public function findOwner(Store $branch): ?User
    {
        if ($branch->relationLoaded('tenant') && $branch->tenant !== null) {
            return $this->platformTenants->findOwner($branch->tenant);
        }

        return $branch->tenant
            ? $this->platformTenants->findOwner($branch->tenant)
            : null;
    }
}
