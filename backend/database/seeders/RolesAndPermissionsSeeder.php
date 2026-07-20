<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',
            'accounts.view',
            'accounts.manage',
            'journals.view',
            'journals.manage',
            'journals.post',
            'settings.manage',
            'sales.view',
            'sales.manage',
            'purchases.view',
            'purchases.manage',
            'warehouse.view',
            'warehouse.manage',
            'customers.view',
            'customers.manage',
            'suppliers.view',
            'suppliers.manage',
            'cash.view',
            'cash.manage',
            'hr.view',
            'hr.manage',
            'reports.view',
            'users.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $admin = Role::findOrCreate('admin');
        $admin->syncPermissions(Permission::all());

        $accountant = Role::findOrCreate('accountant');
        $accountant->syncPermissions([
            'dashboard.view',
            'accounts.view',
            'accounts.manage',
            'journals.view',
            'journals.manage',
            'journals.post',
            'settings.manage',
            'reports.view',
            'customers.view',
            'suppliers.view',
            'cash.view',
            'cash.manage',
            'hr.view',
        ]);

        $warehouse = Role::findOrCreate('warehouse');
        $warehouse->syncPermissions([
            'dashboard.view',
            'warehouse.view',
            'warehouse.manage',
            'reports.view',
        ]);

        $sales = Role::findOrCreate('sales');
        $sales->syncPermissions([
            'dashboard.view',
            'sales.view',
            'sales.manage',
            'customers.view',
            'customers.manage',
            'warehouse.view',
            'cash.view',
            'reports.view',
        ]);

        $purchasing = Role::findOrCreate('purchasing');
        $purchasing->syncPermissions([
            'dashboard.view',
            'purchases.view',
            'purchases.manage',
            'suppliers.view',
            'suppliers.manage',
            'warehouse.view',
            'cash.view',
            'reports.view',
        ]);
    }
}
