<?php

namespace App\Services\Checkout;

use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function findValidCoupon(?string $code, Plan $plan): ?Coupon
    {
        if ($code === null || $code === '') {
            return null;
        }

        $coupon = Coupon::query()
            ->where('code', strtoupper(trim($code)))
            ->where('is_active', true)
            ->first();

        if ($coupon === null) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Invalid coupon code.'],
            ]);
        }

        $this->assertCouponUsable($coupon, $plan);

        return $coupon;
    }

    public function calculateDiscount(Coupon $coupon, int $subtotal): int
    {
        if ($coupon->type === Coupon::TYPE_PERCENT) {
            return (int) round($subtotal * ($coupon->value / 100));
        }

        return min($coupon->value, $subtotal);
    }

    private function assertCouponUsable(Coupon $coupon, Plan $plan): void
    {
        if ($coupon->valid_from !== null && $coupon->valid_from->isFuture()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is not yet valid.'],
            ]);
        }

        if ($coupon->valid_to !== null && $coupon->valid_to->isPast()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon has expired.'],
            ]);
        }

        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon has reached its usage limit.'],
            ]);
        }

        $applicable = $coupon->applicable_plans;

        if (is_array($applicable) && $applicable !== [] && ! in_array($plan->slug, $applicable, true)) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon does not apply to the selected plan.'],
            ]);
        }
    }
}
