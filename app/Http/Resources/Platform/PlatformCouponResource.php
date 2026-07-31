<?php

namespace App\Http\Resources\Platform;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class PlatformCouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'max_uses' => $this->max_uses,
            'used_count' => $this->used_count,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'applicable_plans' => $this->applicable_plans,
            'is_active' => $this->is_active,
        ];
    }
}
