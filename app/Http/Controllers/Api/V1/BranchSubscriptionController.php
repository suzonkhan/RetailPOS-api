<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchSubscriptionResource;
use App\Models\Store;
use App\Services\Branch\BranchScopeService;
use Illuminate\Http\JsonResponse;

class BranchSubscriptionController extends Controller
{
    public function __construct(
        private readonly BranchScopeService $branchScope,
    ) {}

    public function show(Store $branch): JsonResponse
    {
        $this->authorizeBranch($branch);

        return response()->json([
            'subscription' => BranchSubscriptionResource::make($branch->load('plan'))->resolve(),
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
