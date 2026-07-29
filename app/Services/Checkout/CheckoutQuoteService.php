<?php

namespace App\Services\Checkout;

use App\Models\Coupon;
use App\Models\Plan;
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
    public function quote(Tenant $tenant, array $data): array
    {
        $plan = $this->resolvePlan($data);
        $billingCycle = $data['billing_cycle'];
        $subtotal = $this->subtotalForCycle($plan, $billingCycle);

        $coupon = $this->couponService->findValidCoupon($data['coupon_code'] ?? null, $plan);
        $discount = $coupon ? $this->couponService->calculateDiscount($coupon, $subtotal) : 0;
        $total = max(0, $subtotal - $discount);

        return [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
            ],
            'billing_cycle' => $billingCycle,
            'currency' => 'BDT',
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total' => $total,
            'coupon' => $coupon ? $this->couponPayload($coupon) : null,
            'current_plan_id' => $tenant->plan_id,
        ];
    }

    public function subtotalForCycle(Plan $plan, string $billingCycle): int
    {
        return match ($billingCycle) {
            Tenant::BILLING_MONTHLY => (int) $plan->monthly_price,
            Tenant::BILLING_YEARLY => (int) $plan->yearly_price,
            default => throw ValidationException::withMessages([
                'billing_cycle' => ['Billing cycle must be monthly or yearly.'],
            ]),
        };
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
