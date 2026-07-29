<?php

namespace App\Services\Users;

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

    public function tenantUserCount(Tenant $tenant): int
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_platform_admin', false)
            ->count();
    }

    public function canAddUser(Tenant $tenant): bool
    {
        $plan = $tenant->plan;

        if ($plan === null) {
            return false;
        }

        return $this->tenantUserCount($tenant) < $plan->max_users;
    }

    public function create(Tenant $tenant, array $data): User
    {
        if (! $this->canAddUser($tenant)) {
            throw ValidationException::withMessages([
                'mobile' => ['Your plan user limit has been reached. Upgrade to add more users.'],
            ]);
        }

        $this->assertValidRole($data['role']);

        return DB::transaction(function () use ($tenant, $data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'mobile' => $data['mobile'],
                'pin_hash' => $data['pin'],
                'tenant_id' => $tenant->id,
                'is_platform_admin' => false,
            ]);

            $user->assignRole($data['role']);

            return $user->load('roles');
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

            return $user->fresh(['roles']);
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
