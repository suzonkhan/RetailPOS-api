<?php

namespace App\Http\Resources;

use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleReturn */
class SaleReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'sale_id' => $this->sale_id,
            'subtotal' => (float) $this->subtotal,
            'vat_total' => (float) $this->vat_total,
            'total' => (float) $this->total,
            'notes' => $this->notes,
            'items' => SaleReturnItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
