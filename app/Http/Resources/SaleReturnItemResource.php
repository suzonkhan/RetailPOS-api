<?php

namespace App\Http\Resources;

use App\Models\SaleReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleReturnItem */
class SaleReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_item_id' => $this->sale_item_id,
            'product_id' => $this->product_id,
            'quantity' => (float) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'line_subtotal' => (float) $this->line_subtotal,
            'vat_rate' => (float) $this->vat_rate,
            'vat_amount' => (float) $this->vat_amount,
            'line_total' => (float) $this->line_total,
        ];
    }
}
