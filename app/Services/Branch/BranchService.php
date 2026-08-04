<?php

namespace App\Services\Branch;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchService
{
    public function __construct(
        private readonly BranchScopeService $branchScope,
    ) {}

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Store>
     */
    public function listForUser(User $user)
    {
        return $this->branchScope->accessibleBranches($user);
    }

    public function update(User $user, Store $store, array $data): Store
    {
        if (! $this->branchScope->userCanAccessBranch($user, $store)) {
            abort(404);
        }

        if (! $user->hasRole('owner')) {
            abort(403);
        }

        $store->update([
            'name' => $data['name'] ?? $store->name,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $store->phone,
            'address' => array_key_exists('address', $data) ? $data['address'] : $store->address,
        ]);

        return $store->fresh(['plan']);
    }

    public function setDefaultBranch(User $user, Store $store): User
    {
        if (! $user->hasRole('owner')) {
            abort(403);
        }

        if (! $this->branchScope->userCanAccessBranch($user, $store)) {
            abort(404);
        }

        $user->update(['default_store_id' => $store->id]);

        return $user->fresh(['defaultStore']);
    }

    public function suspend(Store $store): Store
    {
        $store->suspend();

        return $store->fresh(['plan']);
    }

    public function resume(Store $store): Store
    {
        $store->resume();

        return $store->fresh(['plan']);
    }

    public function purgeExpired(Store $store): void
    {
        if ($store->data_purge_scheduled_at === null || $store->data_purge_scheduled_at->isFuture()) {
            return;
        }

        if ($store->is_default) {
            throw ValidationException::withMessages([
                'branch' => ['Default branch cannot be purged automatically.'],
            ]);
        }

        DB::transaction(function () use ($store) {
            $store->delete();
        });
    }
}
