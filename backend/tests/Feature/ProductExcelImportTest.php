<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductExcelImportTest extends TestCase
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

    public function test_template_download_has_arabic_headers_without_sku(): void
    {
        $response = $this->get('/api/imports/products/template');
        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type'),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($tmp, $response->streamedContent());
        $book = IOFactory::load($tmp);
        $sheet = $book->getSheetByName('الأصناف');
        $this->assertNotNull($sheet);
        $this->assertSame('اسم الصنف', $sheet->getCell('A1')->getValue());
        $this->assertSame('الماركة', $sheet->getCell('E1')->getValue());
        $this->assertSame('الموديل', $sheet->getCell('F1')->getValue());
        $this->assertSame('المخزن', $sheet->getCell('I1')->getValue());

        $headers = [];
        for ($c = 1; $c <= 14; $c++) {
            $headers[] = (string) $sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).'1')->getValue();
        }
        $this->assertContains('الماركة', $headers);
        $this->assertContains('الموديل', $headers);
        $this->assertNotContains('رقم الصنف', $headers);
        $this->assertNotContains('الكود', $headers);
        $this->assertNotContains('SKU', $headers);
        unlink($tmp);
    }

    public function test_import_creates_products_with_auto_sku_and_stock(): void
    {
        $wh = Warehouse::query()->create([
            'code' => 'WH-IMP',
            'name' => 'مخزن الاستيراد',
            'is_active' => true,
        ]);
        Category::query()->create(['name' => 'إلكترونيات']);
        Unit::query()->create(['name' => 'قطعة', 'symbol' => 'pcs']);

        Product::query()->create([
            'sku' => 'PRD-00001',
            'name' => 'موجود مسبقاً',
            'cost_price' => 1,
            'sale_price' => 2,
            'is_active' => true,
        ]);

        $file = $this->makeImportXlsx([
            ['شاشة', 'إلكترونيات', 'قطعة', 'BC-100', 'سامسونج', 'S24', '50', '80', 'مخزن الاستيراد', '10', '3', 'ملاحظة'],
            ['كابل', '', 'قطعة', '', '', '', '5', '12', 'WH-IMP', '0', '', ''],
        ]);

        $res = $this->post('/api/imports/products', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(2, $res->json('data.imported'));
        $this->assertSame(0, $res->json('data.failed'));

        $p1 = Product::query()->where('name', 'شاشة')->first();
        $this->assertNotNull($p1);
        $this->assertSame('PRD-00002', $p1->sku);
        $this->assertSame('BC-100', $p1->barcode);
        $this->assertSame('سامسونج', $p1->brand);
        $this->assertSame('S24', $p1->model);
        $this->assertSame(10.0, (float) StockLevel::query()
            ->where('product_id', $p1->id)
            ->where('warehouse_id', $wh->id)
            ->sum('quantity'));
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $p1->id,
            'warehouse_id' => $wh->id,
            'type' => 'in',
        ]);

        $p2 = Product::query()->where('name', 'كابل')->first();
        $this->assertNotNull($p2);
        $this->assertSame('PRD-00003', $p2->sku);
        $this->assertNull($p2->brand);
        $this->assertNull($p2->model);
        $this->assertSame(0.0, (float) StockLevel::query()
            ->where('product_id', $p2->id)
            ->where('warehouse_id', $wh->id)
            ->sum('quantity'));
        $this->assertSame(0, StockMovement::query()->where('product_id', $p2->id)->count());
    }

    public function test_import_reports_row_errors_and_continues(): void
    {
        Warehouse::query()->create([
            'code' => 'WH-OK',
            'name' => 'مخزن صالح',
            'is_active' => true,
        ]);

        $file = $this->makeImportXlsx([
            ['صالح', '', '', '', '', '', '1', '2', 'مخزن صالح', '0', '0', ''],
            ['بدون مخزن', '', '', '', '', '', '1', '2', '', '0', '0', ''],
            ['مخزن وهمي', '', '', '', '', '', '1', '2', 'غير موجود', '0', '0', ''],
        ]);

        $res = $this->post('/api/imports/products', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        // Partial success still 200 when imported > 0
        $res->assertOk();
        $this->assertSame(1, $res->json('data.imported'));
        $this->assertSame(2, $res->json('data.failed'));
        $this->assertCount(2, $res->json('data.errors'));
        $this->assertTrue(Product::query()->where('name', 'صالح')->exists());
        $this->assertFalse(Product::query()->where('name', 'بدون مخزن')->exists());
        // Empty unit defaults to قطعة and auto-creates it
        $this->assertTrue(Unit::query()->where('name', 'قطعة')->exists());
        $this->assertNotNull(Product::query()->where('name', 'صالح')->value('unit_id'));
    }

    public function test_import_auto_creates_missing_category_and_unit(): void
    {
        Warehouse::query()->create([
            'code' => 'WH-AC',
            'name' => 'المخزن الرئيسي',
            'is_active' => true,
        ]);

        $this->assertFalse(Category::query()->where('name', 'عام')->exists());
        $this->assertFalse(Unit::query()->where('name', 'قطعة')->exists());

        $file = $this->makeImportXlsx([
            ['منتج أ', 'عام', 'قطعة', '', '', '', '10', '15', 'المخزن الرئيسي', '0', '5', ''],
            ['منتج ب', 'عام', '', '', '', '', '5', '8', 'المخزن الرئيسي', '2', '1', ''],
            ['منتج ج', 'مواد خام', 'كيلو', '', '', '', '3', '6', 'المخزن الرئيسي', '0', '0', ''],
        ]);

        $res = $this->post('/api/imports/products', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(3, $res->json('data.imported'));
        $this->assertSame(0, $res->json('data.failed'));

        $this->assertTrue(Category::query()->where('name', 'عام')->exists());
        $this->assertTrue(Category::query()->where('name', 'مواد خام')->exists());
        $this->assertTrue(Unit::query()->where('name', 'قطعة')->exists());
        $this->assertTrue(Unit::query()->where('name', 'كيلو')->exists());

        $pA = Product::query()->where('name', 'منتج أ')->first();
        $this->assertNotNull($pA);
        $this->assertSame('PRD-00001', $pA->sku);
        $this->assertSame(Category::query()->where('name', 'عام')->value('id'), $pA->category_id);
        $this->assertSame(Unit::query()->where('name', 'قطعة')->value('id'), $pA->unit_id);

        $pB = Product::query()->where('name', 'منتج ب')->first();
        $this->assertNotNull($pB);
        $this->assertSame(Unit::query()->where('name', 'قطعة')->value('id'), $pB->unit_id);
        // Same category name reused — only one «عام» row
        $this->assertSame(1, Category::query()->where('name', 'عام')->count());
    }

    public function test_import_auto_creates_default_warehouse_when_none_exist(): void
    {
        $this->assertSame(0, Warehouse::query()->count());

        $file = $this->makeImportXlsx([
            ['صنف بعد المسح', 'عام', 'قطعة', '', '', '', '1', '2', 'المخزن الرئيسي', '0', '0', ''],
        ]);

        $res = $this->post('/api/imports/products', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(1, $res->json('data.imported'));
        $this->assertTrue(Warehouse::query()->where('name', 'المخزن الرئيسي')->exists());
        $this->assertTrue(Product::query()->where('name', 'صنف بعد المسح')->exists());
    }

    public function test_import_ignores_sku_column_if_present(): void
    {
        Warehouse::query()->create([
            'code' => 'WH-X',
            'name' => 'مخزن X',
            'is_active' => true,
        ]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('الأصناف');
        $headers = array_merge(['رقم الصنف'], ProductImportService::HEADERS);
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1).'1', $h);
        }
        $row = ['USER-SKU', 'صنف نظامي', '', '', '', '', '', '1', '2', 'مخزن X', '0', '0', ''];
        foreach ($row as $i => $v) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1).'2', $v);
        }
        $path = tempnam(sys_get_temp_dir(), 'ign').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $res = $this->post('/api/imports/products', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $product = Product::query()->where('name', 'صنف نظامي')->first();
        $this->assertNotNull($product);
        $this->assertSame('PRD-00001', $product->sku);
        $this->assertNotSame('USER-SKU', $product->sku);
        @unlink($path);
    }

    public function test_guest_cannot_import(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        // user without warehouse.manage and not admin
        $this->post('/api/imports/products', [
            'file' => $this->makeImportXlsx([['x', '', '', '', '', '', '', '', 'y', '', '', '']]),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_save_reference_template_writes_file(): void
    {
        $dir = storage_path('app/testing-templates');
        $path = app(ProductImportService::class)->saveReferenceTemplate($dir);
        $this->assertFileExists($path);
        $book = IOFactory::load($path);
        $this->assertNotNull($book->getSheetByName('الأصناف'));
        @unlink($path);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    protected function makeImportXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('الأصناف');
        foreach (ProductImportService::HEADERS as $i => $h) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1).'1', $h);
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $i => $v) {
                $sheet->setCellValue(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1).($r + 2),
                    $v
                );
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'products-import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
