<?php

namespace App\Services\Platform;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Collection;

class PlatformCouponService
{
    public function list(): Collection
    {
        return Coupon::query()
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(array $data): Coupon
    {
        return Coupon::query()->create([
            'code' => strtoupper(trim($data['code'])),
            'type' => $data['type'],
            'value' => $data['value'],
            'max_uses' => $data['max_uses'] ?? null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
            'applicable_plans' => $this->normalizeApplicablePlans($data['applicable_plans'] ?? null),
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(Coupon $coupon, array $data): Coupon
    {
        $payload = collect($data)->only([
            'type',
            'value',
            'max_uses',
            'valid_from',
            'valid_to',
            'applicable_plans',
            'is_active',
        ])->all();

        if (array_key_exists('code', $data)) {
            $payload['code'] = strtoupper(trim($data['code']));
        }

        if (array_key_exists('applicable_plans', $payload)) {
            $payload['applicable_plans'] = $this->normalizeApplicablePlans($payload['applicable_plans']);
        }

        $coupon->update($payload);

        return $coupon->fresh();
    }

    private function normalizeApplicablePlans(?array $plans): ?array
    {
        if ($plans === null || $plans === []) {
            return null;
        }

        return array_values($plans);
    }
}
