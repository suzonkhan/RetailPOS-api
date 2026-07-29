<?php

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Staff */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->relationLoaded('user') ? $this->user : null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'pay_type' => $this->pay_type,
            'agreed_rate' => $this->agreed_rate !== null ? (float) $this->agreed_rate : null,
            'rate_unit' => $this->rate_unit,
            'joined_at' => $this->joined_at?->format('Y-m-d'),
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'user_id' => $this->user_id,
            'has_login' => $this->user_id !== null,
            'login_role' => $user?->primaryRole(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
