<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const GUARD = 'web';

    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'owner' => [
            'dashboard.view',
            'reports.view',
            'subscription.manage',
            'settings.view',
            'settings.payment_methods',
            'catalog.manage',
            'purchases.manage',
            'expenses.manage',
            'staff.manage',
            'customers.manage',
            'users.manage',
            'pos.use',
        ],
        'cashier' => [
            'dashboard.view',
            'settings.view',
            'catalog.manage',
            'purchases.manage',
            'expenses.manage',
            'staff.manage',
            'customers.manage',
            'pos.use',
        ],
        'staff' => [
            'settings.view',
            'catalog.manage',
            'customers.manage',
            'pos.use',
        ],
        'super_admin' => [
            'platform.tenants',
            'platform.coupons',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissions = collect(self::ROLE_PERMISSIONS)
            ->flatten()
            ->unique()
            ->values();

        foreach ($allPermissions as $permission) {
            Permission::query()->firstOrCreate(
                ['name' => $permission, 'guard_name' => self::GUARD]
            );
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleName, 'guard_name' => self::GUARD]
            );

            $role->syncPermissions($permissions);
        }
    }
}
