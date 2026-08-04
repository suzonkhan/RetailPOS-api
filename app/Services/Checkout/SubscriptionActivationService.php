<?php

namespace App\Services\Checkout;

use App\Models\BkashPayment;
use App\Models\Coupon;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class SubscriptionActivationService
{
    public function activateFromInvoice(SubscriptionInvoice $invoice, ?BkashPayment $payment = null): Store
    {
        if ($invoice->status === SubscriptionInvoice::STATUS_PAID && $invoice->store_id !== null) {
            return $invoice->store->fresh(['plan']);
        }

        return DB::transaction(function () use ($invoice, $payment) {
            $invoice = SubscriptionInvoice::query()
                ->lockForUpdate()
                ->with(['tenant', 'plan', 'coupon', 'store'])
                ->findOrFail($invoice->id);

            if ($invoice->status === SubscriptionInvoice::STATUS_PAID && $invoice->store_id !== null) {
                return $invoice->store;
            }

            $store = match ($invoice->intent) {
                SubscriptionInvoice::INTENT_CREATE_BRANCH => $this->createBranchFromInvoice($invoice),
                SubscriptionInvoice::INTENT_UPGRADE => $this->upgradeBranchFromInvoice($invoice),
                default => $this->renewBranchFromInvoice($invoice),
            };

            Subscription::query()
                ->where('store_id', $store->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update(['status' => Subscription::STATUS_EXPIRED]);

            Subscription::query()->create([
                'tenant_id' => $invoice->tenant_id,
                'store_id' => $store->id,
                'plan_id' => $invoice->plan_id,
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => $invoice->billing_cycle,
                'started_at' => now(),
                'ends_at' => $store->current_period_ends_at,
            ]);

            $invoice->update([
                'status' => SubscriptionInvoice::STATUS_PAID,
                'paid_at' => now(),
                'store_id' => $store->id,
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

            return $store->fresh(['plan']);
        });
    }

    private function createBranchFromInvoice(SubscriptionInvoice $invoice): Store
    {
        $meta = $invoice->branch_meta ?? [];
        $periodEnds = $this->periodEndsAt($invoice->billing_cycle);

        $store = Store::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'plan_id' => $invoice->plan_id,
            'name' => $meta['name'] ?? config('retail360.default_branch_name', 'My Store'),
            'phone' => $meta['phone'] ?? null,
            'address' => $meta['address'] ?? null,
            'status' => Store::STATUS_ACTIVE,
            'subscribed_at' => now(),
            'current_period_ends_at' => $periodEnds,
            'billing_cycle' => $invoice->billing_cycle,
            'is_default' => false,
        ]);

        StoreSetting::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'store_id' => $store->id,
            'vat_adjust_on_sale' => false,
        ]);

        PaymentMethod::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'store_id' => $store->id,
            'name' => 'Cash',
            'is_active' => true,
            'sort_order' => 0,
            'requires_reference' => false,
        ]);

        return $store;
    }

    private function renewBranchFromInvoice(SubscriptionInvoice $invoice): Store
    {
        $store = $invoice->store;

        if ($store === null) {
            abort(422, 'Branch is required for renewal.');
        }

        $base = $store->current_period_ends_at !== null && $store->current_period_ends_at->isFuture()
            ? $store->current_period_ends_at->copy()
            : now();

        if ($store->current_period_ends_at !== null && $store->current_period_ends_at->isPast()) {
            $base = now();
        }

        $periodEnds = $this->addBillingPeriod($base, $invoice->billing_cycle);

        $store->update([
            'plan_id' => $invoice->plan_id,
            'status' => Store::STATUS_ACTIVE,
            'subscribed_at' => $store->subscribed_at ?? now(),
            'current_period_ends_at' => $periodEnds,
            'billing_cycle' => $invoice->billing_cycle,
            'data_purge_scheduled_at' => null,
        ]);

        return $store;
    }

    private function upgradeBranchFromInvoice(SubscriptionInvoice $invoice): Store
    {
        $store = $invoice->store;

        if ($store === null) {
            abort(422, 'Branch is required for upgrade.');
        }

        $store->update([
            'plan_id' => $invoice->plan_id,
        ]);

        if ($store->hasActiveSubscription()) {
            return $store;
        }

        $periodEnds = $this->periodEndsAt($invoice->billing_cycle);

        $store->update([
            'status' => Store::STATUS_ACTIVE,
            'subscribed_at' => $store->subscribed_at ?? now(),
            'current_period_ends_at' => $periodEnds,
            'billing_cycle' => $invoice->billing_cycle,
            'data_purge_scheduled_at' => null,
        ]);

        return $store;
    }

    private function periodEndsAt(string $billingCycle): \Illuminate\Support\Carbon
    {
        return $this->addBillingPeriod(now(), $billingCycle);
    }

    private function addBillingPeriod(\Illuminate\Support\Carbon $from, string $billingCycle): \Illuminate\Support\Carbon
    {
        return match ($billingCycle) {
            Tenant::BILLING_MONTHLY, Store::BILLING_MONTHLY => $from->copy()->addMonth(),
            Tenant::BILLING_YEARLY, Store::BILLING_YEARLY => $from->copy()->addYear(),
            default => $from->copy()->addMonth(),
        };
    }
}
