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
            'sales.invoices.edit',
            'quotes.view',
            'quotes.manage',
            'print_invoices.view',
            'print_invoices.manage',
            'purchases.view',
            'purchases.manage',
            'purchases.invoices.edit',
            'warehouse.view',
            'warehouse.manage',
            'warehouse.products.request',
            'warehouse.adjustments.request',
            'warehouse.transfers.request',
            'warehouse.counts.request',
            'warehouse.master.request',
            'warehouse.approvals.view_own',
            'warehouse.approvals.review',
            'customers.view',
            'customers.manage',
            'suppliers.view',
            'suppliers.manage',
            'cash.view',
            'cash.manage',
            'hr.view',
            'hr.manage',
            'reports.view',
            // Sensitive financials — not implied by reports.view or elevated “admin-like” custom roles.
            'reports.profits.view',
            'reports.capital.view',
            'ftth.view',
            'ftth.manage',
            'price_lists.view',
            'price_lists.manage',
            'users.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $admin = Role::findOrCreate('admin');
        // True system admin keeps every permission (including capital + profits).
        $admin->syncPermissions(Permission::all());

        // View-only role: assign to a specific manager who may see prices but not the dashboard.
        $priceViewer = Role::findOrCreate('price_viewer');
        $priceViewer->syncPermissions([
            'price_lists.view',
        ]);

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
            'reports.profits.view',
            'reports.capital.view',
            'ftth.view',
            'ftth.manage',
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
            'ftth.view',
            'ftth.manage',
        ]);

        $warehouseManager = Role::findOrCreate('warehouse_manager');
        $warehouseManager->syncPermissions([
            'dashboard.view',
            'warehouse.view',
            'warehouse.products.request',
            'warehouse.adjustments.request',
            'warehouse.transfers.request',
            'warehouse.counts.request',
            'warehouse.master.request',
            'warehouse.approvals.view_own',
        ]);

        $sales = Role::findOrCreate('sales');
        $sales->syncPermissions([
            'dashboard.view',
            'sales.view',
            'sales.manage',
            'quotes.view',
            'quotes.manage',
            'print_invoices.view',
            'print_invoices.manage',
            'customers.view',
            'customers.manage',
            'warehouse.view',
            'cash.view',
            'reports.view',
            'ftth.view',
            'ftth.manage',
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
