<?php

namespace App\Http\Resources;

use App\Models\PurchaseItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseItem */
class PurchaseItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'variant_label' => $this->variant_label,
            'quantity' => (float) $this->quantity,
            'unit_cost' => (float) $this->unit_cost,
            'line_total' => (float) $this->line_total,
            'expiration_date' => $this->expiration_date?->format('Y-m-d'),
        ];
    }
}
