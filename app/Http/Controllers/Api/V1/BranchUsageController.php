<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Store;
use App\Services\Branch\BranchScopeService;
use App\Services\Branch\BranchService;
use App\Services\Catalog\CatalogPlanLimitService;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;

class BranchUsageController extends Controller
{
    public function __construct(
        private readonly BranchScopeService $branchScope,
        private readonly CatalogPlanLimitService $catalogPlanLimits,
        private readonly UserService $userService,
    ) {}

    public function show(Store $branch): JsonResponse
    {
        $this->authorizeBranch($branch);

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

    private function authorizeBranch(Store $branch): void
    {
        $user = request()->user();

        if ((int) $branch->tenant_id !== (int) $user->tenant_id) {
            abort(404);
        }

        if (! $this->branchScope->userCanAccessBranch($user, $branch)) {
            abort(403);
        }
    }
}
