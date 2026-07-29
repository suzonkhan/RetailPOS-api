<?php

namespace App\Http\Resources;

use App\Models\StockLot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockLot */
class StockLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'uom' => $this->product->uom ?? 'pcs',
            ]),
            'variant_label' => $this->when(
                $this->relationLoaded('productVariant'),
                fn () => $this->productVariant?->buildLabel(),
            ),
            'purchase_item_id' => $this->purchase_item_id,
            'received_at' => $this->received_at?->toIso8601String(),
            'expiration_date' => $this->expiration_date?->format('Y-m-d'),
            'unit_cost' => (float) $this->unit_cost,
            'quantity_received' => (float) $this->quantity_received,
            'quantity_remaining' => (float) $this->quantity_remaining,
            'is_expired' => $this->isExpired(),
        ];
    }
}
