<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SensitiveFinanceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            ErpDemoSeeder::class,
        ]);
    }

    protected function actingAsElevatedWithoutSensitiveFinance(): User
    {
        $role = Role::findOrCreate('showroom_accountant');
        $role->syncPermissions([
            'dashboard.view',
            'accounts.view',
            'accounts.manage',
            'journals.view',
            'journals.manage',
            'sales.view',
            'sales.manage',
            'customers.view',
            'customers.manage',
            'cash.view',
            'cash.manage',
            'reports.view',
            // deliberately omit reports.profits.view and reports.capital.view
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_income_statement_and_profit_require_profits_permission(): void
    {
        $this->actingAsElevatedWithoutSensitiveFinance();

        $this->getJson('/api/reports/income-statement')->assertForbidden();
        $this->getJson('/api/reports/profit')->assertForbidden();
        $this->getJson('/api/exports/reports/income-statement')->assertForbidden();
        $this->getJson('/api/exports/reports/profit')->assertForbidden();
    }

    public function test_balance_sheet_and_trial_balance_redact_capital_without_permission(): void
    {
        $this->actingAsElevatedWithoutSensitiveFinance();

        $tb = $this->getJson('/api/reports/trial-balance')->assertOk()->json('data');
        $this->assertTrue($tb['capital_redacted'] ?? false);
        $this->assertFalse(collect($tb['rows'])->contains(fn ($r) => ($r['type'] ?? null) === 'equity'));

        $bs = $this->getJson('/api/reports/balance-sheet')->assertOk()->json('data');
        $this->assertTrue($bs['capital_redacted'] ?? false);
        $this->assertTrue($bs['profits_redacted'] ?? false);
        $this->assertSame([], $bs['equity']);
        $this->assertNull($bs['net_income']);
    }

    public function test_equity_accounts_and_gl_are_hidden_without_capital_permission(): void
    {
        $this->actingAsElevatedWithoutSensitiveFinance();

        $accounts = $this->getJson('/api/accounts')->assertOk()->json('data');
        $this->assertFalse(collect($accounts)->contains(fn ($a) => ($a['type'] ?? null) === 'equity'));

        $capital = Account::query()->where('code', '3101')->firstOrFail();
        $this->getJson("/api/accounts/{$capital->id}")->assertForbidden();
        $this->getJson("/api/reports/general-ledger?account_id={$capital->id}")->assertForbidden();
    }

    public function test_dashboard_and_branch_complete_redact_profits(): void
    {
        $this->actingAsElevatedWithoutSensitiveFinance();

        $dash = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');
        $this->assertTrue($dash['profits_redacted'] ?? false);
        $this->assertNull($dash['revenue']);
        $this->assertNull($dash['expense']);
        $this->assertNull($dash['net_income']);

        $branch = Branch::query()->where('code', 'DAM')->firstOrFail();
        $complete = $this->getJson("/api/reports/branch-complete?branch_id={$branch->id}")->assertOk()->json('data');
        $this->assertNull($complete['profit']);
        $this->assertTrue($complete['profits_redacted'] ?? false);
    }

    public function test_admin_and_accountant_still_see_capital_and_profits(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/reports/income-statement')->assertOk();
        $this->getJson('/api/reports/profit')->assertOk();
        $bs = $this->getJson('/api/reports/balance-sheet')->assertOk()->json('data');
        $this->assertArrayNotHasKey('capital_redacted', $bs);
        $this->assertArrayNotHasKey('profits_redacted', $bs);
        $this->assertIsArray($bs['equity']);
        $this->assertNotNull($bs['net_income']);

        $capital = Account::query()->where('code', '3101')->firstOrFail();
        $this->getJson("/api/accounts/{$capital->id}")->assertOk();
        $this->getJson("/api/reports/general-ledger?account_id={$capital->id}")->assertOk();

        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->assignRole('accountant');
        Sanctum::actingAs($accountant);

        $this->getJson('/api/reports/income-statement')->assertOk();
        $accounts = $this->getJson('/api/accounts')->assertOk()->json('data');
        $this->assertTrue(collect($accounts)->contains(fn ($a) => ($a['type'] ?? null) === 'equity'));
    }

    public function test_sales_role_has_reports_but_not_sensitive_finance_by_default(): void
    {
        $sales = Role::findByName('sales', 'web');
        $this->assertTrue($sales->hasPermissionTo('reports.view'));
        $this->assertFalse($sales->hasPermissionTo('reports.profits.view'));
        $this->assertFalse($sales->hasPermissionTo('reports.capital.view'));

        $accountant = Role::findByName('accountant', 'web');
        $this->assertTrue($accountant->hasPermissionTo('reports.profits.view'));
        $this->assertTrue($accountant->hasPermissionTo('reports.capital.view'));

        $admin = Role::findByName('admin', 'web');
        $this->assertTrue($admin->hasPermissionTo('reports.profits.view'));
        $this->assertTrue($admin->hasPermissionTo('reports.capital.view'));
    }
}
