<?php

namespace App\Http\Resources;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sale */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'client_uuid' => $this->client_uuid,
            'order_number' => $this->order_number,
            'customer_id' => $this->customer_id,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'user_id' => $this->user_id,
            'created_user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'updated_by' => $this->updated_by,
            'updated_user' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy === null ? null : [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
            ]),
            'subtotal' => (float) $this->subtotal,
            'vat_total' => (float) $this->vat_total,
            'discount_amount' => (float) ($this->discount_amount ?? 0),
            'total' => (float) $this->total,
            'change_amount' => (float) ($this->change_amount ?? 0),
            'status' => $this->status,
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'payments' => SalePaymentResource::collection($this->whenLoaded('payments')),
            'returns' => SaleReturnResource::collection($this->whenLoaded('returns')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
