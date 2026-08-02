<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Excel\ExcelWorkbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportService
{
    /** Template / import column headers (Arabic). SKU is never required from the user. */
    public const HEADERS = [
        'اسم الصنف',
        'التصنيف',
        'الوحدة',
        'الباركود',
        'سعر التكلفة',
        'سعر البيع',
        'المخزن',
        'كمية افتتاحية',
        'حد إعادة الطلب',
        'ملاحظات',
    ];

    public function __construct(protected InventoryService $inventory) {}

    public function downloadTemplate(): StreamedResponse
    {
        $book = $this->buildTemplateWorkbook();

        return response()->streamDownload(function () use ($book) {
            $tmp = $book->toTempStream();
            fpassthru($tmp);
            fclose($tmp);
        }, 'قالب-استيراد-الأصناف.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function buildTemplateWorkbook(): ExcelWorkbook
    {
        $book = new ExcelWorkbook('قالب استيراد الأصناف');
        $book->addSheet('الأصناف', self::HEADERS, [
            // Example row (user may clear / replace)
            [
                'صنف تجريبي',
                'عام',
                'قطعة',
                '',
                '10',
                '15',
                'المخزن الرئيسي',
                '0',
                '5',
                '',
            ],
        ]);
        $book->addSheet('تعليمات', ['البند', 'الشرح'], [
            ['اسم الصنف', 'مطلوب'],
            ['التصنيف', 'اختياري — اسم التصنيف الموجود في النظام'],
            ['الوحدة', 'اختياري — اسم وحدة القياس الموجودة في النظام'],
            ['الباركود', 'اختياري — يجب أن يكون فريداً إن وُجد'],
            ['سعر التكلفة', 'اختياري — افتراضي 0'],
            ['سعر البيع', 'اختياري — افتراضي 0'],
            ['المخزن', 'مطلوب — اسم أو رمز المخزن لربط الصنف'],
            ['كمية افتتاحية', 'اختياري — افتراضي 0'],
            ['حد إعادة الطلب', 'اختياري — افتراضي 0'],
            ['ملاحظات', 'اختياري — تُحفظ في وصف الصنف'],
            ['رقم الصنف / SKU', 'لا تدخلوه — النظام يولّده تلقائياً'],
        ]);

        return $book;
    }

    /**
     * Write a reference template under storage/app/public/templates.
     */
    public function saveReferenceTemplate(?string $directory = null): string
    {
        $dir = $directory ?: storage_path('app/public/templates');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'products-import-template.xlsx';
        $this->buildTemplateWorkbook()->save($path);

        return $path;
    }

    /**
     * @return array{
     *   imported: int,
     *   failed: int,
     *   total_rows: int,
     *   products: list<array{id:int,sku:string,name:string}>,
     *   errors: list<array{row:int,message:string}>
     * }
     */
    public function import(UploadedFile $file, User $user): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheetByName('الأصناف') ?? $spreadsheet->getActiveSheet();

        $highestRow = (int) $sheet->getHighestDataRow();
        $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $highestCol = max($highestColIndex, count(self::HEADERS));

        $headerMap = $this->mapHeaders($sheet, $highestCol);
        if (! isset($headerMap['اسم الصنف']) || ! isset($headerMap['المخزن'])) {
            return [
                'imported' => 0,
                'failed' => 0,
                'total_rows' => 0,
                'products' => [],
                'errors' => [[
                    'row' => 1,
                    'message' => 'رؤوس الأعمدة غير صحيحة. حمّل القالب الرسمي وأعد تعبئته (يلزم عمودا اسم الصنف والمخزن).',
                ]],
            ];
        }

        $categories = Category::query()->get(['id', 'name']);
        $units = Unit::query()->get(['id', 'name', 'symbol']);
        $warehouses = Warehouse::query()->where('is_active', true)->get(['id', 'name', 'code']);

        $imported = 0;
        $failed = 0;
        $products = [];
        $errors = [];
        $seenBarcodes = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $raw = [];
            foreach (self::HEADERS as $header) {
                $col = $headerMap[$header] ?? null;
                $raw[$header] = $col !== null
                    ? $this->cellString($sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getCalculatedValue())
                    : '';
            }

            if ($this->rowIsEmpty($raw)) {
                continue;
            }

            try {
                $product = $this->importRow($raw, $user, $categories, $units, $warehouses, $seenBarcodes);
                $imported++;
                $products[] = [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                ];
                if ($product->barcode) {
                    $seenBarcodes[mb_strtolower($product->barcode)] = true;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'row' => $row,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'imported' => $imported,
            'failed' => $failed,
            'total_rows' => $imported + $failed,
            'products' => $products,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $raw
     * @param  \Illuminate\Support\Collection<int, Category>  $categories
     * @param  \Illuminate\Support\Collection<int, Unit>  $units
     * @param  \Illuminate\Support\Collection<int, Warehouse>  $warehouses
     * @param  array<string, true>  $seenBarcodes
     */
    protected function importRow(
        array $raw,
        User $user,
        $categories,
        $units,
        $warehouses,
        array $seenBarcodes,
    ): Product {
        $name = trim($raw['اسم الصنف']);
        if ($name === '') {
            throw new \InvalidArgumentException('اسم الصنف مطلوب.');
        }

        $warehouseKey = trim($raw['المخزن']);
        if ($warehouseKey === '') {
            throw new \InvalidArgumentException('المخزن مطلوب.');
        }

        $warehouse = $warehouses->first(function (Warehouse $w) use ($warehouseKey) {
            return mb_strtolower((string) $w->name) === mb_strtolower($warehouseKey)
                || mb_strtolower((string) $w->code) === mb_strtolower($warehouseKey);
        });
        if (! $warehouse) {
            throw new \InvalidArgumentException("المخزن غير موجود: {$warehouseKey}");
        }

        $categoryId = null;
        $categoryKey = trim($raw['التصنيف']);
        if ($categoryKey !== '') {
            $category = $categories->first(
                fn (Category $c) => mb_strtolower((string) $c->name) === mb_strtolower($categoryKey)
            );
            if (! $category) {
                throw new \InvalidArgumentException("التصنيف غير موجود: {$categoryKey}");
            }
            $categoryId = $category->id;
        }

        $unitId = null;
        $unitKey = trim($raw['الوحدة']);
        if ($unitKey !== '') {
            $unit = $units->first(function (Unit $u) use ($unitKey) {
                return mb_strtolower((string) $u->name) === mb_strtolower($unitKey)
                    || mb_strtolower((string) ($u->symbol ?? '')) === mb_strtolower($unitKey);
            });
            if (! $unit) {
                throw new \InvalidArgumentException("الوحدة غير موجودة: {$unitKey}");
            }
            $unitId = $unit->id;
        }

        $barcode = trim($raw['الباركود']);
        if ($barcode === '') {
            $barcode = null;
        } else {
            if (isset($seenBarcodes[mb_strtolower($barcode)])) {
                throw new \InvalidArgumentException("الباركود مكرر في الملف: {$barcode}");
            }
            if (Product::query()->where('barcode', $barcode)->exists()) {
                throw new \InvalidArgumentException("الباركود مستخدم مسبقاً: {$barcode}");
            }
        }

        $costPrice = $this->parseNumber($raw['سعر التكلفة'], 'سعر التكلفة');
        $salePrice = $this->parseNumber($raw['سعر البيع'], 'سعر البيع');
        $openingQty = $this->parseNumber($raw['كمية افتتاحية'], 'كمية افتتاحية');
        $reorderLevel = $this->parseNumber($raw['حد إعادة الطلب'], 'حد إعادة الطلب');
        $notes = trim($raw['ملاحظات']);
        if ($notes === '') {
            $notes = null;
        }

        return DB::transaction(function () use (
            $name, $barcode, $categoryId, $unitId, $costPrice, $salePrice,
            $reorderLevel, $notes, $warehouse, $openingQty, $user
        ) {
            $sku = $this->nextSku();
            $product = Product::query()->create([
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => $name,
                'description' => $notes,
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'cost_price' => $costPrice,
                'sale_price' => $salePrice,
                'reorder_level' => $reorderLevel,
                'track_batch' => false,
                'track_serial' => false,
                'is_active' => true,
            ]);

            $warehouseId = (int) $warehouse->id;
            $qty = round($openingQty, 3);

            if ($qty > 0) {
                $this->inventory->adjustStock(
                    $warehouseId,
                    $product->id,
                    $qty,
                    'in',
                    $user,
                    [
                        'unit_cost' => $product->cost_price,
                        'notes' => 'رصيد افتتاحي عند استيراد الصنف من Excel',
                    ]
                );
            } else {
                StockLevel::query()->firstOrCreate(
                    [
                        'warehouse_id' => $warehouseId,
                        'product_id' => $product->id,
                        'batch_no' => '',
                    ],
                    ['quantity' => 0]
                );
            }

            return $product;
        });
    }

    /**
     * Auto-generate next SKU: PRD-00001, PRD-00002, …
     * Ignores any user-supplied رقم الصنف column.
     */
    public function nextSku(): string
    {
        $prefix = 'PRD-';
        $last = Product::query()
            ->where('sku', 'like', $prefix.'%')
            ->orderByDesc('sku')
            ->lockForUpdate()
            ->value('sku');

        $seq = 1;
        if (is_string($last) && preg_match('/^PRD-(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        } else {
            // Fallback: highest numeric suffix among PRD-* skus that may not sort lexicographically for mixed padding
            $candidates = Product::query()
                ->where('sku', 'like', $prefix.'%')
                ->pluck('sku');
            foreach ($candidates as $sku) {
                if (preg_match('/^PRD-(\d+)$/', (string) $sku, $m)) {
                    $seq = max($seq, ((int) $m[1]) + 1);
                }
            }
        }

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, int> header => 1-based column index
     */
    protected function mapHeaders($sheet, int $highestCol): array
    {
        $map = [];
        $aliases = [
            'اسم الصنف' => ['اسم الصنف', 'الاسم', 'الصنف', 'name'],
            'التصنيف' => ['التصنيف', 'تصنيف', 'category'],
            'الوحدة' => ['الوحدة', 'وحدة', 'unit'],
            'الباركود' => ['الباركود', 'باركود', 'barcode'],
            'سعر التكلفة' => ['سعر التكلفة', 'التكلفة', 'تكلفة', 'cost'],
            'سعر البيع' => ['سعر البيع', 'البيع', 'بيع', 'sale'],
            'المخزن' => ['المخزن', 'مخزن', 'warehouse'],
            'كمية افتتاحية' => ['كمية افتتاحية', 'الكمية', 'كمية', 'opening'],
            'حد إعادة الطلب' => ['حد إعادة الطلب', 'حد الطلب', 'reorder'],
            'ملاحظات' => ['ملاحظات', 'وصف', 'notes', 'description'],
        ];

        for ($col = 1; $col <= $highestCol + 5; $col++) {
            $value = $this->cellString($sheet->getCell(Coordinate::stringFromColumnIndex($col).'1')->getValue());
            if ($value === '') {
                continue;
            }
            // Ignore SKU / رقم الصنف if present
            if (in_array(mb_strtolower($value), ['رقم الصنف', 'الكود', 'sku', 'code'], true)) {
                continue;
            }
            foreach ($aliases as $canonical => $names) {
                foreach ($names as $name) {
                    if (mb_strtolower($value) === mb_strtolower($name)) {
                        $map[$canonical] = $col;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /** @param  array<string, string>  $raw */
    protected function rowIsEmpty(array $raw): bool
    {
        foreach ($raw as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function cellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) || is_int($value)) {
            // Avoid scientific notation for barcodes typed as numbers
            if (is_float($value) && floor($value) == $value) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.') ?: '0';
        }

        $s = $this->latinDigits(trim((string) $value));

        return $s;
    }

    protected function parseNumber(string $raw, string $label): float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0.0;
        }
        $normalized = str_replace([',', ' '], ['', ''], $raw);
        if (! is_numeric($normalized)) {
            throw new \InvalidArgumentException("{$label} غير صالح: {$raw}");
        }
        $n = (float) $normalized;
        if ($n < 0) {
            throw new \InvalidArgumentException("{$label} لا يمكن أن يكون سالباً.");
        }

        return $n;
    }

    protected function latinDigits(string $value): string
    {
        $map = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];

        return strtr($value, $map);
    }
}
