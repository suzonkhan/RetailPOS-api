<?php

namespace App\Services\Users;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserService
{
    /** @var list<string> */
    private array $tenantRoles;

    public function __construct()
    {
        $this->tenantRoles = config('retail360.tenant_roles', ['owner', 'cashier', 'staff']);
    }

    public function branchUserCount(Store $store): int
    {
        return $store->users()
            ->where('is_platform_admin', false)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'owner'))
            ->count();
    }

    public function canAddUser(Store $store): bool
    {
        if ($store->isOnTrial()) {
            return false;
        }

        $plan = $store->plan;

        if ($plan === null) {
            return false;
        }

        return $this->branchUserCount($store) < $this->nonOwnerSeatLimit($plan->max_users);
    }

    private function nonOwnerSeatLimit(int $maxUsers): int
    {
        return max(0, $maxUsers - 1);
    }

    public function create(Tenant $tenant, Store $store, array $data): User
    {
        if (! $this->canAddUser($store)) {
            if ($store->isOnTrial()) {
                throw ValidationException::withMessages([
                    'mobile' => ['Staff cannot be added during trial. Subscribe this branch first.'],
                ]);
            }

            throw ValidationException::withMessages([
                'mobile' => ['Your plan user limit has been reached for this branch. Upgrade to add more users.'],
            ]);
        }

        $this->assertValidRole($data['role']);

        if ($data['role'] === 'owner') {
            throw ValidationException::withMessages([
                'role' => ['Additional owners cannot be invited. Use cashier or staff.'],
            ]);
        }

        return DB::transaction(function () use ($tenant, $store, $data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'mobile' => $data['mobile'],
                'pin_hash' => $data['pin'],
                'tenant_id' => $tenant->id,
                'default_store_id' => $store->id,
                'is_platform_admin' => false,
            ]);

            $user->assignRole($data['role']);
            $store->users()->attach($user->id, ['is_primary' => true]);

            return $user->load(['roles', 'stores']);
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            if (isset($data['name'])) {
                $user->name = $data['name'];
            }

            if (isset($data['pin'])) {
                $user->pin_hash = $data['pin'];
            }

            $user->save();

            if (isset($data['role'])) {
                $this->assertValidRole($data['role']);
                $this->assertCanChangeRole($user, $data['role']);
                $user->syncRoles([$data['role']]);
            }

            return $user->fresh(['roles', 'stores']);
        });
    }

    public function delete(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own account.'],
            ]);
        }

        if ($target->hasRole('owner') && $this->ownerCount($target->tenant) <= 1) {
            throw ValidationException::withMessages([
                'user' => ['At least one owner is required for this shop.'],
            ]);
        }

        $target->tokens()->delete();
        $target->delete();
    }

    private function ownerCount(?Tenant $tenant): int
    {
        if ($tenant === null) {
            return 0;
        }

        return User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_platform_admin', false)
            ->role('owner')
            ->count();
    }

    private function assertValidRole(string $role): void
    {
        if (! in_array($role, $this->tenantRoles, true)) {
            throw ValidationException::withMessages([
                'role' => ['Invalid role. Allowed: '.implode(', ', $this->tenantRoles).'.'],
            ]);
        }

        if (! Role::query()->where('name', $role)->exists()) {
            throw ValidationException::withMessages([
                'role' => ['Role is not configured.'],
            ]);
        }
    }

    private function assertCanChangeRole(User $user, string $newRole): void
    {
        if ($user->hasRole('owner') && $newRole !== 'owner' && $this->ownerCount($user->tenant) <= 1) {
            throw ValidationException::withMessages([
                'role' => ['At least one owner is required for this shop.'],
            ]);
        }
    }
}
