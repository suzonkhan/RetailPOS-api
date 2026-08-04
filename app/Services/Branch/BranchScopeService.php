<?php

namespace App\Services\Branch;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

class BranchScopeService
{
    public function resolveBranchIdFromRequest(Request $request, User $user): ?int
    {
        $header = $request->header('X-Branch-Id');

        if ($header !== null && $header !== '') {
            return (int) $header;
        }

        if ($user->default_store_id !== null) {
            return (int) $user->default_store_id;
        }

        if ($user->hasRole('owner')) {
            $default = Store::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('is_default', true)
                ->value('id');

            if ($default !== null) {
                return (int) $default;
            }

            return Store::query()
                ->where('tenant_id', $user->tenant_id)
                ->orderBy('id')
                ->value('id');
        }

        return Store::query()
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->value('id');
    }

    public function resolveBranch(User $user, ?int $branchId = null): Store
    {
        $branchId ??= $this->resolveBranchIdFromRequest(request(), $user);

        if ($branchId === null) {
            abort(404, 'Branch not found.');
        }

        $store = Store::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($branchId)
            ->first();

        if ($store === null) {
            abort(404, 'Branch not found.');
        }

        if (! $this->userCanAccessBranch($user, $store)) {
            abort(403, 'You do not have access to this branch.');
        }

        return $store;
    }

    public function userCanAccessBranch(User $user, Store $store): bool
    {
        if ((int) $store->tenant_id !== (int) $user->tenant_id) {
            return false;
        }

        if ($user->hasRole('owner')) {
            return true;
        }

        return $store->users()->where('users.id', $user->id)->exists();
    }

    public function authorizeBranchResource(User $user, object $model): void
    {
        if (! isset($model->store_id)) {
            return;
        }

        $store = $this->resolveBranch($user);

        if ((int) $model->store_id !== (int) $store->id) {
            abort(404);
        }

        if (isset($model->tenant_id) && (int) $model->tenant_id !== (int) $user->tenant_id) {
            abort(404);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Store>
     */
    public function accessibleBranches(User $user)
    {
        if ($user->hasRole('owner')) {
            return Store::query()
                ->where('tenant_id', $user->tenant_id)
                ->with('plan')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get();
        }

        return Store::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->with('plan')
            ->orderBy('name')
            ->get();
    }
}
