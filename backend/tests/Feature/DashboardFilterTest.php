<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardFilterTest extends TestCase
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

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_dashboard_filters_change_month_sales_totals(): void
    {
        $dam = Branch::query()->where('code', 'DAM')->firstOrFail();
        $alp = Branch::query()->where('code', 'ALP')->firstOrFail();
        $whDam = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $whAlp = Warehouse::query()->where('branch_id', $alp->id)->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $userId = User::query()->first()->id;

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-DASH-DAM-SYP',
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $whDam->id,
            'branch_id' => $dam->id,
            'status' => 'posted',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 1000,
            'subtotal' => 1000,
            'tax_amount' => 0,
            'total' => 1000,
            'paid_amount' => 0,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-DASH-ALP-USD',
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $whAlp->id,
            'branch_id' => $alp->id,
            'status' => 'posted',
            'currency' => 'USD',
            'exchange_rate' => 15000,
            'base_amount' => 30000,
            'subtotal' => 2,
            'tax_amount' => 0,
            'total' => 2,
            'paid_amount' => 0,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        $all = $this->getJson('/api/dashboard/summary?days=7');
        $all->assertOk()
            ->assertJsonPath('data.currency', 'SYP')
            ->assertJsonPath('data.filter_branch_id', null)
            ->assertJsonPath('data.filter_currency', null);

        $allMonthSales = (float) $all->json('data.month_sales');
        $this->assertEqualsWithDelta(31000.0, $allMonthSales, 0.01);

        $byDam = $this->getJson('/api/dashboard/summary?days=7&branch_id='.$dam->id);
        $byDam->assertOk()->assertJsonPath('data.filter_branch_id', $dam->id);
        $this->assertEqualsWithDelta(1000.0, (float) $byDam->json('data.month_sales'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $byDam->json('data.revenue'), 0.01);

        $byAlp = $this->getJson('/api/dashboard/summary?days=7&branch_id='.$alp->id);
        $byAlp->assertOk();
        $this->assertEqualsWithDelta(30000.0, (float) $byAlp->json('data.month_sales'), 0.01);

        $byUsd = $this->getJson('/api/dashboard/summary?days=7&currency=USD');
        $byUsd->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.filter_currency', 'USD');
        $this->assertEqualsWithDelta(2.0, (float) $byUsd->json('data.month_sales'), 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $byUsd->json('data.revenue'), 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $byUsd->json('data.receivables'), 0.01);

        $byDamUsd = $this->getJson('/api/dashboard/summary?days=7&branch_id='.$dam->id.'&currency=USD');
        $byDamUsd->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $byDamUsd->json('data.month_sales'), 0.01);
    }
}
