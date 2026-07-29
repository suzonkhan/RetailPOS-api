<?php

namespace App\Services\Checkout;

use App\Models\BkashPayment;
use App\Models\Coupon;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class SubscriptionActivationService
{
    public function activateFromInvoice(SubscriptionInvoice $invoice, ?BkashPayment $payment = null): Tenant
    {
        if ($invoice->status === SubscriptionInvoice::STATUS_PAID) {
            return $invoice->tenant->fresh(['plan']);
        }

        return DB::transaction(function () use ($invoice, $payment) {
            $invoice = SubscriptionInvoice::query()
                ->lockForUpdate()
                ->with(['tenant', 'plan', 'coupon'])
                ->findOrFail($invoice->id);

            if ($invoice->status === SubscriptionInvoice::STATUS_PAID) {
                return $invoice->tenant;
            }

            $tenant = $invoice->tenant;
            $periodEnds = $this->periodEndsAt($invoice->billing_cycle);

            $tenant->update([
                'plan_id' => $invoice->plan_id,
                'status' => Tenant::STATUS_ACTIVE,
                'subscribed_at' => $tenant->subscribed_at ?? now(),
                'current_period_ends_at' => $periodEnds,
                'billing_cycle' => $invoice->billing_cycle,
            ]);

            Subscription::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update(['status' => Subscription::STATUS_EXPIRED]);

            Subscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $invoice->plan_id,
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => $invoice->billing_cycle,
                'started_at' => now(),
                'ends_at' => $periodEnds,
            ]);

            $invoice->update([
                'status' => SubscriptionInvoice::STATUS_PAID,
                'paid_at' => now(),
            ]);

            if ($payment !== null) {
                $payment->update([
                    'status' => BkashPayment::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            }

            if ($invoice->coupon_id !== null) {
                Coupon::query()
                    ->whereKey($invoice->coupon_id)
                    ->increment('used_count');
            }

            return $tenant->fresh(['plan']);
        });
    }

    private function periodEndsAt(string $billingCycle): \Illuminate\Support\Carbon
    {
        return match ($billingCycle) {
            Tenant::BILLING_MONTHLY => now()->addMonth(),
            Tenant::BILLING_YEARLY => now()->addYear(),
            default => now()->addMonth(),
        };
    }
}
