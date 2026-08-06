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
        'الماركة',
        'الموديل',
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
                'سامسونج',
                'A54',
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
            ['التصنيف', 'اختياري — إن لم يوجد يُنشأ تلقائياً بالاسم المكتوب'],
            ['الوحدة', 'اختياري — إن فرغت تُستخدم «قطعة»؛ وإن لم توجد تُنشأ تلقائياً'],
            ['الباركود', 'اختياري — يجب أن يكون فريداً إن وُجد'],
            ['الماركة', 'اختياري — اسم الماركة / العلامة التجارية'],
            ['الموديل', 'اختياري — رقم أو اسم الموديل'],
            ['سعر التكلفة', 'اختياري — افتراضي 0'],
            ['سعر البيع', 'اختياري — افتراضي 0'],
            ['المخزن', 'مطلوب — اسم أو رمز مخزن موجود. إن لم يكن لديك أي مخزن يُنشأ «المخزن الرئيسي» تلقائياً'],
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
            // After a wipe there may be zero warehouses — create a practical default once.
            if ($warehouses->isEmpty()) {
                $warehouse = $this->ensureDefaultWarehouse($warehouseKey);
                $warehouses->push($warehouse);
            } else {
                $names = $warehouses->pluck('name')->implode('، ');
                throw new \InvalidArgumentException(
                    "المخزن غير موجود: {$warehouseKey}. المخازن المتاحة: {$names}"
                );
            }
        }

        $categoryId = null;
        $categoryKey = trim($raw['التصنيف']);
        if ($categoryKey !== '') {
            $category = $categories->first(
                fn (Category $c) => mb_strtolower((string) $c->name) === mb_strtolower($categoryKey)
            );
            if (! $category) {
                $category = Category::query()->create(['name' => $categoryKey]);
                $categories->push($category);
            }
            $categoryId = $category->id;
        }

        $unitKey = trim($raw['الوحدة']);
        if ($unitKey === '') {
            $unitKey = 'قطعة';
        }
        $unit = $units->first(function (Unit $u) use ($unitKey) {
            return mb_strtolower((string) $u->name) === mb_strtolower($unitKey)
                || mb_strtolower((string) ($u->symbol ?? '')) === mb_strtolower($unitKey);
        });
        if (! $unit) {
            $unit = Unit::query()->create([
                'name' => $unitKey,
                'symbol' => mb_strlen($unitKey) <= 16 ? $unitKey : null,
            ]);
            $units->push($unit);
        }
        $unitId = $unit->id;

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
        $brand = trim($raw['الماركة']);
        if ($brand === '') {
            $brand = null;
        }
        $model = trim($raw['الموديل']);
        if ($model === '') {
            $model = null;
        }
        $notes = trim($raw['ملاحظات']);
        if ($notes === '') {
            $notes = null;
        }

        return DB::transaction(function () use (
            $name, $barcode, $brand, $model, $categoryId, $unitId, $costPrice, $salePrice,
            $reorderLevel, $notes, $warehouse, $openingQty, $user
        ) {
            $sku = $this->nextSku();
            $product = Product::query()->create([
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => $name,
                'brand' => $brand,
                'model' => $model,
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
     * When the company has no warehouses yet (e.g. after a wipe), create one so import can proceed.
     * Uses the name from the spreadsheet when provided; falls back to «المخزن الرئيسي».
     */
    protected function ensureDefaultWarehouse(string $requestedName): Warehouse
    {
        $name = trim($requestedName) !== '' ? trim($requestedName) : 'المخزن الرئيسي';
        $baseCode = 'WH-01';
        $code = $baseCode;
        $n = 1;
        while (Warehouse::query()->where('code', $code)->exists()) {
            $n++;
            $code = 'WH-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        }

        $branchId = \App\Models\Branch::query()->where('is_main', true)->value('id')
            ?? \App\Models\Branch::query()->value('id');

        return Warehouse::query()->create([
            'code' => $code,
            'name' => $name,
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
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
            'الماركة' => ['الماركة', 'ماركة', 'العلامة التجارية', 'brand'],
            'الموديل' => ['الموديل', 'موديل', 'model'],
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
