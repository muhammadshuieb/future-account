<?php

namespace Database\Seeders;

use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Database\Seeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import warehouse price sheet as a second customer-facing list named «ساينا».
 * Excel columns (مستودع رئيسي / البيان):
 *  البيان | النوع | الموديل | الوحدة | العدد | إفرادي | شحن | تكلفة القطعة | …
 */
class SynaPriceListSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/syna-warehouse-prices.xlsx');
        if (! is_file($path)) {
            $this->command?->warn('Syna price list Excel not found: '.$path);

            return;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('مستودع رئيسي')
            ?? $spreadsheet->getSheetByName('البيان')
            ?? $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        $list = PriceList::query()->firstOrCreate(
            ['name' => 'ساينا'],
            [
                'currency' => 'USD',
                'is_active' => true,
                'notes' => 'مستودع مع أسعار — قائمة ساينا',
            ]
        );

        PriceListItem::query()->where('price_list_id', $list->id)->delete();

        $currentCategory = null;
        $sort = 0;
        $imported = 0;
        $rowNo = 0;

        foreach ($rows as $index => $row) {
            if ($index < 7 || ! is_array($row)) {
                continue;
            }

            $bayan = $this->cellStr($row[3] ?? null);
            $type = $this->cellStr($row[4] ?? null);
            $model = $this->cellStr($row[5] ?? null);
            $unit = $this->cellStr($row[6] ?? null);
            $unitPrice = $this->toMoney($row[8] ?? null);
            $landed = $this->toMoney($row[10] ?? null);

            if ($bayan === '' && $model === '') {
                continue;
            }

            $category = $type !== '' ? $type : ($bayan !== '' ? $bayan : 'عام');

            if ($category !== $currentCategory) {
                $currentCategory = $category;
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
                $rowNo = 0;
            }

            $rowNo++;
            $sort++;
            // Retail = unit price; wholesale = landed unit cost when available.
            PriceListItem::query()->create([
                'price_list_id' => $list->id,
                'sort_order' => $sort,
                'category' => $currentCategory,
                'row_no' => (string) $rowNo,
                'model_no' => $model !== '' ? $model : ($bayan !== '' ? $bayan : null),
                'mp' => null,
                'specs' => $bayan !== '' ? $bayan : null,
                'lens' => $unit !== '' ? $unit : null,
                'product_url' => null,
                'price_super_wholesale' => null,
                'price_retail' => $unitPrice,
                'price_wholesale' => $landed ?? $unitPrice,
                'is_category_header' => false,
                'is_active' => true,
            ]);
            $imported++;
        }

        $this->command?->info("Syna price list seeded: {$imported} rows into #{$list->id}");
    }

    private function cellStr(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) || is_int($v)) {
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
