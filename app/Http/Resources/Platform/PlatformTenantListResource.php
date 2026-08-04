<?php

namespace App\Http\Resources\Platform;

use App\Models\Store;
use App\Models\Tenant;
use App\Services\Platform\PlatformTenantService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class PlatformTenantListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = app(PlatformTenantService::class)->findOwner($this->resource);
        $branch = $this->resolveDefaultBranch();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $branch?->status,
            'trial_ends_at' => $branch?->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $branch?->current_period_ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'plan' => $branch?->plan ? [
                'id' => $branch->plan->id,
                'name' => $branch->plan->name,
                'slug' => $branch->plan->slug,
            ] : null,
            'owner' => $owner ? [
                'name' => $owner->name,
                'mobile' => $owner->mobile,
            ] : null,
            'branch_count' => $this->whenCounted('stores', $this->stores?->count()),
        ];
    }

    private function resolveDefaultBranch(): ?Store
    {
        if ($this->relationLoaded('stores')) {
            return $this->stores->firstWhere('is_default', true) ?? $this->stores->first();
        }

        return $this->stores()->with('plan')->where('is_default', true)->first()
            ?? $this->stores()->with('plan')->orderBy('id')->first();
    }
}
