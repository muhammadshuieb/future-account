<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SalesLineImportTest extends TestCase
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

    public function test_template_has_sale_price_header(): void
    {
        $response = $this->get('/api/imports/sales-orders/lines/template');
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'so-tpl');
        file_put_contents($tmp, $response->streamedContent());
        $book = IOFactory::load($tmp);
        $sheet = $book->getSheetByName('البنود');
        $this->assertNotNull($sheet);
        $this->assertSame('الموديل', $sheet->getCell('A1')->getValue());
        $this->assertSame('سعر البيع', $sheet->getCell('F1')->getValue());
        unlink($tmp);
    }

    public function test_preview_matches_model_and_fills_sale_price(): void
    {
        $product = Product::query()->create([
            'sku' => 'PRD-SO-01',
            'name' => 'شاشة',
            'brand' => 'سامسونج',
            'model' => 'Galaxy-S24',
            'cost_price' => 40,
            'sale_price' => 55,
            'is_active' => true,
        ]);

        $file = $this->makeImportXlsx([
            ['Galaxy-S24', '', '', '', '3', '', ''],
        ]);

        $before = SalesOrder::query()->count();
        $res = $this->post('/api/imports/sales-orders/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(1, $res->json('data.imported'));
        $this->assertSame($product->id, $res->json('data.lines.0.product_id'));
        $this->assertSame(55.0, (float) $res->json('data.lines.0.unit_price'));
        $this->assertSame($before, SalesOrder::query()->count());
    }

    /**
     * @param  list<list<string>>  $rows
     */
    protected function makeImportXlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('البنود');
        $headers = ['الموديل', 'اسم الصنف', 'الماركة', 'رمز الصنف', 'الكمية', 'سعر البيع', 'ملاحظات البند'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $header);
        }
        foreach ($rows as $r => $cols) {
            foreach ($cols as $c => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1).($r + 2), $value);
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'so-xlsx').'.xlsx';
        (new Xlsx($book))->save($tmp);

        return new UploadedFile($tmp, 'sales-lines.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
