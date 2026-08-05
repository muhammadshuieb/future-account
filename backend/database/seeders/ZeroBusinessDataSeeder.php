<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class ZeroBusinessDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            CurrencySeeder::class,
            AdminUserSeeder::class,
        ]);

        foreach (['default_branch_id', 'default_warehouse_id', 'default_cash_box_id'] as $key) {
            Setting::forgetKey($key);
        }
    }
}
