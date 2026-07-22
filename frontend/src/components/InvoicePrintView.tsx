import { useEffect, useRef, type ReactNode } from 'react'
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
  customer?: { name: string; tax_number?: string; phone?: string }
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
  supplier?: { name: string; tax_number?: string; phone?: string }
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

function BrandLogo() {
  return (
    <img
      src={LOGO.print}
      alt="SYNAMOR TECHNOLOGY"
      className="brand-logo brand-logo--print"
      onError={(e) => {
        const img = e.currentTarget
        if (img.dataset.fallback === '1') return
        img.dataset.fallback = '1'
        img.src = LOGO.default
      }}
    />
  )
}

/** Shared invoice print/view header: logo left, company + title right (RTL). */
function InvoiceBrandHeader({
  documentLabel,
  invoiceNumber,
  invoiceDate,
  companyName,
  taxNumber,
  extra,
}: {
  documentLabel: string
  invoiceNumber: string
  invoiceDate: string
  companyName?: string
  taxNumber?: string
  extra?: ReactNode
}) {
  const { t } = useTranslation()
  const brandLine = companyName?.trim()
    ? companyName
    : `${t('app.name')} — Syna Co`

  return (
    <header className="flex w-full flex-wrap items-start justify-between gap-4 border-b border-black/10 pb-4">
      {/* First in RTL → visual right: company + report title */}
      <div className="min-w-0 text-start">
        <p className="text-lg font-bold">{brandLine}</p>
        {taxNumber && (
          <p className="text-xs text-black/55">{t('companies.taxNumber')}: {taxNumber}</p>
        )}
        <p className="mt-1 text-xs font-semibold text-teal">{documentLabel}</p>
        <p className="font-mono text-base font-bold">{invoiceNumber}</p>
        <p>{String(invoiceDate).slice(0, 10)}</p>
        {extra}
      </div>
      {/* Second in RTL → visual left: logo */}
      <BrandLogo />
    </header>
  )
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
  const companyName = structured?.seller?.name

  useEffect(() => {
    if (canvasRef.current && payload) {
      void QRCode.toCanvas(canvasRef.current, payload, { width: 140, margin: 1 })
    }
  }, [payload])

  return (
    <div className="space-y-4 text-sm" dir="rtl">
      <InvoiceBrandHeader
        documentLabel={t('sales.invoices')}
        invoiceNumber={invoice.invoice_number}
        invoiceDate={invoice.invoice_date}
        companyName={companyName}
        taxNumber={structured?.seller?.tax_number}
      />

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

      {(structured?.tax_breakdown || []).filter((tb) => tb.tax > 0).length > 0 && (
        <div className="rounded border border-black/10 p-3">
          <p className="mb-2 text-xs font-semibold">تفصيل الضريبة</p>
          {(structured?.tax_breakdown || []).filter((tb) => tb.tax > 0).map((tb, i) => (
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
        {Number(invoice.tax_amount) > 0 && (
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

export function PurchaseInvoicePrintView({ invoice }: { invoice: PurchaseInvoicePrintData }) {
  const { t } = useTranslation()
  const lines = invoice.lines || invoice.items || []

  return (
    <div className="space-y-4 text-sm" dir="rtl">
      <InvoiceBrandHeader
        documentLabel={t('purchases.invoices')}
        invoiceNumber={invoice.invoice_number}
        invoiceDate={invoice.invoice_date}
      />

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
        {invoice.tax_amount != null && Number(invoice.tax_amount) > 0 && (
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
