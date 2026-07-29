<?php

namespace App\Http\Resources;

use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleItem */
class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $returnedQuantity = $this->relationLoaded('returnItems')
            ? (float) $this->returnItems->sum('quantity')
            : null;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'variant_label' => $this->variant_label,
            'quantity' => (float) $this->quantity,
            'returned_quantity' => $returnedQuantity,
            'returnable_quantity' => $returnedQuantity !== null
                ? max(0, (float) $this->quantity - $returnedQuantity)
                : null,
            'unit_price' => (float) $this->unit_price,
            'unit_cost' => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'line_subtotal' => (float) $this->line_subtotal,
            'vat_rate' => (float) $this->vat_rate,
            'vat_type' => $this->vat_type,
            'vat_amount' => (float) $this->vat_amount,
            'line_total' => (float) $this->line_total,
        ];
    }
}
