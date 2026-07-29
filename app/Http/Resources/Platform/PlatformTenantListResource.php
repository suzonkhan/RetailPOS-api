<?php

namespace App\Http\Resources\Platform;

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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $this->current_period_ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'plan' => $this->plan ? [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'slug' => $this->plan->slug,
            ] : null,
            'owner' => $owner ? [
                'name' => $owner->name,
                'mobile' => $owner->mobile,
            ] : null,
        ];
    }
}
