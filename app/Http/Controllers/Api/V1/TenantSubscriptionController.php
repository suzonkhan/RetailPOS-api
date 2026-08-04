<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchSubscriptionResource;
use App\Services\Branch\BranchScopeService;
use Illuminate\Http\JsonResponse;

class TenantSubscriptionController extends Controller
{
    public function __construct(
        private readonly BranchScopeService $branchScope,
    ) {}

    public function show(): JsonResponse
    {
        $branch = $this->branchScope->resolveBranch(request()->user());

        return response()->json([
            'subscription' => BranchSubscriptionResource::make($branch->load('plan'))->resolve(),
        ]);
    }
}
