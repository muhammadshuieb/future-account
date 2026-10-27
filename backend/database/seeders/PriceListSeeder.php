<?php

namespace Database\Seeders;

use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Database\Seeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PriceListSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/price-list.xlsx');
        if (! is_file($path)) {
            $this->command?->warn('Price list Excel not found: '.$path);

            return;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('قائمة المنتجات') ?? $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        $list = PriceList::query()->firstOrCreate(
            ['name' => 'قائمة أسعار Hikvision'],
            [
                'currency' => 'USD',
                'is_active' => true,
                'notes' => 'مستورد من ملف DOC-20261026-WA0182',
            ]
        );

        // Re-import replaces items so re-running stays idempotent.
        PriceListItem::query()->where('price_list_id', $list->id)->delete();

        $currentCategory = null;
        $prevRowNo = null;
        $sort = 0;
        $imported = 0;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
            $col0 = $this->cellStr($cells[0] ?? null);
            $col1 = $this->cellStr($cells[1] ?? null);
            $col2 = $this->cellStr($cells[2] ?? null);
            $col3 = $this->cellStr($cells[3] ?? null);
            $col4 = $this->cellStr($cells[4] ?? null);

            // Skip empty / title / header rows
            if ($col0 === '' && $col1 === '' && $col2 === '' && $col3 === '') {
                continue;
            }
            if ($this->isHeaderRow($cells)) {
                continue;
            }

            // Category banner: e.g. [null, "Analogue CAMERA", …] — Excel often puts 0 in price cells
            if ($this->isCategoryRow($cells)) {
                $currentCategory = $col0 !== '' ? $col0 : $col1;
                $sort++;
                PriceListItem::query()->create([
                    'price_list_id' => $list->id,
                    'sort_order' => $sort,
                    'category' => $currentCategory,
                    'model_no' => $currentCategory,
                    'is_category_header' => true,
                    'is_active' => true,
                ]);
                $imported++;
                $prevRowNo = null;

                continue;
            }

            // Product row: # | Model No. | MP | Specs | Lens | Super WS | Retail | Wholesale
            $rowNo = $col0;
            $model = $col1;
            if ($model === '' && $rowNo === '') {
                continue;
            }

            // Numbering restarted without a category banner → infer section name from model
            if ($rowNo !== '' && is_numeric($rowNo) && (int) $rowNo === 1 && $prevRowNo !== null && $prevRowNo > 1) {
                $inferred = $this->inferCategoryFromModel($model);
                if ($inferred !== null && $inferred !== $currentCategory) {
                    $currentCategory = $inferred;
                    $sort++;
                    PriceListItem::query()->create([
                        'price_list_id' => $list->id,
                        'sort_order' => $sort,
                        'category' => $currentCategory,
                        'model_no' => $currentCategory,
                        'is_category_header' => true,
                        'is_active' => true,
                    ]);
                    $imported++;
                }
            }

            if ($currentCategory === null) {
                $currentCategory = $this->inferCategoryFromModel($model) ?? 'عام';
                $sort++;
                PriceListItem::query()->create([
                    'price_list_id' => $list->id,
                    'sort_order' => $sort,
                    'category' => $currentCategory,
                    'model_no' => $currentCategory,
                    'is_category_header' => true,
                    'is_active' => true,
                ]);
                $imported++;
            }

            $sort++;
            PriceListItem::query()->create([
                'price_list_id' => $list->id,
                'sort_order' => $sort,
                'category' => $currentCategory,
                'row_no' => $rowNo !== '' ? $rowNo : null,
                'model_no' => $model !== '' ? $model : null,
                'mp' => $col2 !== '' ? $col2 : null,
                'specs' => $col3 !== '' ? $col3 : null,
                'lens' => $col4 !== '' ? $col4 : null,
                'price_super_wholesale' => $this->toMoney($cells[5] ?? null),
                'price_retail' => $this->toMoney($cells[6] ?? null),
                'price_wholesale' => $this->toMoney($cells[7] ?? null),
                'is_category_header' => false,
                'is_active' => true,
            ]);
            $imported++;
            if ($rowNo !== '' && is_numeric($rowNo)) {
                $prevRowNo = (int) $rowNo;
            }
        }

        $this->command?->info("Price list seeded: {$imported} rows into #{$list->id}");
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function isHeaderRow(array $cells): bool
    {
        $joined = mb_strtolower(implode(' ', array_map(fn ($c) => (string) ($c ?? ''), array_slice($cells, 0, 8))));

        return (str_contains($joined, 'رقم الموديل') || str_contains($joined, 'model'))
            && (str_contains($joined, 'سعر') || str_contains($joined, 'جملة') || str_contains($joined, 'mp'));
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function isCategoryRow(array $cells): bool
    {
        $col0 = $this->cellStr($cells[0] ?? null);
        $col1 = $this->cellStr($cells[1] ?? null);

        // Category banners often have literal 0 in price columns — treat 0 as empty.
        if ($this->hasRealPrice($cells[5] ?? null)
            || $this->hasRealPrice($cells[6] ?? null)
            || $this->hasRealPrice($cells[7] ?? null)) {
            return false;
        }

        // Numeric row number ⇒ product line
        if ($col0 !== '' && is_numeric($col0)) {
            return false;
        }

        // Typical sheet markers: [null, "Analogue CAMERA", null…] or ["Category", null…]
        $label = $col0 !== '' ? $col0 : $col1;
        if ($label === '') {
            return false;
        }

        $extraFilled = 0;
        foreach ([2, 3, 4] as $i) {
            if ($this->cellStr($cells[$i] ?? null) !== '') {
                $extraFilled++;
            }
        }

        return $extraFilled === 0;
    }

    private function cellStr(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) || is_int($v)) {
            // Avoid "1.0" for integer-looking floats used as row numbers
            if (is_float($v) && floor($v) == $v) {
                return (string) (int) $v;
            }

            return trim((string) $v);
        }

        return trim((string) $v);
    }

    private function toMoney(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return round((float) $v, 2);
        }
        $s = preg_replace('/[^\d.\-]/', '', str_replace(',', '', (string) $v)) ?? '';
        if ($s === '' || ! is_numeric($s)) {
            return null;
        }

        return round((float) $s, 2);
    }

    private function hasRealPrice(mixed $v): bool
    {
        $n = $this->toMoney($v);

        return $n !== null && abs($n) > 0.00001;
    }

    private function inferCategoryFromModel(string $model): ?string
    {
        $m = mb_strtoupper($model);

        if (str_contains($m, 'DS-2CD') || str_contains($m, 'IDS-2CD') || str_contains($m, 'HM-TD')
            || str_contains($m, 'DS-2XT') || str_contains($m, 'DS-2CF') || str_contains($m, 'DS-TCG')) {
            return 'كاميرات شبكية IP';
        }
        if (str_contains($m, 'DS-76') || str_contains($m, 'DS-77') || str_contains($m, 'IDS-77')
            || str_contains($m, 'IDS-96') || str_contains($m, 'DS-96') || str_contains($m, 'NXI')) {
            return 'مسجلات NVR';
        }
        if (str_contains($m, 'DS-2CE') || str_contains($m, 'ANALOGUE')) {
            return 'Analogue CAMERA';
        }
        if (str_contains($m, 'HQHI') || str_contains($m, 'DVR') || str_contains($m, 'DS-71')) {
            return 'مسجلات DVR';
        }
        if (str_contains($m, 'DS-MH') || str_contains($m, 'RX300') || str_contains($m, 'BODY')) {
            return 'كاميرات محمولة';
        }
        if (str_contains($m, 'DS-2DE') || str_contains($m, 'PTZ')) {
            return 'كاميرات PTZ';
        }
        if (str_contains($m, 'DASH') || str_contains($m, 'AE-D') || str_contains($m, 'MVR')) {
            return 'مسجل للسيارات والباصات (MVR)';
        }
        if (str_contains($m, 'DS-3E') || str_contains($m, 'SWITCH') || str_contains($m, 'PORT')) {
            return 'سويتشات شبكة';
        }
        if (str_contains($m, 'CAT6') || str_contains($m, 'DS-1LN') || str_contains($m, 'CABLE')) {
            return 'كابلات وملحقات';
        }
        if (str_contains($m, 'DS-D5') || str_contains($m, 'INTERACTIVE')) {
            return 'شاشات تفاعلية INTERACTIVE';
        }
        if (str_contains($m, 'PWA') || str_contains($m, 'ALARM') || str_contains($m, 'DS-PD')) {
            return 'Wireless Alarm';
        }
        if (str_contains($m, 'DS-KP') || str_contains($m, 'SIP')) {
            return 'مقاسم هاتف SIP IP';
        }
        if (str_contains($m, 'DS-KH') || str_contains($m, 'DS-KV') || str_contains($m, 'INTERCOM')) {
            return 'انترفون Intercom';
        }
        if (str_contains($m, 'DS-QA') || str_contains($m, 'SPEAKER') || str_contains($m, 'AMP')) {
            return 'صوتيات SPEAKER';
        }
        if (str_contains($m, 'EZVIZ') || str_contains($m, 'CS-')) {
            return 'EZVIZ';
        }
        if (str_contains($m, 'DS-3W') || str_contains($m, 'WAP') || str_contains($m, 'WIRELESS')) {
            return 'وايرلس ADAPTER';
        }
        if (str_contains($m, 'DS-K1') || str_contains($m, 'DS-K3') || str_contains($m, 'ACCESS')) {
            return 'تحكم بالدخول';
        }
        if (str_contains($m, 'DS-1M') || str_contains($m, 'DS-1F') || str_contains($m, 'RJ45')) {
            return 'ملحقات شبكية';
        }
        if (str_contains($m, 'TCVC') || str_contains($m, 'TDSB') || str_contains($m, 'CHECKPOINT')) {
            return 'نقاط تفتيش ورادار';
        }

        return null;
    }
}
