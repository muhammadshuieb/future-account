<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Services\PurchaseInvoiceLineImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PurchaseInvoiceLineImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('admin');
        Sanctum::actingAs($this->user);
    }

    public function test_template_download_has_arabic_headers_and_example_row(): void
    {
        $response = $this->get('/api/imports/purchase-invoices/lines/template');
        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type'),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'pi-tpl');
        file_put_contents($tmp, $response->streamedContent());
        $book = IOFactory::load($tmp);
        $sheet = $book->getSheetByName('البنود');
        $this->assertNotNull($sheet);
        $this->assertSame('الموديل', $sheet->getCell('A1')->getValue());
        $this->assertSame('اسم الصنف', $sheet->getCell('B1')->getValue());
        $this->assertSame('الماركة', $sheet->getCell('C1')->getValue());
        $this->assertSame('رمز الصنف', $sheet->getCell('D1')->getValue());
        $this->assertSame('الكمية', $sheet->getCell('E1')->getValue());
        $this->assertSame('التكلفة', $sheet->getCell('F1')->getValue());
        $this->assertSame('ملاحظات البند', $sheet->getCell('G1')->getValue());
        $this->assertSame('S24', $sheet->getCell('A2')->getValue());
        $instructions = $book->getSheetByName('تعليمات');
        $this->assertNotNull($instructions);
        $this->assertStringContainsString('الموديل', (string) $instructions->getCell('A2')->getValue());
        unlink($tmp);
    }

    public function test_preview_matches_unique_model_and_autofills_catalog_fields(): void
    {
        $product = $this->makeProduct('PRD-MOD-01', 'شاشة جوال', 'سامسونج', 'Galaxy-S24', 40);

        // model + qty only — name/brand/code/cost empty → filled from catalog
        $file = $this->makeImportXlsx([
            ['Galaxy-S24', '', '', '', '8', '', ''],
        ]);

        $before = PurchaseInvoice::query()->count();
        $res = $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(1, $res->json('data.imported'));
        $this->assertSame(1, $res->json('data.matched'));
        $this->assertSame($product->id, $res->json('data.lines.0.product_id'));
        $this->assertSame('model', $res->json('data.lines.0.match_reason'));
        $this->assertSame('شاشة جوال', $res->json('data.lines.0.product_name'));
        $this->assertSame('سامسونج', $res->json('data.lines.0.brand'));
        $this->assertSame('Galaxy-S24', $res->json('data.lines.0.model'));
        $this->assertSame('PRD-MOD-01', $res->json('data.lines.0.product_code'));
        $this->assertEqualsWithDelta(8.0, (float) $res->json('data.lines.0.quantity'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $res->json('data.lines.0.unit_cost'), 0.01);
        $this->assertSame($before, PurchaseInvoice::query()->count());
    }

    public function test_preview_disambiguates_duplicate_model_with_brand(): void
    {
        $p1 = $this->makeProduct('PRD-DM-1', 'هاتف', 'سامسونج', 'X100', 100);
        $p2 = $this->makeProduct('PRD-DM-2', 'هاتف', 'شاومي', 'X100', 90);

        $file = $this->makeImportXlsx([
            ['X100', '', 'سامسونج', '', '2', '', ''],
            ['X100', '', '', '', '1', '50', ''],
        ]);

        $res = $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(2, $res->json('data.imported'));
        $this->assertSame($p1->id, $res->json('data.lines.0.product_id'));
        $this->assertSame('model_disambiguated', $res->json('data.lines.0.match_reason'));
        $this->assertNull($res->json('data.lines.1.product_id'));
        $this->assertSame('ambiguous_model', $res->json('data.lines.1.match_reason'));
        $this->assertStringContainsString('موديل', (string) $res->json('data.lines.1.warning'));
        $this->assertSame($p2->id, Product::query()->where('sku', 'PRD-DM-2')->value('id'));
    }

    public function test_preview_matches_by_sku_when_model_empty(): void
    {
        $product = $this->makeProduct('PRD-IMP-01', 'شاشة', 'سامسونج', 'S24', 40);

        $file = $this->makeImportXlsx([
            ['', '', '', 'PRD-IMP-01', '8', '55', ''],
        ]);

        $res = $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame($product->id, $res->json('data.lines.0.product_id'));
        $this->assertSame('code', $res->json('data.lines.0.match_reason'));
        $this->assertEqualsWithDelta(55.0, (float) $res->json('data.lines.0.unit_cost'), 0.01);
    }

    public function test_preview_matches_barcode_and_fills_cost_from_product(): void
    {
        $product = $this->makeProduct('PRD-IMP-02', 'كابل', null, null, 12.5, 'BC-IMP-02');

        $file = $this->makeImportXlsx([
            ['', 'كابل', '', 'BC-IMP-02', '3', '', ''],
        ]);

        $res = $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame($product->id, $res->json('data.lines.0.product_id'));
        $this->assertEqualsWithDelta(12.5, (float) $res->json('data.lines.0.unit_cost'), 0.01);
    }

    public function test_preview_matches_name_brand_model_then_unique_name_when_model_empty_in_file(): void
    {
        $p1 = $this->makeProduct('PRD-A', 'راوتر', 'تي بي لينك', 'C6', 20);
        $p2 = $this->makeProduct('PRD-B', 'راوتر', 'تيندا', 'AC8', 18);
        $unique = $this->makeProduct('PRD-C', 'ماوس', null, null, 5);

        // First row has model → matches by unique model (C6 is unique)
        // Second row has name only → unique name
        $file = $this->makeImportXlsx([
            ['C6', 'راوتر', 'تي بي لينك', '', '2', '21', ''],
            ['', 'ماوس', '', '', '4', '', ''],
        ]);

        $res = $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(2, $res->json('data.imported'));
        $this->assertSame($p1->id, $res->json('data.lines.0.product_id'));
        $this->assertSame('model', $res->json('data.lines.0.match_reason'));
        $this->assertSame($unique->id, $res->json('data.lines.1.product_id'));
        $this->assertSame('name', $res->json('data.lines.1.match_reason'));
        $this->assertSame($p2->id, Product::query()->where('sku', 'PRD-B')->value('id'));
    }

    public function test_preview_keeps_unmatched_and_ambiguous_rows(): void
    {
        $this->makeProduct('PRD-D1', 'لوحة', 'لوجيتك', 'K120', 10);
        $this->makeProduct('PRD-D2', 'لوحة', 'ديل', 'KB216', 9);

        $file = $this->makeImportXlsx([
            ['', 'لوحة', '', '', '1', '10', ''],
            ['', 'صنف غير موجود', '', '', '2', '3', 'ملاحظة'],
        ]);

        $res = $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(2, $res->json('data.imported'));
        $this->assertSame(0, $res->json('data.matched'));
        $this->assertSame(2, $res->json('data.unmatched'));
        $this->assertNull($res->json('data.lines.0.product_id'));
        $this->assertFalse($res->json('data.lines.0.matched'));
        $this->assertSame('ambiguous', $res->json('data.lines.0.match_reason'));
        $this->assertNotNull($res->json('data.lines.0.warning'));
        $this->assertNull($res->json('data.lines.1.product_id'));
        $this->assertSame('صنف غير موجود', $res->json('data.lines.1.product_name'));
        $this->assertSame('ملاحظة', $res->json('data.lines.1.notes'));
        $this->assertSame(0, PurchaseInvoice::query()->count());
    }

    public function test_preview_skips_example_row_and_accepts_english_headers(): void
    {
        $product = $this->makeProduct('PRD-EN', 'Keyboard', 'Logitech', 'MX', 30);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['model', 'product_name', 'brand', 'product_code', 'quantity', 'unit_cost', 'notes'], null, 'A1');
        $sheet->fromArray(['S24', '', '', '', '10', '', 'مثال — يكفي الموديل؛ احذف هذا الصف'], null, 'A2');
        $sheet->fromArray(['MX', 'Keyboard', 'Logitech', 'PRD-EN', '6', '33', ''], null, 'A3');
        $path = tempnam(sys_get_temp_dir(), 'en-imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'lines.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $res = $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(1, $res->json('data.imported'));
        $this->assertGreaterThanOrEqual(1, $res->json('data.skipped'));
        $this->assertSame($product->id, $res->json('data.lines.0.product_id'));
        $this->assertSame('model', $res->json('data.lines.0.match_reason'));
        @unlink($path);
    }

    public function test_preview_reports_invalid_headers_and_missing_quantity(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['foo', 'bar'], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'bad-imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'bad.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.imported', 0);

        @unlink($path);

        $file2 = $this->makeImportXlsx([
            ['', 'اسم', '', 'PRD-X', '', '10', ''],
        ]);
        $res = $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file2], [
            'Accept' => 'application/json',
        ]);
        $this->assertSame(0, $res->json('data.imported'));
        $this->assertNotEmpty($res->json('data.errors'));
    }

    public function test_legacy_column_order_still_works_via_header_names(): void
    {
        $product = $this->makeProduct('LEG-01', 'طابعة', 'إتش بي', 'LaserJet', 200);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('البنود');
        // Old template column order: code, name, brand, model, qty, cost, notes
        $sheet->fromArray(['رمز الصنف', 'اسم الصنف', 'الماركة', 'الموديل', 'الكمية', 'التكلفة', 'ملاحظات البند'], null, 'A1');
        $sheet->fromArray(['', '', '', 'LaserJet', '1', '', ''], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'leg-imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'legacy.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $res = $this->post('/api/imports/purchase-invoices/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame($product->id, $res->json('data.lines.0.product_id'));
        $this->assertSame('model', $res->json('data.lines.0.match_reason'));
        @unlink($path);
    }

    public function test_guest_without_permission_cannot_preview(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        $this->post('/api/imports/purchase-invoices/lines/preview', [
            'file' => $this->makeImportXlsx([['x', 'y', '', '', '1', '1', '']]),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    protected function makeProduct(
        string $sku,
        string $name,
        ?string $brand,
        ?string $model,
        float $cost,
        ?string $barcode = null,
    ): Product {
        return Product::query()->create([
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $name,
            'brand' => $brand,
            'model' => $model,
            'cost_price' => $cost,
            'sale_price' => $cost + 5,
            'is_active' => true,
        ]);
    }

    /**
     * Row order matches HEADERS: model, name, brand, code, qty, cost, notes
     *
     * @param  list<list<string>>  $rows
     */
    protected function makeImportXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('البنود');
        foreach (PurchaseInvoiceLineImportService::HEADERS as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $h);
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $i => $v) {
                $sheet->setCellValue(
                    Coordinate::stringFromColumnIndex($i + 1).($r + 2),
                    $v
                );
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'pi-imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'purchase-invoice-lines.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
