<?php

namespace App\Http\Resources;

use App\Models\DuePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DuePayment */
class DuePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'customer_id' => $this->customer_id,
            'customer_due_id' => $this->customer_due_id,
            'payment_method_id' => $this->payment_method_id,
            'payment_method' => PaymentMethodResource::make($this->whenLoaded('paymentMethod')),
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
