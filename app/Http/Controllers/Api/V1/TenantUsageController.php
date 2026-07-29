<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Catalog\CatalogPlanLimitService;
use Illuminate\Http\JsonResponse;

class TenantUsageController extends Controller
{
    public function __construct(
        private readonly CatalogPlanLimitService $catalogPlanLimits,
    ) {}

    public function show(): JsonResponse
    {
        $tenant = request()->user()->tenant?->load('plan');

        if ($tenant === null || $tenant->plan === null) {
            return response()->json(['usage' => null]);
        }

        $plan = $tenant->plan;
        $userCount = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_platform_admin', false)
            ->count();

        return response()->json([
            'usage' => [
                'users' => [
                    'current' => $userCount,
                    'max' => $plan->max_users,
                ],
                'categories' => [
                    'current' => $this->catalogPlanLimits->categoryCount($tenant),
                    'max' => $plan->max_categories,
                ],
                'products' => [
                    'current' => $this->catalogPlanLimits->productCount($tenant),
                    'max' => $plan->max_products,
                ],
            ],
        ]);
    }
}
