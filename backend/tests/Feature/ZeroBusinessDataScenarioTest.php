<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bank;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Receipt;
use App\Models\SalesInvoice;
use App\Models\Setting;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashService;
use Database\Seeders\ZeroBusinessDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZeroBusinessDataScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ZeroBusinessDataSeeder::class]);

        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->admin);
    }

    public function test_zero_reset_can_build_a_persistable_accounting_scenario(): void
    {
        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, Warehouse::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Supplier::query()->count());
        $this->assertSame(0, Employee::query()->count());
        $this->assertSame(0, SalesInvoice::query()->count());
        $this->assertSame(0, PurchaseInvoice::query()->count());
        $this->assertSame(0, Receipt::query()->count());
        $this->assertSame(0, SupplierPayment::query()->count());
        $this->assertNull(Setting::getValue('default_cash_box_id'));
        $this->assertNull(Setting::getValue('default_warehouse_id'));

        $accounts = Account::query()->whereIn('code', ['1101', '1102', '1103', '1104', '1105', '2101', '3101'])
            ->get()
            ->keyBy('code');

        $companyId = $this->postJson('/api/companies', [
            'code' => 'ZERO',
            'name' => 'شركة التصفير المحاسبية',
            'name_en' => 'Zero Accounting Co',
            'currency' => 'USD',
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $branchId = $this->postJson('/api/branches', [
            'company_id' => $companyId,
            'code' => 'MAIN',
            'name' => 'الفرع الرئيسي',
            'city' => 'دمشق',
            'address' => 'ساحة الأمويين',
            'is_main' => true,
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $warehouseAId = $this->postJson('/api/warehouses', [
            'code' => 'WH-A',
            'name' => 'المخزن أ',
            'branch_id' => $branchId,
            'location' => 'دمشق',
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $warehouseBId = $this->postJson('/api/warehouses', [
            'code' => 'WH-B',
            'name' => 'المخزن ب',
            'branch_id' => $branchId,
            'location' => 'دمشق',
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $categoryId = $this->postJson('/api/categories', [
            'name' => 'أجهزة مكتبية',
        ])->assertCreated()->json('data.id');

        $unitId = $this->postJson('/api/units', [
            'name' => 'قطعة',
            'symbol' => 'pcs',
        ])->assertCreated()->json('data.id');

        $productAId = $this->postJson('/api/products', [
            'sku' => 'ITEM-A',
            'barcode' => '6282000000001',
            'name' => 'طابعة مكتبية',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'cost_price' => 50,
            'sale_price' => 100,
            'reorder_level' => 2,
            'is_active' => true,
            'warehouse_id' => $warehouseAId,
            'opening_quantity' => 0,
        ])->assertCreated()->json('data.id');

        $productBId = $this->postJson('/api/products', [
            'sku' => 'ITEM-B',
            'barcode' => '6282000000002',
            'name' => 'حبر ليزر',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'cost_price' => 20,
            'sale_price' => 40,
            'reorder_level' => 3,
            'is_active' => true,
            'warehouse_id' => $warehouseAId,
            'opening_quantity' => 0,
        ])->assertCreated()->json('data.id');

        $customerId = $this->postJson('/api/customers', [
            'code' => 'CUS-Z1',
            'name' => 'عميل الاختبار',
            'branch_id' => $branchId,
            'credit_limit' => 1000,
            'account_id' => $accounts['1103']->id,
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $supplierId = $this->postJson('/api/suppliers', [
            'code' => 'SUP-Z1',
            'name' => 'مورد الاختبار',
            'branch_id' => $branchId,
            'credit_limit' => 1000,
            'account_id' => $accounts['2101']->id,
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/employees', [
            'employee_number' => 'EMP-Z1',
            'name' => 'محاسب التشغيل',
            'branch_id' => $branchId,
            'job_title' => 'محاسب',
            'department' => 'المالية',
            'hire_date' => now()->toDateString(),
            'basic_salary' => 0,
            'is_active' => true,
        ])->assertCreated();

        // Cash box balances are document driven, so the opening float mirrors the capital
        // journal entry posted below (which is what makes the GL and the box agree).
        $usdCashBoxId = $this->postJson('/api/cash-boxes', [
            'code' => 'CASH-USD',
            'name' => 'صندوق الدولار',
            'branch_id' => $branchId,
            'account_id' => $accounts['1101']->id,
            'opening_balance' => 1000,
            'currency' => 'USD',
            'is_active' => true,
            'is_default' => true,
        ])->assertCreated()->json('data.id');

        $sypCashBoxId = $this->postJson('/api/cash-boxes', [
            'code' => 'CASH-SYP',
            'name' => 'صندوق الليرة',
            'branch_id' => $branchId,
            'account_id' => $accounts['1105']->id,
            'opening_balance' => 0,
            'currency' => 'SYP',
            'is_active' => true,
            'is_default' => false,
        ])->assertCreated()->json('data.id');

        $bankId = $this->postJson('/api/banks', [
            'code' => 'BANK-USD',
            'name' => 'بنك التشغيل',
            'branch_id' => $branchId,
            'account_number' => '123456',
            'account_id' => $accounts['1102']->id,
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/journal-entries', [
            'entry_date' => now()->toDateString(),
            'branch_id' => $branchId,
            'description' => 'تمويل تشغيلي أولي',
            'reference' => 'CAP-001',
            'status' => 'posted',
            'details' => [
                ['account_id' => $accounts['1101']->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $accounts['3101']->id, 'debit' => 0, 'credit' => 1000],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'posted');

        $purchaseCreditId = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseAId,
            'branch_id' => $branchId,
            'payment_type' => 'credit',
            'currency' => 'USD',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $productAId, 'quantity' => 10, 'unit_cost' => 50, 'tax_rate' => 0],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseAId,
            'branch_id' => $branchId,
            'payment_type' => 'cash',
            'cash_box_id' => $usdCashBoxId,
            'currency' => 'USD',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $productBId, 'quantity' => 5, 'unit_cost' => 20, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseAId,
            'branch_id' => $branchId,
            'payment_type' => 'partial',
            'paid_amount' => 60,
            'cash_box_id' => $usdCashBoxId,
            'currency' => 'USD',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $productBId, 'quantity' => 8, 'unit_cost' => 20, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $salesCreditId = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseAId,
            'branch_id' => $branchId,
            'payment_type' => 'credit',
            'currency' => 'USD',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $productAId, 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseAId,
            'branch_id' => $branchId,
            'payment_type' => 'cash',
            'cash_box_id' => $usdCashBoxId,
            'currency' => 'USD',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $productAId, 'quantity' => 2, 'unit_price' => 90, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseAId,
            'branch_id' => $branchId,
            'payment_type' => 'partial',
            'paid_amount' => 50,
            'cash_box_id' => $usdCashBoxId,
            'currency' => 'USD',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $productBId, 'quantity' => 4, 'unit_price' => 40, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/receipts', [
            'receipt_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'sales_invoice_id' => $salesCreditId,
            'cash_box_id' => $usdCashBoxId,
            'method' => 'cash',
            'amount' => 120,
            'currency' => 'USD',
            'status' => 'posted',
        ])->assertCreated();

        $this->postJson('/api/supplier-payments', [
            'payment_date' => now()->toDateString(),
            'supplier_id' => $supplierId,
            'purchase_invoice_id' => $purchaseCreditId,
            'cash_box_id' => $usdCashBoxId,
            'method' => 'cash',
            'amount' => 200,
            'currency' => 'USD',
            'status' => 'posted',
        ])->assertCreated();

        $this->postJson('/api/cash-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_type' => 'cash_box',
            'from_id' => $usdCashBoxId,
            'to_type' => 'bank',
            'to_id' => $bankId,
            'amount' => 150,
            'status' => 'posted',
        ])->assertCreated();

        $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $usdCashBoxId,
            'target_cash_box_id' => $sypCashBoxId,
            'source_currency' => 'USD',
            'target_currency' => 'SYP',
            'source_amount' => 100,
            'target_amount' => 1500000,
            'exchange_rate' => 15000,
            'status' => 'posted',
        ])->assertCreated();

        $this->postJson('/api/warehouse-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $warehouseAId,
            'to_warehouse_id' => $warehouseBId,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $productAId, 'quantity' => 1],
            ],
        ])->assertCreated();

        $customerBalance = (float) $this->getJson("/api/customers/{$customerId}")->json('data.balance');
        $supplierBalance = (float) $this->getJson("/api/suppliers/{$supplierId}")->json('data.balance');
        $this->assertEqualsWithDelta(290, $customerBalance, 0.01);
        $this->assertEqualsWithDelta(400, $supplierBalance, 0.01);

        $cashBoxes = collect($this->getJson('/api/cash-boxes')->json('data'));
        $usdCash = (float) $cashBoxes->firstWhere('id', $usdCashBoxId)['balance'];
        $sypCash = (float) $cashBoxes->firstWhere('id', $sypCashBoxId)['balance'];
        $this->assertTrue(abs($usdCash - 740) < 0.01, 'cash-boxes usd balance: '.$usdCash);
        $this->assertEqualsWithDelta(1500000, $sypCash, 0.01);

        $bookBalance = (float) $this->getJson("/api/banks/{$bankId}/book-balance")->json('data.book_balance');
        $this->assertEqualsWithDelta(150, $bookBalance, 0.01);

        $this->assertSame(4.0, (float) StockLevel::query()->where('warehouse_id', $warehouseAId)->where('product_id', $productAId)->sum('quantity'));
        $this->assertSame(1.0, (float) StockLevel::query()->where('warehouse_id', $warehouseBId)->where('product_id', $productAId)->sum('quantity'));
        $this->assertSame(9.0, (float) StockLevel::query()->where('warehouse_id', $warehouseAId)->where('product_id', $productBId)->sum('quantity'));

        $inventory = $this->getJson("/api/reports/inventory?branch_id={$branchId}")->json('data');
        $rows = collect($inventory['rows'])->keyBy('sku');
        $this->assertEqualsWithDelta(5, (float) $rows['ITEM-A']['on_hand'], 0.01);
        $this->assertEqualsWithDelta(9, (float) $rows['ITEM-B']['on_hand'], 0.01);
        $this->assertEqualsWithDelta(430, (float) $inventory['total_value'], 0.01);

        $dashboard = $this->getJson("/api/dashboard/summary?branch_id={$branchId}")->json('data');
        $this->assertEqualsWithDelta(640, (float) $dashboard['revenue'], 0.01);
        $this->assertEqualsWithDelta(760, (float) $dashboard['expense'], 0.01);
        $this->assertEqualsWithDelta(290, (float) $dashboard['receivables'], 0.01);
        $this->assertEqualsWithDelta(400, (float) $dashboard['payables'], 0.01);
        $this->assertEqualsWithDelta(640, (float) $dashboard['month_sales'], 0.01);
        $this->assertEqualsWithDelta(760, (float) $dashboard['month_purchases'], 0.01);

        $cashBoxes = collect($this->getJson('/api/cash-boxes')->json('data'));
        $usdCash = (float) $cashBoxes->firstWhere('id', $usdCashBoxId)['balance'];
        $sypCash = (float) $cashBoxes->firstWhere('id', $sypCashBoxId)['balance'];
        $bookBalance = (float) $this->getJson("/api/banks/{$bankId}/book-balance")->json('data.book_balance');
        $bankBalance = (float) $this->getJson("/api/banks/{$bankId}/book-balance")->json('data.balance');

        $this->assertEqualsWithDelta($usdCash, (float) collect($dashboard['by_currency'])->firstWhere('currency', 'USD')['cash'], 0.01);
        $this->assertEqualsWithDelta($sypCash, (float) collect($dashboard['by_currency'])->firstWhere('currency', 'SYP')['cash'], 0.01);
        $this->assertEqualsWithDelta($bankBalance, (float) collect($dashboard['by_currency'])->firstWhere('currency', 'USD')['bank'], 0.01);
        $this->assertEqualsWithDelta($bookBalance, $bankBalance, 0.01);
        $this->assertEqualsWithDelta(
            (float) $dashboard['cash'] + (float) $dashboard['bank'],
            (float) $dashboard['liquidity'],
            0.01
        );

        $profit = $this->getJson("/api/reports/profit?branch_id={$branchId}")->json('data');
        $this->assertEqualsWithDelta(640, (float) $profit['sales'], 0.01);
        $this->assertEqualsWithDelta(330, (float) $profit['cogs'], 0.01);
        $this->assertEqualsWithDelta(310, (float) $profit['gross_profit'], 0.01);

        $trialBalance = $this->getJson("/api/reports/trial-balance?branch_id={$branchId}")->json('data');
        $this->assertEqualsWithDelta((float) $trialBalance['total_debit'], (float) $trialBalance['total_credit'], 0.01);

        $this->assertLedgerClosingBalance($accounts['1101']->id, 740, $branchId);
        $this->assertLedgerClosingBalance($accounts['1102']->id, 150, $branchId);
        $this->assertLedgerClosingBalance($accounts['1103']->id, 290, $branchId);
        $this->assertLedgerClosingBalance($accounts['1104']->id, 430, $branchId);
        $this->assertLedgerClosingBalance($accounts['1105']->id, 100.01, $branchId);
        $this->assertLedgerClosingBalance($accounts['2101']->id, 400, $branchId);

        $branchReport = $this->getJson("/api/reports/branch-complete?branch_id={$branchId}")->json('data');
        $this->assertEqualsWithDelta(640, (float) $branchReport['sales']['total'], 0.01);
        $this->assertEqualsWithDelta(760, (float) $branchReport['purchases']['total'], 0.01);
        $this->assertEqualsWithDelta(290, (float) $branchReport['receivables'], 0.01);
        $this->assertEqualsWithDelta(400, (float) $branchReport['payables'], 0.01);
        $this->assertEqualsWithDelta(430, (float) $branchReport['inventory_total_value'], 0.01);

        $cashService = app(CashService::class);
        $usdBox = CashBox::query()->findOrFail($usdCashBoxId);
        $sypBox = CashBox::query()->findOrFail($sypCashBoxId);
        $bank = Bank::query()->findOrFail($bankId);
        $this->assertTrue(abs($cashService->cashBoxCurrencyBalance($usdBox) - 740) < 0.01, 'service usd balance: '.$cashService->cashBoxCurrencyBalance($usdBox));
        $this->assertEqualsWithDelta(1500000, $cashService->cashBoxCurrencyBalance($sypBox), 0.01);
        $this->assertEqualsWithDelta(150, $cashService->bookBalance($bank), 0.01);

        $this->assertSame(3, SalesInvoice::query()->where('status', 'posted')->count());
        $this->assertSame(3, PurchaseInvoice::query()->where('status', 'posted')->count());
    }

    protected function assertLedgerClosingBalance(int $accountId, float $expected, int $branchId): void
    {
        $ledger = $this->getJson("/api/reports/general-ledger?account_id={$accountId}&branch_id={$branchId}")
            ->assertOk()
            ->json('data');

        $this->assertTrue(
            abs(((float) $ledger['closing_balance']) - $expected) < 0.01,
            sprintf('ledger %d closing balance: %s', $accountId, (string) $ledger['closing_balance'])
        );
    }
}
