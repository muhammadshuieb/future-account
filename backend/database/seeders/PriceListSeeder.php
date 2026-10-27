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
            if ($index === 0 && (str_contains(mb_strtolower($col0.$col1), 'model') || $col0 === '#' || str_contains($col1, 'Model'))) {
                // fall through — detect properly below
            }
            if ($this->isHeaderRow($cells)) {
                continue;
            }

            // Category banner: e.g. [null, "Analogue CAMERA", …] with no prices
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

                continue;
            }

            // Product row: # | Model No. | MP | Specs | Lens | Super WS | Retail | Wholesale
            $rowNo = $col0;
            $model = $col1;
            if ($model === '' && $rowNo === '') {
                continue;
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

        $hasPrice = $this->toMoney($cells[5] ?? null) !== null
            || $this->toMoney($cells[6] ?? null) !== null
            || $this->toMoney($cells[7] ?? null) !== null;

        if ($hasPrice) {
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
}
