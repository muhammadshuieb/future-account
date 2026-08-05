#!/usr/bin/env bash
# One-off: register the warehouse-approval permissions and the warehouse_manager
# role on an existing install (deploy only runs migrations, not the roles seeder).
set -euo pipefail
cd /opt/future-account
set -a
. ./.env.prod
set +a

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

echo "== tables =="
"${COMPOSE[@]}" exec -T postgres psql -U future -d future_account -t -c \
  "select table_name from information_schema.tables where table_name in ('user_warehouse','warehouse_approval_requests') order by table_name;"

cat > /tmp/warehouse_approvals_setup.php <<'PHP'
<?php

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app()[PermissionRegistrar::class]->forgetCachedPermissions();

$new = [
    'warehouse.products.request',
    'warehouse.adjustments.request',
    'warehouse.transfers.request',
    'warehouse.counts.request',
    'warehouse.master.request',
    'warehouse.approvals.view_own',
    'warehouse.approvals.review',
];
foreach ($new as $permission) {
    Permission::findOrCreate($permission);
}

Role::findOrCreate('admin')->syncPermissions(Permission::all());

Role::findOrCreate('warehouse_manager')->syncPermissions([
    'dashboard.view',
    'warehouse.view',
    'warehouse.products.request',
    'warehouse.adjustments.request',
    'warehouse.transfers.request',
    'warehouse.counts.request',
    'warehouse.master.request',
    'warehouse.approvals.view_own',
]);

app()[PermissionRegistrar::class]->forgetCachedPermissions();

echo 'roles: '.Role::query()->pluck('name')->implode(', ')."\n";
echo 'admin permissions: '.Role::findByName('admin')->permissions()->count()."\n";
echo 'warehouse_manager permissions: '.Role::findByName('warehouse_manager')->permissions()->pluck('name')->implode(', ')."\n";
PHP

"${COMPOSE[@]}" cp /tmp/warehouse_approvals_setup.php backend:/tmp/warehouse_approvals_setup.php
echo "== roles =="
"${COMPOSE[@]}" exec -T backend php /tmp/warehouse_approvals_setup.php
"${COMPOSE[@]}" exec -T backend php artisan cache:clear --no-ansi >/dev/null
echo "done"
