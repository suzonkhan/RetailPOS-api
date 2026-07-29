<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'monthly_price' => $this->monthly_price,
            'yearly_price' => $this->yearly_price,
            'max_users' => $this->max_users,
            'max_stores' => $this->max_stores,
            'max_categories' => $this->max_categories,
            'max_products' => $this->max_products,
        ];
    }
}
