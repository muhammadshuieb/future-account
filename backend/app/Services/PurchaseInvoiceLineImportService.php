<?php

namespace App\Services;

use App\Models\Product;
use App\Services\Excel\ExcelWorkbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseInvoiceLineImportService
{
    public const HEADERS = [
        'رمز الصنف',
        'اسم الصنف',
        'الماركة',
        'الموديل',
        'الكمية',
        'التكلفة',
        'ملاحظات البند',
    ];

    public const EXAMPLE_CODE = 'EXAMPLE';

    public function downloadTemplate(): StreamedResponse
    {
        $book = $this->buildTemplateWorkbook();

        return response()->streamDownload(function () use ($book) {
            $tmp = $book->toTempStream();
            fpassthru($tmp);
            fclose($tmp);
        }, 'purchase-invoice-lines-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function buildTemplateWorkbook(): ExcelWorkbook
    {
        $book = new ExcelWorkbook('قالب بنود فاتورة شراء');
        $book->addSheet('البنود', self::HEADERS, [
            [
                self::EXAMPLE_CODE,
                'شاشة',
                'سامسونج',
                'S24',
                '10',
                '50',
                'مثال — احذف هذا الصف',
            ],
        ]);
        $book->addSheet('تعليمات', ['البند', 'الشرح'], [
            ['رمز الصنف', 'اختياري لكن الأفضل للمطابقة — رقم الصنف (SKU) أو الباركود كما في الدليل'],
            ['اسم الصنف', 'مطلوب إن لم يُذكر الرمز — يُطابق اسم الصنف في الدليل'],
            ['الماركة', 'اختياري — يحسّن المطابقة عند تكرار الاسم'],
            ['الموديل', 'اختياري — يحسّن المطابقة عند تكرار الاسم'],
            ['الكمية', 'مطلوب — أكبر من صفر'],
            ['التكلفة', 'اختياري — إن فرغت تُستخدم تكلفة الصنف في الدليل بعد المطابقة'],
            ['ملاحظات البند', 'اختياري — تظهر للمراجعة في النموذج ولا تُحفظ مع الفاتورة تلقائياً'],
            ['المطابقة', '1) الرمز  2) الاسم + الماركة + الموديل  3) الاسم إن كان وحيداً'],
            ['الحفظ', 'الاستيراد يعبّئ البنود فقط — راجع ثم اضغط حفظ في الفاتورة'],
            ['صف المثال', 'احذف صف EXAMPLE أو استبدله ببيانات حقيقية'],
        ]);

        return $book;
    }

    /**
     * Parse an Excel file into invoice line drafts. Does not create an invoice.
     *
     * @return array{
     *   imported: int,
     *   matched: int,
     *   unmatched: int,
     *   skipped: int,
     *   lines: list<array{
     *     row: int,
     *     product_id: int|null,
     *     product_code: string,
     *     product_name: string,
     *     brand: string|null,
     *     model: string|null,
     *     sku: string|null,
     *     quantity: float,
     *     unit_cost: float|null,
     *     notes: string,
     *     matched: bool,
     *     match_reason: string|null,
     *     warning: string|null
     *   }>,
     *   errors: list<array{row: int, message: string}>
     * }
     */
    public function preview(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheetByName('البنود') ?? $spreadsheet->getActiveSheet();

        $highestRow = (int) $sheet->getHighestDataRow();
        $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $highestCol = max($highestColIndex, count(self::HEADERS));

        $headerMap = $this->mapHeaders($sheet, $highestCol);
        if (! isset($headerMap['الكمية']) || (! isset($headerMap['رمز الصنف']) && ! isset($headerMap['اسم الصنف']))) {
            return [
                'imported' => 0,
                'matched' => 0,
                'unmatched' => 0,
                'skipped' => 0,
                'lines' => [],
                'errors' => [[
                    'row' => 1,
                    'message' => 'رؤوس الأعمدة غير صحيحة. حمّل القالب الرسمي وأعد تعبئته (يلزم عمود الكمية وعمود رمز الصنف أو اسم الصنف).',
                ]],
            ];
        }

        $products = Product::query()
            ->get(['id', 'sku', 'barcode', 'name', 'brand', 'model', 'cost_price', 'is_active']);

        $lines = [];
        $errors = [];
        $skipped = 0;
        $matched = 0;
        $unmatched = 0;

        for ($row = 2; $row <= $highestRow; $row++) {
            $raw = [];
            foreach (self::HEADERS as $header) {
                $col = $headerMap[$header] ?? null;
                $raw[$header] = $col !== null
                    ? $this->cellString($sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getCalculatedValue())
                    : '';
            }

            if ($this->rowIsEmpty($raw) || $this->isExampleRow($raw)) {
                $skipped++;

                continue;
            }

            try {
                $parsed = $this->parseRow($raw, $products, $row);
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['row' => $row, 'message' => $e->getMessage()];

                continue;
            }

            if ($parsed['matched']) {
                $matched++;
            } else {
                $unmatched++;
            }
            $lines[] = $parsed;
        }

        return [
            'imported' => count($lines),
            'matched' => $matched,
            'unmatched' => $unmatched,
            'skipped' => $skipped,
            'lines' => $lines,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $raw
     * @param  Collection<int, Product>  $products
     * @return array{
     *   row: int,
     *   product_id: int|null,
     *   product_code: string,
     *   product_name: string,
     *   brand: string|null,
     *   model: string|null,
     *   sku: string|null,
     *   quantity: float,
     *   unit_cost: float|null,
     *   notes: string,
     *   matched: bool,
     *   match_reason: string|null,
     *   warning: string|null
     * }
     */
    protected function parseRow(array $raw, $products, int $row): array
    {
        $code = trim($raw['رمز الصنف']);
        $name = trim($raw['اسم الصنف']);
        $brand = trim($raw['الماركة']);
        $model = trim($raw['الموديل']);
        $notes = trim($raw['ملاحظات البند']);

        if ($code === '' && $name === '') {
            throw new \InvalidArgumentException('أدخل رمز الصنف أو اسم الصنف.');
        }

        $quantity = $this->parseNumber($raw['الكمية'], 'الكمية', required: true);
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('الكمية يجب أن تكون أكبر من صفر.');
        }

        $costRaw = trim($raw['التكلفة']);
        $unitCost = $costRaw === '' ? null : $this->parseNumber($costRaw, 'التكلفة');

        $match = $this->matchProduct($products, $code, $name, $brand, $model);
        $product = $match['product'];

        if ($product && $unitCost === null) {
            $unitCost = round((float) $product->cost_price, 2);
        }

        $matched = $product !== null;
        $label = trim(implode(' / ', array_filter([$code, $name, $brand, $model], fn ($v) => $v !== '')));

        return [
            'row' => $row,
            'product_id' => $product?->id,
            'product_code' => $code !== '' ? $code : (string) ($product?->sku ?? ''),
            'product_name' => $name !== '' ? $name : (string) ($product?->name ?? ''),
            'brand' => $brand !== '' ? $brand : ($product?->brand ?: null),
            'model' => $model !== '' ? $model : ($product?->model ?: null),
            'sku' => $product?->sku,
            'quantity' => round($quantity, 3),
            'unit_cost' => $unitCost,
            'notes' => $notes,
            'matched' => $matched,
            'match_reason' => $match['reason'],
            'warning' => $matched ? null : $this->unmatchedWarning($label, $match['reason']),
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{product: Product|null, reason: string|null}
     */
    protected function matchProduct($products, string $code, string $name, string $brand, string $model): array
    {
        $norm = fn (?string $value) => mb_strtolower(trim((string) $value));

        if ($code !== '') {
            $needle = $norm($code);
            $byCode = $products->filter(function (Product $p) use ($needle, $norm) {
                return $norm($p->sku) === $needle
                    || ($p->barcode && $norm($p->barcode) === $needle);
            });
            if ($byCode->count() === 1) {
                return ['product' => $byCode->first(), 'reason' => 'code'];
            }
            if ($byCode->count() > 1) {
                return ['product' => null, 'reason' => 'ambiguous_code'];
            }
        }

        if ($name === '') {
            return ['product' => null, 'reason' => $code !== '' ? 'code_not_found' : 'no_name'];
        }

        $byName = $products->filter(fn (Product $p) => $norm($p->name) === $norm($name));

        if ($brand !== '' || $model !== '') {
            $narrowed = $byName->filter(function (Product $p) use ($brand, $model, $norm) {
                if ($brand !== '' && $norm($p->brand) !== $norm($brand)) {
                    return false;
                }
                if ($model !== '' && $norm($p->model) !== $norm($model)) {
                    return false;
                }

                return true;
            });
            if ($narrowed->count() === 1) {
                return ['product' => $narrowed->first(), 'reason' => 'name_brand_model'];
            }
            if ($narrowed->count() > 1) {
                return ['product' => null, 'reason' => 'ambiguous'];
            }
        }

        if ($byName->count() === 1) {
            return ['product' => $byName->first(), 'reason' => 'name'];
        }
        if ($byName->count() > 1) {
            return ['product' => null, 'reason' => 'ambiguous'];
        }

        return ['product' => null, 'reason' => $code !== '' ? 'code_not_found' : 'not_found'];
    }

    protected function unmatchedWarning(string $label, ?string $reason): string
    {
        $who = $label !== '' ? $label : 'بدون اسم';

        return match ($reason) {
            'ambiguous', 'ambiguous_code' => "عدة أصناف مطابقة لـ «{$who}» — اختر الصنف يدوياً.",
            default => "لم يُعثر على الصنف «{$who}» في الدليل — اختر الصنف يدوياً.",
        };
    }

    /** @param  array<string, string>  $raw */
    protected function isExampleRow(array $raw): bool
    {
        $code = mb_strtolower(trim($raw['رمز الصنف']));
        if ($code === mb_strtolower(self::EXAMPLE_CODE) || $code === 'مثال') {
            return true;
        }
        $notes = trim($raw['ملاحظات البند']);

        return str_contains($notes, 'مثال') && str_contains($notes, 'احذف');
    }

    /**
     * @return array<string, int> header => 1-based column index
     */
    protected function mapHeaders($sheet, int $highestCol): array
    {
        $map = [];
        $aliases = [
            'رمز الصنف' => ['رمز الصنف', 'رقم الصنف', 'الكود', 'sku', 'barcode', 'product_code', 'code'],
            'اسم الصنف' => ['اسم الصنف', 'الاسم', 'اسم', 'الصنف', 'product_name', 'name'],
            'الماركة' => ['الماركة', 'ماركة', 'العلامة التجارية', 'brand'],
            'الموديل' => ['الموديل', 'موديل', 'model'],
            'الكمية' => ['الكمية', 'كمية', 'quantity', 'qty'],
            'التكلفة' => ['التكلفة', 'تكلفة', 'سعر التكلفة', 'unit_cost', 'cost', 'cost_price'],
            'ملاحظات البند' => ['ملاحظات البند', 'ملاحظات', 'ملاحظة', 'notes', 'line_notes'],
        ];

        for ($col = 1; $col <= $highestCol + 5; $col++) {
            $value = $this->cellString($sheet->getCell(Coordinate::stringFromColumnIndex($col).'1')->getValue());
            if ($value === '') {
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
            if (is_float($value) && floor($value) == $value) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.') ?: '0';
        }

        return $this->latinDigits(trim((string) $value));
    }

    protected function parseNumber(string $raw, string $label, bool $required = false): float
    {
        $raw = trim($raw);
        if ($raw === '') {
            if ($required) {
                throw new \InvalidArgumentException("{$label} مطلوبة.");
            }

            return 0.0;
        }
        $normalized = str_replace([',', ' ', '٬'], ['', '', ''], $raw);
        if (! is_numeric($normalized)) {
            throw new \InvalidArgumentException("{$label} غير صالحة: {$raw}");
        }
        $n = (float) $normalized;
        if ($n < 0) {
            throw new \InvalidArgumentException("{$label} لا يمكن أن تكون سالبة.");
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
