<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Store;
use App\Services\Branch\BranchService;
use App\Services\Branch\BranchScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BranchController extends Controller
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly BranchScopeService $branchScope,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $branches = $this->branches->listForUser(request()->user());

        return BranchResource::collection($branches);
    }

    public function show(Store $branch): BranchResource
    {
        $this->authorizeBranch($branch);

        return BranchResource::make($branch->load('plan'));
    }

    public function update(UpdateBranchRequest $request, Store $branch): BranchResource
    {
        $this->authorizeBranch($branch);

        $branch = $this->branches->update($request->user(), $branch, $request->validated());

        return BranchResource::make($branch);
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
