<?php

namespace App\Http\Resources\Platform;

use App\Models\BkashPayment;
use App\Models\SubscriptionInvoice;
use App\Services\Platform\PlatformTenantBillingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionInvoice */
class PlatformTenantBillingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $billingService = app(PlatformTenantBillingService::class);

        return [
            'id' => $this->id,
            'intent' => $this->intent,
            'billing_cycle' => $this->billing_cycle,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'branch' => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ] : null,
            'plan' => $this->plan ? [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'slug' => $this->plan->slug,
            ] : null,
            'can_approve' => $billingService->canApprove($this->resource),
            'payments' => $this->bkashPayments->map(fn (BkashPayment $payment) => [
                'id' => $payment->id,
                'payment_id' => $payment->payment_id,
                'trx_id' => $payment->trx_id,
                'amount' => $payment->amount,
                'status' => $payment->status,
                'transaction_status' => $payment->transaction_status,
                'completed_at' => $payment->completed_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
