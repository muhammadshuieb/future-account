import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import QRCode from 'qrcode'
import { LOGO } from '@/lib/brand'
import { formatQuantity } from '@/components/ui'

export type SalesInvoicePrintData = {
  invoice_number: string
  invoice_date: string
  e_invoice_uuid?: string
  total: number
  tax_amount: number
  subtotal: number
  currency?: string
  customer?: { name: string; tax_number?: string }
  lines?: {
    product?: { name: string; sku?: string }
    quantity: number
    unit_price: number
    line_total: number
    batch_no?: string
    serial_no?: string
  }[]
}

export type PurchaseInvoicePrintData = {
  invoice_number: string
  invoice_date: string
  total: number
  tax_amount?: number
  subtotal?: number
  currency?: string
  supplier?: { name: string; tax_number?: string }
  lines?: {
    product?: { name: string; sku?: string }
    quantity: number
    unit_cost?: number
    unit_price?: number
    line_total: number
    batch_no?: string
    serial_no?: string
  }[]
  items?: PurchaseInvoicePrintData['lines']
}

export type EInvoiceData = {
  qr_payload?: string
  e_invoice?: Record<string, unknown>
  e_invoice_uuid?: string
}

type StructuredEInvoice = {
  uuid?: string
  seller?: { name?: string; tax_number?: string }
  tax_breakdown?: { rate: number; taxable: number; tax: number }[]
}

export function SalesInvoicePrintView({
  invoice,
  eInvoice,
}: {
  invoice: SalesInvoicePrintData
  eInvoice?: EInvoiceData
}) {
  const { t } = useTranslation()
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const payload = eInvoice?.qr_payload
  const structured = eInvoice?.e_invoice as StructuredEInvoice | undefined
  const companyName = structured?.seller?.name || 'Syna Co'

  useEffect(() => {
    if (canvasRef.current && payload) {
      void QRCode.toCanvas(canvasRef.current, payload, { width: 140, margin: 1 })
    }
  }, [payload])

  return (
    <div className="space-y-4 text-sm" dir="rtl">
      <header className="flex flex-wrap items-start justify-between gap-4 border-b border-black/10 pb-4">
        <div className="flex items-center gap-3">
          <img src={LOGO.print} alt="SYNAMOR TECHNOLOGY" className="brand-logo brand-logo--print" />
          <div>
            <p className="text-lg font-bold">{companyName}</p>
            {structured?.seller?.tax_number && (
              <p className="text-xs text-black/55">{t('companies.taxNumber')}: {structured.seller.tax_number}</p>
            )}
          </div>
        </div>
        <div className="text-start">
          <p className="text-xs font-semibold text-teal">{t('sales.invoices')}</p>
          <p className="font-mono text-base font-bold">{invoice.invoice_number}</p>
          <p>{String(invoice.invoice_date).slice(0, 10)}</p>
        </div>
      </header>

      {(payload || invoice.e_invoice_uuid || eInvoice?.e_invoice_uuid || structured?.uuid) && (
        <div className="rounded-lg border-2 border-teal/30 bg-teal/5 p-4">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-teal">
                {t('sales.eInvoice')} — future-account-einvoice/1.0
              </p>
              <p className="mt-1 font-mono text-xs text-black/60">
                UUID: {structured?.uuid || eInvoice?.e_invoice_uuid || invoice.e_invoice_uuid || '—'}
              </p>
            </div>
            {payload && <canvas ref={canvasRef} className="rounded border border-black/10" />}
          </div>
        </div>
      )}

      <div className="grid gap-2 sm:grid-cols-2">
        <p>
          <span className="text-black/55">{t('common.customer')}: </span>
          {invoice.customer?.name || '—'}
        </p>
        {invoice.customer?.tax_number && (
          <p>
            <span className="text-black/55">{t('companies.taxNumber')}: </span>
            {invoice.customer.tax_number}
          </p>
        )}
        <p>
          <span className="text-black/55">{t('common.currency')}: </span>
          {invoice.currency || 'SYP'}
        </p>
      </div>

      <table className="data-table">
        <thead>
          <tr>
            <th>{t('common.product')}</th>
            <th title={t('common.quantityUnit')}>{t('common.quantity')}</th>
            <th>{t('common.price')}</th>
            <th>{t('common.batch')}</th>
            <th>{t('common.total')}</th>
          </tr>
        </thead>
        <tbody>
          {(invoice.lines || []).map((l, i) => (
            <tr key={i}>
              <td>{l.product?.name}</td>
              <td className="tabular-nums">{formatQuantity(l.quantity)}</td>
              <td className="tabular-nums">{l.unit_price}</td>
              <td className="font-mono text-xs">{l.batch_no || l.serial_no || '—'}</td>
              <td className="tabular-nums">{l.line_total}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {(structured?.tax_breakdown || []).length > 0 && (
        <div className="rounded border border-black/10 p-3">
          <p className="mb-2 text-xs font-semibold">تفصيل الضريبة</p>
          {(structured?.tax_breakdown || []).map((tb, i) => (
            <p key={i} className="text-xs">
              {tb.rate}% — خاضع {tb.taxable}، ضريبة {tb.tax}
            </p>
          ))}
        </div>
      )}

      <div className="ms-auto max-w-xs space-y-1 border-t border-black/10 pt-3 text-start">
        <p>
          <span className="text-black/55">المجموع الفرعي: </span>
          <span className="tabular-nums">{invoice.subtotal}</span>
        </p>
        <p>
          <span className="text-black/55">الضريبة: </span>
          <span className="tabular-nums">{invoice.tax_amount}</span>
        </p>
        <p className="text-base font-bold">
          {t('common.total')} ({invoice.currency || 'SYP'}):{' '}
          <span className="tabular-nums">{invoice.total}</span>
        </p>
      </div>
    </div>
  )
}

export function PurchaseInvoicePrintView({ invoice }: { invoice: PurchaseInvoicePrintData }) {
  const { t } = useTranslation()
  const lines = invoice.lines || invoice.items || []

  return (
    <div className="space-y-4 text-sm" dir="rtl">
      <header className="flex flex-wrap items-start justify-between gap-4 border-b border-black/10 pb-4">
        <div className="flex items-center gap-3">
          <img src={LOGO.print} alt="SYNAMOR TECHNOLOGY" className="brand-logo brand-logo--print" />
          <div>
            <p className="text-lg font-bold">Syna Co</p>
          </div>
        </div>
        <div className="text-start">
          <p className="text-xs font-semibold text-teal">{t('purchases.invoices')}</p>
          <p className="font-mono text-base font-bold">{invoice.invoice_number}</p>
          <p>{String(invoice.invoice_date).slice(0, 10)}</p>
        </div>
      </header>

      <div className="grid gap-2 sm:grid-cols-2">
        <p>
          <span className="text-black/55">{t('common.supplier')}: </span>
          {invoice.supplier?.name || '—'}
        </p>
        {invoice.supplier?.tax_number && (
          <p>
            <span className="text-black/55">{t('companies.taxNumber')}: </span>
            {invoice.supplier.tax_number}
          </p>
        )}
        <p>
          <span className="text-black/55">{t('common.currency')}: </span>
          {invoice.currency || 'SYP'}
        </p>
      </div>

      <table className="data-table">
        <thead>
          <tr>
            <th>{t('common.product')}</th>
            <th title={t('common.quantityUnit')}>{t('common.quantity')}</th>
            <th>{t('common.cost')}</th>
            <th>{t('common.batch')}</th>
            <th>{t('common.total')}</th>
          </tr>
        </thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={i}>
              <td>{l.product?.name}</td>
              <td className="tabular-nums">{formatQuantity(l.quantity)}</td>
              <td className="tabular-nums">{l.unit_cost ?? l.unit_price ?? '—'}</td>
              <td className="font-mono text-xs">{l.batch_no || l.serial_no || '—'}</td>
              <td className="tabular-nums">{l.line_total}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="ms-auto max-w-xs space-y-1 border-t border-black/10 pt-3 text-start">
        {invoice.subtotal != null && (
          <p>
            <span className="text-black/55">المجموع الفرعي: </span>
            <span className="tabular-nums">{invoice.subtotal}</span>
          </p>
        )}
        {invoice.tax_amount != null && (
          <p>
            <span className="text-black/55">الضريبة: </span>
            <span className="tabular-nums">{invoice.tax_amount}</span>
          </p>
        )}
        <p className="text-base font-bold">
          {t('common.total')} ({invoice.currency || 'SYP'}):{' '}
          <span className="tabular-nums">{invoice.total}</span>
        </p>
      </div>
    </div>
  )
}
