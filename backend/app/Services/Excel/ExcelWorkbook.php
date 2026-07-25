<?php

namespace App\Services\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Thin PhpSpreadsheet helper: Arabic headers, Latin digits, RTL sheets.
 */
class ExcelWorkbook
{
    protected Spreadsheet $spreadsheet;

    protected int $sheetIndex = 0;

    public function __construct(?string $title = null)
    {
        $this->spreadsheet = new Spreadsheet;
        $this->spreadsheet->getProperties()
            ->setCreator('Syna Co ERP')
            ->setTitle($title ?: 'Syna Co Export');

        // Remove default sheet; callers add named sheets.
        $this->spreadsheet->removeSheetByIndex(0);
    }

    public function spreadsheet(): Spreadsheet
    {
        return $this->spreadsheet;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    public function addSheet(string $title, array $headers, array $rows): Worksheet
    {
        $safeTitle = $this->safeSheetTitle($title);
        $sheet = new Worksheet($this->spreadsheet, $safeTitle);
        $this->spreadsheet->addSheet($sheet, $this->sheetIndex++);
        $sheet->setRightToLeft(true);

        $colCount = max(count($headers), 1);
        foreach ($headers as $i => $header) {
            $cell = Coordinate::stringFromColumnIndex($i + 1).'1';
            $sheet->setCellValue($cell, $this->latinDigits((string) $header));
        }

        $headerRange = 'A1:'.Coordinate::stringFromColumnIndex($colCount).'1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0F766E'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $rowNum = 2;
        foreach ($rows as $row) {
            foreach ($row as $i => $value) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$rowNum;
                $sheet->setCellValue($cell, $this->normalizeValue($value));
            }
            $rowNum++;
        }

        for ($i = 1; $i <= $colCount; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return $sheet;
    }

    public function ensureNotEmpty(): void
    {
        if ($this->spreadsheet->getSheetCount() === 0) {
            $this->addSheet('فارغ', ['ملاحظة'], [['لا توجد بيانات']]);
        }
        $this->spreadsheet->setActiveSheetIndex(0);
    }

    public function save(string $path): void
    {
        $this->ensureNotEmpty();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        (new Xlsx($this->spreadsheet))->save($path);
    }

    /**
     * @return resource
     */
    public function toTempStream()
    {
        $this->ensureNotEmpty();
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new \RuntimeException('تعذر إنشاء ملف مؤقت للتصدير.');
        }
        $meta = stream_get_meta_data($tmp);
        $uri = $meta['uri'] ?? null;
        if (! is_string($uri) || $uri === '') {
            throw new \RuntimeException('تعذر إنشاء ملف مؤقت للتصدير.');
        }
        (new Xlsx($this->spreadsheet))->save($uri);
        rewind($tmp);

        return $tmp;
    }

    protected function safeSheetTitle(string $title): string
    {
        $clean = preg_replace('/[\\\\\\/*\\?\\:\\[\\]]/', '', $title) ?? $title;
        $clean = trim($clean);
        if ($clean === '') {
            $clean = 'ورقة';
        }
        if (mb_strlen($clean) > 31) {
            $clean = mb_substr($clean, 0, 31);
        }

        $existing = [];
        foreach ($this->spreadsheet->getAllSheets() as $sheet) {
            $existing[$sheet->getTitle()] = true;
        }
        $base = $clean;
        $n = 2;
        while (isset($existing[$clean])) {
            $suffix = ' '.$n;
            $clean = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
            $n++;
        }

        return $clean;
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_array($value) || is_object($value)) {
            return $this->latinDigits(json_encode($value, JSON_UNESCAPED_UNICODE) ?: '');
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return $this->latinDigits((string) $value);
    }

    /** Convert Eastern Arabic / Persian digits to Latin 0-9. */
    public function latinDigits(string $value): string
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
