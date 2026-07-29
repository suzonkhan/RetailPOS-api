<?php

namespace App\Http\Resources;

use App\Models\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalePayment */
class SalePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_method_id' => $this->payment_method_id,
            'payment_method' => PaymentMethodResource::make($this->whenLoaded('paymentMethod')),
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
        ];
    }
}
