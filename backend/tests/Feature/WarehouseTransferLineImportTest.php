<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseTransfer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class WarehouseTransferLineImportTest extends TestCase
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

    public function test_template_download_has_transfer_headers(): void
    {
        $response = $this->get('/api/imports/warehouse-transfers/lines/template');
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'tr-tpl');
        file_put_contents($tmp, $response->streamedContent());
        $book = IOFactory::load($tmp);
        $sheet = $book->getSheetByName('البنود');
        $this->assertNotNull($sheet);
        $this->assertSame('الموديل', $sheet->getCell('A1')->getValue());
        $this->assertSame('الكمية', $sheet->getCell('E1')->getValue());
        $this->assertSame('رقم الدفعة', $sheet->getCell('F1')->getValue());
        $this->assertSame('الرقم التسلسلي', $sheet->getCell('G1')->getValue());
        unlink($tmp);
    }

    public function test_preview_matches_model_and_does_not_create_transfer(): void
    {
        $product = Product::query()->create([
            'sku' => 'PRD-TR-01',
            'name' => 'كابل شبكة',
            'brand' => 'Generic',
            'model' => 'CAT6-1M',
            'cost_price' => 1,
            'sale_price' => 2,
            'is_active' => true,
        ]);

        $file = $this->makeImportXlsx([
            ['CAT6-1M', '', '', '', '25', 'B-1', '', ''],
        ]);

        $before = WarehouseTransfer::query()->count();
        $res = $this->post('/api/imports/warehouse-transfers/lines/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $res->assertOk();
        $this->assertSame(1, $res->json('data.imported'));
        $this->assertSame(1, $res->json('data.matched'));
        $this->assertSame($product->id, $res->json('data.lines.0.product_id'));
        $this->assertSame(25.0, (float) $res->json('data.lines.0.quantity'));
        $this->assertSame('B-1', $res->json('data.lines.0.batch_no'));
        $this->assertSame($before, WarehouseTransfer::query()->count());
    }

    /**
     * @param  list<list<string>>  $rows
     */
    protected function makeImportXlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('البنود');
        $headers = ['الموديل', 'اسم الصنف', 'الماركة', 'رمز الصنف', 'الكمية', 'رقم الدفعة', 'الرقم التسلسلي', 'ملاحظات البند'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $header);
        }
        foreach ($rows as $r => $cols) {
            foreach ($cols as $c => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1).($r + 2), $value);
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tr-xlsx').'.xlsx';
        (new Xlsx($book))->save($tmp);

        return new UploadedFile($tmp, 'transfer-lines.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
