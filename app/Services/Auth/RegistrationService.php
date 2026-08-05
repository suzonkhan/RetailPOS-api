<?php

namespace App\Services\Auth;

use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Plans\TrialPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegistrationService
{
    public function __construct(
        private readonly TrialPlanService $trialPlanService,
    ) {}

    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $ownerName = trim((string) $data['owner_name']);
            $storeName = $ownerName."'s Store";
            $branchName = config('retail360.default_branch_name', 'main branch');
            $plan = $this->trialPlanService->resolveTrialPlan();

            $tenant = Tenant::query()->create([
                'name' => $storeName,
                'slug' => $this->uniqueTenantSlug($storeName),
                'plan_id' => $plan->id,
                'status' => Tenant::STATUS_TRIAL,
                'trial_ends_at' => now()->addDays((int) config('retail360.trial_days', 15)),
            ]);

            $store = Store::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'name' => $branchName,
                'status' => Store::STATUS_TRIAL,
                'trial_ends_at' => now()->addDays((int) config('retail360.trial_days', 15)),
                'is_default' => true,
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
                'name' => $ownerName,
                'mobile' => $data['mobile'],
                'pin_hash' => $data['pin'],
                'tenant_id' => $tenant->id,
                'default_store_id' => $store->id,
                'is_platform_admin' => false,
            ]);

            $user->assignRole('owner');

            return $user->load(['tenant.plan', 'defaultStore.plan', 'roles']);
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
