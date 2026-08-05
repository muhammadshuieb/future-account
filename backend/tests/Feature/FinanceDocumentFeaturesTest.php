<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\JournalDetail;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Receipt;
use App\Models\SalesInvoice;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CurrencyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceDocumentFeaturesTest extends TestCase
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
        app(CurrencyService::class)->ensureSeeded();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_rejects_currency_exchange_when_source_balance_insufficient(): void
    {
        $cash = Account::query()->where('code', '1101')->firstOrFail();
        $fx = Account::query()->where('code', '1105')->firstOrFail();
        $syp = CashBox::query()->create([
            'code' => 'SYP-T',
            'name' => 'صندوق اختبار',
            'account_id' => $cash->id,
            'opening_balance' => 1000,
            'currency' => 'SYP',
            'is_active' => true,
        ]);
        $usd = CashBox::query()->create([
            'code' => 'USD-T',
            'name' => 'دولار اختبار',
            'account_id' => $fx->id,
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $syp->id,
            'target_cash_box_id' => $usd->id,
            'source_currency' => 'SYP',
            'target_currency' => 'USD',
            'source_amount' => 5000,
            'target_amount' => 1,
            'exchange_rate' => 5000,
            'status' => 'posted',
        ]);

        $response->assertStatus(422);
    }

    public function test_sales_invoice_payment_types_cash_credit_partial(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        // Documents default to the base currency (USD), so payments need a USD box.
        $cashBox = CashBox::query()->firstOrCreate(
            ['code' => 'CASH-USD'],
            [
                'name' => 'صندوق الدولار',
                'account_id' => Account::query()->where('code', '1101')->value('id'),
                'opening_balance' => 0,
                'currency' => 'USD',
                'is_active' => true,
            ]
        );

        // Seed stock via purchase
        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 100, 'tax_rate' => 0]],
        ])->assertCreated();

        // Credit
        $credit = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]],
        ]);
        $credit->assertCreated()->assertJsonPath('data.payment_type', 'credit');
        $this->assertEquals(0, (float) SalesInvoice::query()->find($credit->json('data.id'))->paid_amount);
        $this->assertSame(0, Receipt::query()->where('sales_invoice_id', $credit->json('data.id'))->count());

        // Cash
        $cash = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'cash_box_id' => $cashBox->id,
            'payment_type' => 'cash',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]],
        ]);
        $cash->assertCreated()->assertJsonPath('data.payment_type', 'cash');
        $cashInv = SalesInvoice::query()->findOrFail($cash->json('data.id'));
        $this->assertEqualsWithDelta(1000, (float) $cashInv->paid_amount, 0.01);
        $this->assertSame(1, Receipt::query()->where('sales_invoice_id', $cashInv->id)->where('status', 'posted')->count());

        // Partial
        $partial = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'cash_box_id' => $cashBox->id,
            'payment_type' => 'partial',
            'paid_amount' => 400,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]],
        ]);
        $partial->assertCreated()->assertJsonPath('data.payment_type', 'partial');
        $partialInv = SalesInvoice::query()->findOrFail($partial->json('data.id'));
        $this->assertEqualsWithDelta(400, (float) $partialInv->paid_amount, 0.01);

        $stmt = $this->getJson("/api/customers/{$customer->id}/statement")->json('data');
        // credit 1000 + cash 1000 + partial 1000 - receipts 1000 - 400 = 1600
        $this->assertEqualsWithDelta(1600, (float) $stmt['balance'], 0.01);
    }

    public function test_purchase_invoice_tax_posts_to_input_vat_and_payment_types(): void
    {
        Setting::query()->updateOrCreate(['key' => 'tax_enabled'], ['value' => '1', 'group' => 'finance', 'label' => 'tax']);
        Setting::query()->updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'group' => 'finance', 'label' => 'rate']);

        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();
        $cashBox = CashBox::query()->firstOrCreate(
            ['code' => 'CASH-USD'],
            [
                'name' => 'صندوق الدولار',
                'account_id' => Account::query()->where('code', '1101')->value('id'),
                'opening_balance' => 100000,
                'currency' => 'USD',
                'is_active' => true,
            ]
        );

        $response = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'cash_box_id' => $cashBox->id,
            'payment_type' => 'partial',
            'paid_amount' => 550,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100, 'tax_rate' => 10]],
        ]);

        $response->assertCreated();
        $invoice = PurchaseInvoice::query()->findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(1000, (float) $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(100, (float) $invoice->tax_amount, 0.01);
        $this->assertEqualsWithDelta(1100, (float) $invoice->total, 0.01);
        $this->assertEqualsWithDelta(550, (float) $invoice->paid_amount, 0.01);
        $this->assertSame('partial', $invoice->payment_type);

        $vatInput = Account::query()->where('code', '1106')->firstOrFail();
        $vatDebit = (float) JournalDetail::query()
            ->where('journal_entry_id', $invoice->journal_entry_id)
            ->where('account_id', $vatInput->id)
            ->sum('debit');
        $this->assertEqualsWithDelta(100, $vatDebit, 0.01);

        $this->assertSame(1, SupplierPayment::query()->where('purchase_invoice_id', $invoice->id)->where('status', 'posted')->count());

        $customers = $this->getJson('/api/suppliers')->json('data');
        $row = collect($customers)->firstWhere('id', $supplier->id);
        $this->assertArrayHasKey('balance', $row);
        $this->assertEqualsWithDelta(550, (float) $row['balance'], 0.01);
    }

    public function test_attachment_upload_requires_auth_and_stores_file(): void
    {
        Storage::fake('attachments');

        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $invoice = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->json('data');

        $file = UploadedFile::fake()->image('receipt.jpg', 200, 200);

        $upload = $this->post('/api/attachments', [
            'attachable_type' => 'purchase_invoice',
            'attachable_id' => $invoice['id'],
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $upload->assertCreated();
        $attachment = Attachment::query()->firstOrFail();
        Storage::disk('attachments')->assertExists($attachment->path);

        $list = $this->getJson('/api/attachments?attachable_type=purchase_invoice&attachable_id='.$invoice['id']);
        $list->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        $this->post('/api/attachments', [
            'attachable_type' => 'purchase_invoice',
            'attachable_id' => $invoice['id'],
            'file' => UploadedFile::fake()->image('x.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }
}
