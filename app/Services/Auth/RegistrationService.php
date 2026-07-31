<?php

namespace App\Services\Auth;

use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegistrationService
{
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $shopName = trim((string) ($data['shop_name'] ?? ''));
            if ($shopName === '') {
                $shopName = $data['owner_name'];
            }

            $plan = Plan::query()
                ->where('slug', $data['plan_slug'] ?? 'startup')
                ->where('is_active', true)
                ->firstOrFail();

            $tenant = Tenant::query()->create([
                'name' => $shopName,
                'slug' => $this->uniqueTenantSlug($shopName),
                'plan_id' => $plan->id,
                'status' => Tenant::STATUS_TRIAL,
                'trial_ends_at' => now()->addDays((int) config('retail360.trial_days', 15)),
            ]);

            $store = Store::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['store_name'] ?? $shopName,
            ]);

            StoreSetting::query()->create([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'vat_adjust_on_sale' => false,
            ]);

            PaymentMethod::query()->create([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'name' => 'Cash',
                'is_active' => true,
                'sort_order' => 0,
                'requires_reference' => false,
            ]);

            $user = User::query()->create([
                'name' => $data['owner_name'],
                'mobile' => $data['mobile'],
                'pin_hash' => $data['pin'],
                'tenant_id' => $tenant->id,
                'is_platform_admin' => false,
            ]);

            $user->assignRole('owner');

            return $user->load(['tenant.plan', 'tenant.store', 'roles']);
        });
    }

    private function uniqueTenantSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 1;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
