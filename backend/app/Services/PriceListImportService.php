<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Services\Excel\ExcelWorkbook;
use App\Support\HikvisionProductUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PriceListImportService
{
    /** Official template headers (Arabic). */
    public const HEADERS = [
        'الفئة',
        'الرقم',
        'رقم الموديل',
        'MP',
        'المواصفات',
        'العدسة',
        'رابط المنتج',
        'جملة الجملة',
        'المفرق',
        'الجملة',
        'عنوان فئة',
    ];

    public function downloadTemplate(PriceList $priceList): StreamedResponse
    {
        $book = $this->buildTemplateWorkbook($priceList);
        $safeName = preg_replace('/[\\\\\/\:\*\?\"\<\>\|]+/u', '-', $priceList->name) ?: 'price-list';

        return response()->streamDownload(function () use ($book) {
            $tmp = $book->toTempStream();
            fpassthru($tmp);
            fclose($tmp);
        }, 'قالب-قائمة-أسعار-'.$safeName.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function buildTemplateWorkbook(PriceList $priceList): ExcelWorkbook
    {
        $book = new ExcelWorkbook('قالب قائمة الأسعار');
        $book->addSheet('البنود', self::HEADERS, [
            [
                'كاميرات IP',
                '1',
                'DS-2CD2043G2-I',
                '4',
                '4MP Fixed Bullet Network Camera',
                '2.8mm',
                '',
                '45',
                '65',
                '55',
                'لا',
            ],
            [
                'كاميرات IP',
                '',
                'كاميرات IP',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                'نعم',
            ],
        ]);
        $book->addSheet('تعليمات', ['البند', 'الشرح'], [
            ['الفئة', 'اختياري — اسم الفئة (يُستخدم للتجميع والتنقل)'],
            ['الرقم', 'اختياري — رقم الصف داخل الفئة'],
            ['رقم الموديل', 'مطلوب للبنود العادية — مفتاح المطابقة عند التحديث'],
            ['MP', 'اختياري'],
            ['المواصفات', 'اختياري'],
            ['العدسة', 'اختياري'],
            ['رابط المنتج', 'اختياري — إن فرغ يُنشأ تلقائياً من Hikvision حسب الموديل'],
            ['جملة الجملة', 'اختياري — رقم'],
            ['المفرق', 'اختياري — رقم'],
            ['الجملة', 'اختياري — رقم'],
            ['عنوان فئة', 'نعم/لا — صف عنوان فئة بدون أسعار'],
            ['الاستبدال', 'عند الاستيراد مع خيار الاستبدال تُحذف كل البنود الحالية ثم تُضاف صفوف الملف'],
            ['القائمة', 'يُستورد إلى: '.$priceList->name],
        ]);

        return $book;
    }

    /**
     * @return array{
     *   imported: int,
     *   updated: int,
     *   created: int,
     *   failed: int,
     *   total_rows: int,
     *   replaced: bool,
     *   errors: list<array{row:int,message:string}>
     * }
     */
    public function import(UploadedFile $file, PriceList $priceList, bool $replace = false): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheetByName('البنود') ?? $spreadsheet->getActiveSheet();

        $highestRow = (int) $sheet->getHighestDataRow();
        $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $highestCol = max($highestColIndex, count(self::HEADERS));

        $headerMap = $this->mapHeaders($sheet, $highestCol);
        if (! isset($headerMap['رقم الموديل'])) {
            return [
                'imported' => 0,
                'updated' => 0,
                'created' => 0,
                'failed' => 0,
                'total_rows' => 0,
                'replaced' => false,
                'errors' => [[
                    'row' => 1,
                    'message' => 'رؤوس الأعمدة غير صحيحة. حمّل القالب الرسمي وأعد تعبئته (يلزم عمود رقم الموديل).',
                ]],
            ];
        }

        $imported = 0;
        $updated = 0;
        $created = 0;
        $failed = 0;
        $errors = [];
        $totalRows = 0;

        DB::transaction(function () use (
            $sheet,
            $headerMap,
            $highestRow,
            $priceList,
            $replace,
            &$imported,
            &$updated,
            &$created,
            &$failed,
            &$errors,
            &$totalRows,
        ) {
            if ($replace) {
                PriceListItem::query()->where('price_list_id', $priceList->id)->delete();
            }

            $maxSort = (int) PriceListItem::query()->where('price_list_id', $priceList->id)->max('sort_order');

            for ($row = 2; $row <= $highestRow; $row++) {
                $raw = [];
                foreach (self::HEADERS as $header) {
                    $col = $headerMap[$header] ?? null;
                    $raw[$header] = $col
                        ? $this->cellString($sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getValue())
                        : '';
                }

                if ($this->rowIsEmpty($raw)) {
                    continue;
                }

                $totalRows++;

                try {
                    $isHeader = $this->parseYesNo($raw['عنوان فئة']);
                    $model = trim($raw['رقم الموديل']);
                    $category = trim($raw['الفئة']);

                    if (! $isHeader && $model === '') {
                        throw new \InvalidArgumentException('رقم الموديل مطلوب للبنود العادية.');
                    }

                    if ($isHeader && $model === '' && $category === '') {
                        throw new \InvalidArgumentException('عنوان الفئة يحتاج اسم فئة أو رقم موديل.');
                    }

                    if ($isHeader && $model === '') {
                        $model = $category;
                    }

                    $payload = [
                        'category' => $category !== '' ? $category : null,
                        'row_no' => trim($raw['الرقم']) !== '' ? trim($raw['الرقم']) : null,
                        'model_no' => $model !== '' ? $model : null,
                        'mp' => trim($raw['MP']) !== '' ? trim($raw['MP']) : null,
                        'specs' => trim($raw['المواصفات']) !== '' ? trim($raw['المواصفات']) : null,
                        'lens' => trim($raw['العدسة']) !== '' ? trim($raw['العدسة']) : null,
                        'product_url' => $this->resolveProductUrl(trim($raw['رابط المنتج']), $model),
                        'price_super_wholesale' => $isHeader ? null : $this->parseNullableNumber($raw['جملة الجملة'], 'جملة الجملة'),
                        'price_retail' => $isHeader ? null : $this->parseNullableNumber($raw['المفرق'], 'المفرق'),
                        'price_wholesale' => $isHeader ? null : $this->parseNullableNumber($raw['الجملة'], 'الجملة'),
                        'is_category_header' => $isHeader,
                        'is_active' => true,
                    ];

                    $existing = null;
                    if (! $replace) {
                        $existing = $this->findExistingItem($priceList->id, $payload, $isHeader);
                    }

                    if ($existing) {
                        $existing->fill($payload)->save();
                        $updated++;
                    } else {
                        $maxSort++;
                        PriceListItem::query()->create(array_merge($payload, [
                            'price_list_id' => $priceList->id,
                            'sort_order' => $maxSort,
                        ]));
                        $created++;
                    }
                    $imported++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'row' => $row,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        });

        return [
            'imported' => $imported,
            'updated' => $updated,
            'created' => $created,
            'failed' => $failed,
            'total_rows' => $totalRows,
            'replaced' => $replace,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function findExistingItem(int $priceListId, array $payload, bool $isHeader): ?PriceListItem
    {
        $query = PriceListItem::query()
            ->where('price_list_id', $priceListId)
            ->where('is_category_header', $isHeader);

        if ($isHeader) {
            $category = $payload['category'] ?? $payload['model_no'] ?? null;
            if ($category === null || $category === '') {
                return null;
            }

            return $query
                ->where(function ($w) use ($category) {
                    $w->where('category', $category)->orWhere('model_no', $category);
                })
                ->orderBy('id')
                ->first();
        }

        $model = $payload['model_no'] ?? null;
        if ($model === null || $model === '') {
            return null;
        }

        $query->where('model_no', $model);
        if (! empty($payload['category'])) {
            $query->where('category', $payload['category']);
        }

        return $query->orderBy('id')->first();
    }

    protected function resolveProductUrl(string $url, string $model): ?string
    {
        if ($url !== '') {
            return $url;
        }

        return HikvisionProductUrl::forModel($model !== '' ? $model : null);
    }

    /**
     * @return array<string, int> header => 1-based column index
     */
    protected function mapHeaders($sheet, int $highestCol): array
    {
        $map = [];
        $aliases = [
            'الفئة' => ['الفئة', 'فئة', 'التصنيف', 'category'],
            'الرقم' => ['الرقم', '#', 'رقم', 'row', 'row_no'],
            'رقم الموديل' => ['رقم الموديل', 'الموديل', 'موديل', 'model', 'model_no'],
            'MP' => ['mp', 'MP', 'ميجابكسل'],
            'المواصفات' => ['المواصفات', 'مواصفات', 'البيان', 'specs'],
            'العدسة' => ['العدسة', 'عدسة', 'lens'],
            'رابط المنتج' => ['رابط المنتج', 'الرابط', 'url', 'product_url', 'link'],
            'جملة الجملة' => ['جملة الجملة', 'سعر جملة الجملة', 'super_wholesale', 'super wholesale'],
            'المفرق' => ['المفرق', 'سعر المفرق', 'retail'],
            'الجملة' => ['الجملة', 'سعر الجملة', 'wholesale'],
            'عنوان فئة' => ['عنوان فئة', 'عنوان الفئة', 'header', 'is_category_header'],
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

    protected function parseYesNo(string $raw): bool
    {
        $v = mb_strtolower(trim($raw));
        if ($v === '') {
            return false;
        }

        return in_array($v, ['1', 'نعم', 'yes', 'y', 'true', 'header', 'عنوان'], true);
    }

    protected function parseNullableNumber(string $raw, string $label): ?float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $normalized = str_replace([',', ' '], ['', ''], $this->latinDigits($raw));
        if (! is_numeric($normalized)) {
            throw new \InvalidArgumentException("{$label} غير صالح: {$raw}");
        }

        return (float) $normalized;
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
