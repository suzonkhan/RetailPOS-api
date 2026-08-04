<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetDefaultBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Store;
use App\Services\Branch\BranchService;
use Illuminate\Http\JsonResponse;

class SetDefaultBranchController extends Controller
{
    public function __construct(
        private readonly BranchService $branches,
    ) {}

    public function __invoke(SetDefaultBranchRequest $request): JsonResponse
    {
        $branch = Store::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereKey($request->validated('branch_id'))
            ->firstOrFail();

        $this->branches->setDefaultBranch($request->user(), $branch);

        return response()->json([
            'default_branch' => BranchResource::make($branch->load('plan'))->resolve(),
        ]);
    }
}
