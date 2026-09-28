<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ExcelExportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExcelExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_guest_cannot_export_module(): void
    {
        $this->getJson('/api/exports/customers')->assertUnauthorized();
    }

    public function test_sales_role_can_export_customers_xlsx(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('sales');
        Sanctum::actingAs($user);

        Customer::query()->create([
            'code' => 'C-001',
            'name' => 'عميل تجريبي',
            'phone' => '0912345678',
            'is_active' => true,
        ]);

        $response = $this->get('/api/exports/customers');
        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type'),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        $this->assertSame('العملاء', $sheet->getTitle());
        $this->assertSame('الكود', $sheet->getCell('A1')->getValue());
        unlink($tmp);
    }

    public function test_customer_statement_excel_includes_invoice_line_items(): void
    {
        $this->seed([ChartOfAccountsSeeder::class, ErpDemoSeeder::class]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $product->update(['brand' => 'HP', 'model' => 'LaserJet']);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 800, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $sales = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1200, 'tax_rate' => 0],
            ],
        ]);
        $sales->assertCreated();
        $invoiceNumber = (string) $sales->json('data.invoice_number');

        $response = $this->get('/api/exports/reports/customer-statement?customer_id='.$customer->id);
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();

        $this->assertSame('الصنف', $sheet->getCell('H1')->getValue());
        $this->assertSame('الماركة', $sheet->getCell('I1')->getValue());
        $this->assertSame('الموديل', $sheet->getCell('J1')->getValue());

        $foundInvoice = false;
        $foundLine = false;
        foreach ($sheet->getRowIterator(2) as $row) {
            $cells = [];
            foreach ($row->getCellIterator('A', 'M') as $cell) {
                $cells[] = (string) $cell->getValue();
            }
            if (($cells[1] ?? '') === 'فاتورة' && ($cells[2] ?? '') === $invoiceNumber) {
                $foundInvoice = true;
            }
            if (trim($cells[1] ?? '') === 'بند فاتورة'
                && ($cells[2] ?? '') === $invoiceNumber
                && ($cells[7] ?? '') === 'طابعة ليزر'
                && ($cells[8] ?? '') === 'HP'
                && ($cells[9] ?? '') === 'LaserJet'
                && (float) ($cells[10] ?? 0) === 2.0
                && (float) ($cells[11] ?? 0) === 1200.0
                && (float) ($cells[12] ?? 0) === 2400.0
            ) {
                $foundLine = true;
            }
        }

        unlink($tmp);
        $this->assertTrue($foundInvoice, 'Expected invoice row in customer statement Excel');
        $this->assertTrue($foundLine, 'Expected invoice line-item row in customer statement Excel');
    }

    public function test_supplier_statement_excel_includes_invoice_line_items(): void
    {
        $this->seed([ChartOfAccountsSeeder::class, ErpDemoSeeder::class]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $product->update(['brand' => 'Canon', 'model' => 'G3010']);

        $purchase = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 800, 'tax_rate' => 0],
            ],
        ]);
        $purchase->assertCreated();
        $invoiceNumber = (string) $purchase->json('data.invoice_number');

        $response = $this->get('/api/exports/reports/supplier-statement?supplier_id='.$supplier->id);
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();

        $foundInvoice = false;
        $foundLine = false;
        foreach ($sheet->getRowIterator(2) as $row) {
            $cells = [];
            foreach ($row->getCellIterator('A', 'M') as $cell) {
                $cells[] = (string) $cell->getValue();
            }
            if (($cells[1] ?? '') === 'فاتورة' && ($cells[2] ?? '') === $invoiceNumber) {
                $foundInvoice = true;
            }
            if (trim($cells[1] ?? '') === 'بند فاتورة'
                && ($cells[2] ?? '') === $invoiceNumber
                && ($cells[7] ?? '') === 'طابعة ليزر'
                && ($cells[8] ?? '') === 'Canon'
                && ($cells[9] ?? '') === 'G3010'
                && (float) ($cells[10] ?? 0) === 5.0
                && (float) ($cells[11] ?? 0) === 800.0
                && (float) ($cells[12] ?? 0) === 4000.0
            ) {
                $foundLine = true;
            }
        }

        unlink($tmp);
        $this->assertTrue($foundInvoice, 'Expected invoice row in supplier statement Excel');
        $this->assertTrue($foundLine, 'Expected invoice line-item row in supplier statement Excel');
    }

    public function test_non_admin_cannot_export_full_archive(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('sales');
        Sanctum::actingAs($user);

        $this->get('/api/exports/full')->assertForbidden();
    }

    public function test_admin_full_archive_creates_valid_xlsx(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $response = $this->get('/api/exports/full');
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $response->streamedContent());
        $book = IOFactory::load($tmp);
        $this->assertGreaterThanOrEqual(5, $book->getSheetCount());
        unlink($tmp);
    }

    public function test_full_archive_service_writes_file(): void
    {
        $dir = storage_path('app/testing-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $meta = app(ExcelExportService::class)->saveFullArchiveBeside(
            'future_account_test_20260101_120000.dump',
            $dir,
        );

        $this->assertSame('future_account_test_20260101_120000.xlsx', $meta['filename']);
        $this->assertFileExists($meta['path']);
        $this->assertGreaterThan(100, $meta['size']);

        $book = IOFactory::load($meta['path']);
        $this->assertGreaterThanOrEqual(5, $book->getSheetCount());
        @unlink($meta['path']);
    }
}
