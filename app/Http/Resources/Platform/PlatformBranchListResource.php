<?php

namespace App\Http\Resources\Platform;

use App\Models\Store;
use App\Services\Platform\PlatformBranchService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Store */
class PlatformBranchListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = app(PlatformBranchService::class)->findOwner($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'status' => $this->status,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $this->current_period_ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'tenant' => $this->tenant ? [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
            ] : null,
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
