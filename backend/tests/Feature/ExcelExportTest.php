<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\ExcelExportService;
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
