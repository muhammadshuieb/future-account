<?php

namespace App\Services;

use App\Models\SalesInvoice;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Structured e-invoice payload (UBL-like simplified JSON).
 * Government API submission (ZATCA, GIB, etc.) is Phase 2 — requires country credentials.
 */
class EInvoiceService
{
    public function ensureUuid(SalesInvoice $invoice): SalesInvoice
    {
        if (! $invoice->e_invoice_uuid) {
            $invoice->update(['e_invoice_uuid' => (string) Str::uuid()]);
            $invoice->refresh();
        }

        return $invoice;
    }

    public function buildPayload(SalesInvoice $invoice): array
    {
        $invoice = $this->ensureUuid($invoice->load(['customer', 'lines.product', 'warehouse']));

        $companyName = Setting::getValue('company_name', 'Future Account');
        $taxNumber = Setting::getValue('tax_number', '');
        $baseCurrency = Setting::getValue('currency', 'SYP');

        $taxBreakdown = [];
        $lines = [];
        foreach ($invoice->lines as $line) {
            $lineSub = round((float) $line->quantity * (float) $line->unit_price, 2);
            $lineTax = round($lineSub * (float) $line->tax_rate / 100, 2);
            $rateKey = (string) $line->tax_rate;
            if (! isset($taxBreakdown[$rateKey])) {
                $taxBreakdown[$rateKey] = ['rate' => (float) $line->tax_rate, 'taxable' => 0, 'tax' => 0];
            }
            $taxBreakdown[$rateKey]['taxable'] += $lineSub;
            $taxBreakdown[$rateKey]['tax'] += $lineTax;

            $lines[] = [
                'line_id' => $line->id,
                'product_sku' => $line->product?->sku,
                'product_name' => $line->product?->name,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'tax_rate' => (float) $line->tax_rate,
                'line_subtotal' => $lineSub,
                'line_tax' => $lineTax,
                'line_total' => (float) $line->line_total,
                'batch_no' => $line->batch_no,
                'serial_no' => $line->serial_no,
            ];
        }

        return [
            'schema' => 'future-account-einvoice/1.0',
            'uuid' => $invoice->e_invoice_uuid,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => optional($invoice->invoice_date)->toDateString(),
            'posted_at' => optional($invoice->posted_at)?->toIso8601String(),
            'seller' => [
                'name' => $companyName,
                'tax_number' => $taxNumber,
                'country' => Setting::getValue('country_code', 'SY'),
            ],
            'buyer' => [
                'name' => $invoice->customer?->name,
                'tax_number' => $invoice->customer?->tax_number,
            ],
            'currency' => $invoice->currency ?? $baseCurrency,
            'exchange_rate' => (float) ($invoice->exchange_rate ?: 1),
            'base_currency' => $baseCurrency,
            'base_amount' => (float) ($invoice->base_amount ?: $invoice->total),
            'amounts' => [
                'subtotal' => (float) $invoice->subtotal,
                'tax_total' => (float) $invoice->tax_amount,
                'total' => (float) $invoice->total,
                'paid' => (float) $invoice->paid_amount,
            ],
            'tax_breakdown' => array_values($taxBreakdown),
            'lines' => $lines,
            'warehouse' => $invoice->warehouse?->name,
            'notes' => $invoice->notes,
        ];
    }

    public function qrPayload(SalesInvoice $invoice): string
    {
        return json_encode($this->buildPayload($invoice), JSON_UNESCAPED_UNICODE);
    }
}
