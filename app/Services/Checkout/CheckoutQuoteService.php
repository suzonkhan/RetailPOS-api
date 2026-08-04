<?php

namespace App\Services\Checkout;

use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

class CheckoutQuoteService
{
    public function __construct(
        private readonly CouponService $couponService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function quote(Tenant $tenant, array $data, ?Store $store = null): array
    {
        $plan = $this->resolvePlan($data);
        $billingCycle = $data['billing_cycle'];
        $intent = $data['intent'] ?? SubscriptionInvoice::INTENT_RENEW;

        $this->assertIntentAllowed($intent, $plan, $store, $data);

        $periodSubtotal = $this->subtotalForCycle($plan, $billingCycle);
        $gapAmount = 0;
        $gapDays = 0;

        if ($intent === SubscriptionInvoice::INTENT_RENEW && $store !== null) {
            [$gapDays, $gapAmount] = $this->gapBilling($store, $plan, $billingCycle);
        }

        $subtotal = $periodSubtotal + $gapAmount;

        $coupon = $this->couponService->findValidCoupon($data['coupon_code'] ?? null, $plan);
        $discount = $coupon ? $this->couponService->calculateDiscount($coupon, $subtotal) : 0;
        $total = max(0, $subtotal - $discount);

        return [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
            ],
            'intent' => $intent,
            'billing_cycle' => $billingCycle,
            'currency' => 'BDT',
            'subtotal' => $subtotal,
            'period_subtotal' => $periodSubtotal,
            'gap_days' => $gapDays,
            'gap_amount' => $gapAmount,
            'discount_amount' => $discount,
            'total' => $total,
            'coupon' => $coupon ? $this->couponPayload($coupon) : null,
            'current_plan_id' => $store?->plan_id,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function gapBilling(Store $store, Plan $plan, string $billingCycle): array
    {
        if ($store->current_period_ends_at === null || $store->current_period_ends_at->isFuture()) {
            if ($store->isOnTrial()) {
                return [0, 0];
            }

            if ($store->hasActiveSubscription()) {
                return [0, 0];
            }
        }

        $expiry = $store->current_period_ends_at ?? $store->trial_ends_at;

        if ($expiry === null || $expiry->isFuture()) {
            return [0, 0];
        }

        $gapDays = (int) $expiry->startOfDay()->diffInDays(now()->startOfDay());

        if ($gapDays <= 0) {
            return [0, 0];
        }

        $dailyRate = $this->dailyRate($plan, $billingCycle);

        return [$gapDays, (int) round($dailyRate * $gapDays)];
    }

    public function dailyRate(Plan $plan, string $billingCycle): float
    {
        $periodPrice = $this->subtotalForCycle($plan, $billingCycle);
        $days = $billingCycle === Tenant::BILLING_YEARLY ? 365 : 30;

        return $periodPrice / $days;
    }

    public function subtotalForCycle(Plan $plan, string $billingCycle): int
    {
        return match ($billingCycle) {
            Tenant::BILLING_MONTHLY, Store::BILLING_MONTHLY => (int) $plan->monthly_price,
            Tenant::BILLING_YEARLY, Store::BILLING_YEARLY => (int) $plan->yearly_price,
            default => throw ValidationException::withMessages([
                'billing_cycle' => ['Billing cycle must be monthly or yearly.'],
            ]),
        };
    }

    private function assertIntentAllowed(string $intent, Plan $plan, ?Store $store, array $data): void
    {
        if ($intent === SubscriptionInvoice::INTENT_CREATE_BRANCH) {
            $meta = $data['branch_meta'] ?? [];
            if (empty($meta['name'])) {
                throw ValidationException::withMessages([
                    'branch_meta.name' => ['Branch name is required.'],
                ]);
            }

            return;
        }

        if ($store === null) {
            throw ValidationException::withMessages([
                'store_id' => ['Branch is required for this checkout.'],
            ]);
        }

        if ($intent === SubscriptionInvoice::INTENT_UPGRADE) {
            if ($store->plan_id !== null && $plan->monthly_price <= (int) $store->plan?->monthly_price) {
                throw ValidationException::withMessages([
                    'plan_slug' => ['Downgrade is not allowed. Choose a higher plan.'],
                ]);
            }
        }
    }

    private function resolvePlan(array $data): Plan
    {
        if (! empty($data['plan_id'])) {
            return Plan::query()->where('is_active', true)->findOrFail($data['plan_id']);
        }

        if (! empty($data['plan_slug'])) {
            return Plan::query()
                ->where('slug', $data['plan_slug'])
                ->where('is_active', true)
                ->firstOrFail();
        }

        throw ValidationException::withMessages([
            'plan_id' => ['Plan is required.'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function couponPayload(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => $coupon->value,
        ];
    }
}
