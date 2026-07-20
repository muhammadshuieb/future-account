<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Setting;
use App\Services\EInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BarcodeController extends ApiController
{
    public function __construct(protected EInvoiceService $eInvoice) {}
    public function productLabels(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');

        $data = $request->validate([
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = Product::query()->where('is_active', true)->orderBy('sku');
        if (! empty($data['product_ids'])) {
            $query->whereIn('id', $data['product_ids']);
        }

        $copies = $data['copies'] ?? 1;
        $company = Setting::getValue('company_name', 'شركة ساينا');

        $labels = [];
        foreach ($query->get() as $product) {
            $code = $product->barcode ?: $this->suggestBarcode($product);
            for ($i = 0; $i < $copies; $i++) {
                $labels[] = [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'barcode' => $code,
                    'format' => $this->detectFormat($code),
                    'price' => (float) $product->sale_price,
                    'company' => $company,
                ];
            }
        }

        return $this->ok(['labels' => $labels, 'count' => count($labels)]);
    }

    public function generateForProduct(Request $request, Product $product): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');

        $data = $request->validate([
            'barcode' => ['nullable', 'string', 'max:64'],
            'save' => ['nullable', 'boolean'],
        ]);

        $code = $data['barcode'] ?? $product->barcode ?? $this->suggestBarcode($product);

        if ($request->boolean('save', true)) {
            $exists = Product::query()
                ->where('barcode', $code)
                ->where('id', '!=', $product->id)
                ->exists();
            if ($exists) {
                abort(422, 'الباركود مستخدم لصنف آخر.');
            }
            $product->update(['barcode' => $code]);
        }

        return $this->ok([
            'product_id' => $product->id,
            'barcode' => $code,
            'format' => $this->detectFormat($code),
            'name' => $product->name,
            'sku' => $product->sku,
        ]);
    }

    public function invoiceQr(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorizePermission('sales.view');

        $payload = $this->eInvoice->buildPayload($salesInvoice);

        return $this->ok([
            'invoice_number' => $salesInvoice->invoice_number,
            'e_invoice_uuid' => $payload['uuid'],
            'e_invoice' => $payload,
            'qr_payload' => $this->eInvoice->qrPayload($salesInvoice),
            'print_url' => '/sales?print='.$salesInvoice->id,
        ]);
    }

    public function eInvoice(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok($this->eInvoice->buildPayload($salesInvoice));
    }

    protected function suggestBarcode(Product $product): string
    {
        // Prefer EAN-13 style numeric code from SKU digits + checksum-ish pad
        $digits = preg_replace('/\D+/', '', (string) $product->sku) ?: (string) $product->id;
        $digits = str_pad(substr($digits, 0, 12), 12, '0', STR_PAD_LEFT);
        $check = $this->ean13CheckDigit($digits);

        return $digits.$check;
    }

    protected function ean13CheckDigit(string $twelve): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $n = (int) $twelve[$i];
            $sum += ($i % 2 === 0) ? $n : $n * 3;
        }

        return (10 - ($sum % 10)) % 10;
    }

    protected function detectFormat(string $code): string
    {
        if (preg_match('/^\d{13}$/', $code)) {
            return 'EAN13';
        }
        if (preg_match('/^\d{8}$/', $code)) {
            return 'EAN8';
        }

        return 'CODE128';
    }
}
