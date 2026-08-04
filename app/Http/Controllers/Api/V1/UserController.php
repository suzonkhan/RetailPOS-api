<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Branch\BranchScopeService;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly BranchScopeService $branchScope,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $store = $this->branchScope->resolveBranch(request()->user());
        $tenantId = request()->user()->tenant_id;

        $users = User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_platform_admin', false)
            ->where(function ($query) use ($store) {
                $query->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
                    ->orWhereHas('stores', fn ($q) => $q->where('stores.id', $store->id));
            })
            ->with('roles')
            ->orderBy('name')
            ->get();

        $store->load('plan');

        return UserResource::collection($users)->additional([
            'meta' => [
                'user_count' => $this->userService->branchUserCount($store),
                'max_users' => $store->plan?->max_users,
                'can_add_user' => $this->userService->canAddUser($store),
                'branch_id' => $store->id,
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $store = $this->branchScope->resolveBranch($request->user());

        $user = $this->userService->create($request->user()->tenant, $store, $request->validated());

        return UserResource::make($user)
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        $this->authorizeTenantUser($user);

        return UserResource::make($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorizeTenantUser($user);

        $user = $this->userService->update($user, $request->validated());

        return UserResource::make($user);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorizeTenantUser($user);

        $this->userService->delete(request()->user(), $user);

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    private function authorizeTenantUser(User $user): void
    {
        $actor = request()->user();

        if ($user->tenant_id !== $actor->tenant_id || $user->is_platform_admin) {
            abort(404);
        }
    }
}
