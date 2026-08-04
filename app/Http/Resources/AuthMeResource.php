<?php

namespace App\Http\Resources;

use App\Models\Store;
use App\Models\User;
use App\Services\Branch\BranchScopeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class AuthMeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this->tenant;
        $branchScope = app(BranchScopeService::class);
        $branches = $branchScope->accessibleBranches($this->resource);
        $currentBranch = $this->resolveCurrentBranch($branchScope, $branches);

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
            ] : null,
            'branches' => BranchResource::collection($branches)->resolve(),
            'current_branch' => $currentBranch ? BranchResource::make($currentBranch)->resolve() : null,
            'store' => $currentBranch ? [
                'id' => $currentBranch->id,
                'name' => $currentBranch->name,
            ] : null,
            'subscription' => $currentBranch
                ? BranchSubscriptionResource::make($currentBranch)->resolve()
                : null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Store>  $branches
     */
    private function resolveCurrentBranch(BranchScopeService $branchScope, $branches): ?Store
    {
        if ($branches->isEmpty()) {
            return null;
        }

        try {
            return $branchScope->resolveBranch($this->resource);
        } catch (\Throwable) {
            return $branches->first();
        }
    }
}
