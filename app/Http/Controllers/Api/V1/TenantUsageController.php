<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Branch\BranchScopeService;
use App\Services\Catalog\CatalogPlanLimitService;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;

class TenantUsageController extends Controller
{
    public function __construct(
        private readonly CatalogPlanLimitService $catalogPlanLimits,
        private readonly BranchScopeService $branchScope,
        private readonly UserService $userService,
    ) {}

    public function show(): JsonResponse
    {
        $branch = $this->branchScope->resolveBranch(request()->user());
        $branch->load('plan');
        $plan = $branch->plan;

        if ($plan === null) {
            return response()->json(['usage' => null]);
        }

        return response()->json([
            'usage' => [
                'users' => [
                    'current' => $this->userService->branchUserCount($branch),
                    'max' => $plan->max_users,
                ],
                'categories' => [
                    'current' => $this->catalogPlanLimits->categoryCount($branch),
                    'max' => $plan->max_categories,
                ],
                'products' => [
                    'current' => $this->catalogPlanLimits->productCount($branch),
                    'max' => $plan->max_products,
                ],
            ],
        ]);
    }
}
