import { useEffect, useRef, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import QRCode from 'qrcode'
import { LOGO } from '@/lib/brand'
import { formatMoney, formatQuantity } from '@/components/ui'
import { formatInvoiceDateTime, todayYmd } from '@/lib/dates'
import { ProductIdentityCells, ProductIdentityHeaders } from '@/components/ProductIdentityCells'
import { unitFromProduct } from '@/lib/productUnit'
import { statementPaymentTypeLabel } from '@/components/StatementPrintView'

export type SalesInvoicePrintData = {
  invoice_number: string
  invoice_date: string
  created_at?: string | null
  e_invoice_uuid?: string
  total: number
  tax_amount: number
  discount_amount?: number
  subtotal: number
  paid_amount?: number
  payment_type?: string
  currency?: string
  notes?: string | null
  customer?: { name: string; tax_number?: string; phone?: string }
  branch?: { name?: string; code?: string } | null
  warehouse?: { name?: string } | null
  lines?: {
    product?: { name: string; sku?: string; brand?: string; model?: string; unit?: { name?: string; symbol?: string } }
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
  created_at?: string | null
  total: number
  tax_amount?: number
  subtotal?: number
  discount_amount?: number
  customs_amount?: number
  transport_fees?: number
  fines_amount?: number
  other_fees?: number
  paid_amount?: number
  payment_type?: string
  currency?: string
  notes?: string | null
  supplier?: { name: string; tax_number?: string; phone?: string }
  lines?: {
    product?: { name: string; sku?: string; brand?: string; model?: string; unit?: { name?: string; symbol?: string } }
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
      className="brand-logo brand-logo--print print-logo"
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
  createdAt,
  companyName,
  taxNumber,
  extra,
  statementStyle = false,
}: {
  documentLabel: string
  invoiceNumber: string
  invoiceDate: string
  createdAt?: string | null
  companyName?: string
  taxNumber?: string
  extra?: ReactNode
  /** Match partner statement print header (company / brand / title / print date). */
  statementStyle?: boolean
}) {
  const { t } = useTranslation()
  const brandLine = companyName?.trim()
    ? companyName
    : `${t('app.name')} — Syna Co`

  if (statementStyle) {
    return (
      <header className="print-brand-header statement-print__header">
        <div className="min-w-0 text-start">
          <p className="statement-print__company">{brandLine}</p>
          <p className="statement-print__brand">SYNAMOR TECHNOLOGY</p>
          {taxNumber && (
            <p className="statement-print__muted">{t('companies.taxNumber')}: {taxNumber}</p>
          )}
          <p className="statement-print__doc-title">{documentLabel}</p>
          <p className="statement-print__muted font-mono">{invoiceNumber}</p>
          <p className="statement-print__muted">{formatInvoiceDateTime(invoiceDate, createdAt)}</p>
          <p className="statement-print__muted">تاريخ الطباعة: {todayYmd()}</p>
          {extra}
        </div>
        <BrandLogo />
      </header>
    )
  }

  return (
    <header className="print-brand-header flex w-full flex-wrap items-start justify-between gap-2 border-b border-black/10 pb-2">
      {/* First in RTL → visual right: company + report title */}
      <div className="min-w-0 text-start">
        <p className="text-base font-bold leading-tight">{brandLine}</p>
        {taxNumber && (
          <p className="text-[11px] text-black/55">{t('companies.taxNumber')}: {taxNumber}</p>
        )}
        <p className="mt-0.5 text-[11px] font-semibold text-teal">{documentLabel}</p>
        <p className="font-mono text-sm font-bold">{invoiceNumber}</p>
        <p className="text-xs">{formatInvoiceDateTime(invoiceDate, createdAt)}</p>
        {extra}
      </div>
      {/* Second in RTL → visual left: logo */}
      <BrandLogo />
    </header>
  )
}

type StatementStyleLine = {
  product?: { name?: string; sku?: string; brand?: string; model?: string } | null
  quantity: number
  unit_price?: number
  unit_cost?: number
  line_total: number
  serial_no?: string | null
  batch_no?: string | null
}

/** Line table matching كشف الحساب: صنف / ماركة / موديل / كمية / سعر / الإجمالي */
function StatementStyleLinesTable({
  lines,
  currency,
}: {
  lines: StatementStyleLine[]
  currency: string
}) {
  return (
    <table className="data-table statement-invoice-detail__lines">
      <thead>
        <tr>
          <th>الصنف</th>
          <th>الماركة</th>
          <th>الموديل</th>
          <th>كمية</th>
          <th>سعر</th>
          <th>الإجمالي</th>
        </tr>
      </thead>
      <tbody>
        {lines.map((line, i) => {
          const unitPrice = Number(line.unit_price ?? line.unit_cost ?? 0)
          const qty = Number(line.quantity) || 0
          const lineTotal = Number(line.line_total ?? qty * unitPrice)
          const serial = line.serial_no || line.batch_no || null
          return (
            <tr key={i}>
              <ProductIdentityCells product={line.product} serialNo={serial} />
              <td className="tabular-nums">{formatQuantity(qty)}</td>
              <td className="tabular-nums">{formatMoney(unitPrice, currency)}</td>
              <td className="tabular-nums">{formatMoney(lineTotal, currency)}</td>
            </tr>
          )
        })}
      </tbody>
    </table>
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
      void QRCode.toCanvas(canvasRef.current, payload, { width: 72, margin: 1 })
    }
  }, [payload])

  const currency = invoice.currency || 'USD'

  return (
    <div className="statement-print" dir="rtl">
      <InvoiceBrandHeader
        statementStyle
        documentLabel={t('sales.invoices')}
        invoiceNumber={invoice.invoice_number}
        invoiceDate={invoice.invoice_date}
        createdAt={invoice.created_at}
        companyName={companyName}
        taxNumber={structured?.seller?.tax_number}
      />

      {(payload || invoice.e_invoice_uuid || eInvoice?.e_invoice_uuid || structured?.uuid) && (
        <div className="rounded border border-teal/30 bg-teal/5 p-2">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="text-[11px] font-semibold uppercase text-teal">
                {t('sales.eInvoice')} — future-account-einvoice/1.0
              </p>
              <p className="mt-0.5 font-mono text-[10px] text-black/60">
                UUID: {structured?.uuid || eInvoice?.e_invoice_uuid || invoice.e_invoice_uuid || '—'}
              </p>
            </div>
            {payload && <canvas ref={canvasRef} className="print-qr rounded border border-black/10" />}
          </div>
        </div>
      )}

      <section className="statement-print__meta">
        <p>
          <span className="statement-print__label">{t('common.customer')}: </span>
          <strong>{invoice.customer?.name || '—'}</strong>
        </p>
        {invoice.customer?.phone && (
          <p>
            <span className="statement-print__label">الهاتف: </span>
            {invoice.customer.phone}
          </p>
        )}
        {invoice.customer?.tax_number && (
          <p>
            <span className="statement-print__label">{t('companies.taxNumber')}: </span>
            {invoice.customer.tax_number}
          </p>
        )}
        {invoice.branch?.name && (
          <p>
            <span className="statement-print__label">{t('common.branch')}: </span>
            {invoice.branch.name}
          </p>
        )}
        {invoice.warehouse?.name && (
          <p>
            <span className="statement-print__label">{t('common.warehouse')}: </span>
            {invoice.warehouse.name}
          </p>
        )}
        <p>
          <span className="statement-print__label">{t('common.currency')}: </span>
          {currency}
        </p>
        {invoice.payment_type && (
          <p>
            <span className="statement-print__label">{t('common.paymentType')}: </span>
            <strong>{statementPaymentTypeLabel(invoice.payment_type)}</strong>
          </p>
        )}
      </section>

      {invoice.notes ? (
        <p className="statement-invoice-detail__notes">ملاحظات: {invoice.notes}</p>
      ) : null}

      <div className="statement-invoice-detail">
        <StatementStyleLinesTable lines={invoice.lines || []} currency={currency} />
      </div>

      {(structured?.tax_breakdown || []).filter((tb) => tb.tax > 0).length > 0 && (
        <div className="rounded border border-black/10 p-2">
          <p className="mb-1 text-[11px] font-semibold">تفصيل الضريبة</p>
          {(structured?.tax_breakdown || []).filter((tb) => tb.tax > 0).map((tb, i) => (
            <p key={i} className="text-[11px]">
              {tb.rate}% — خاضع {tb.taxable}، ضريبة {tb.tax}
            </p>
          ))}
        </div>
      )}

      <section className="print-avoid-break statement-print__summary statement-print__summary--footer">
        <p>
          فرعي:{' '}
          <strong className="tabular-nums">{formatMoney(Number(invoice.subtotal) || 0, currency)}</strong>
        </p>
        {Number(invoice.discount_amount) > 0 && (
          <p>
            حسم:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.discount_amount) || 0, currency)}</strong>
          </p>
        )}
        {Number(invoice.tax_amount) > 0 && (
          <p>
            ضريبة:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.tax_amount) || 0, currency)}</strong>
          </p>
        )}
        {invoice.paid_amount != null && (
          <p>
            المدفوع:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.paid_amount) || 0, currency)}</strong>
          </p>
        )}
        <p className="statement-print__closing-line">
          الإجمالي:{' '}
          <strong className="tabular-nums">{formatMoney(Number(invoice.total) || 0, currency)}</strong>
        </p>
      </section>
    </div>
  )
}

export type SalesQuotePrintData = {
  quote_number: string
  quote_date: string
  created_at?: string | null
  valid_until?: string | null
  total: number
  tax_amount?: number
  subtotal: number
  currency?: string
  notes?: string | null
  customer?: { name: string; tax_number?: string; phone?: string } | null
  branch?: { name?: string; code?: string } | null
  warehouse?: { name?: string } | null
  items?: {
    product_name?: string | null
    brand?: string | null
    model?: string | null
    product?: { name: string; sku?: string; brand?: string; model?: string; unit?: { name?: string; symbol?: string } }
    quantity: number
    unit_price: number
    line_total: number
  }[]
}

function quoteLineProduct(item: NonNullable<SalesQuotePrintData['items']>[number]) {
  return {
    name: item.product_name?.trim() || item.product?.name,
    brand: item.brand?.trim() || item.product?.brand,
    model: item.model?.trim() || item.product?.model,
    unit: item.product?.unit,
  }
}

export function SalesQuotePrintView({ quote }: { quote: SalesQuotePrintData }) {
  const { t } = useTranslation()
  const lines = (quote.items || []).map((l) => ({
    product: quoteLineProduct(l),
    quantity: l.quantity,
    unit_price: l.unit_price,
    line_total: l.line_total,
  }))
  const currency = quote.currency || 'USD'

  return (
    <div className="statement-print" dir="rtl">
      <InvoiceBrandHeader
        statementStyle
        documentLabel={t('quotes.documentTitle')}
        invoiceNumber={quote.quote_number}
        invoiceDate={quote.quote_date}
        createdAt={quote.created_at}
      />

      <section className="statement-print__meta">
        <p>
          <span className="statement-print__label">{t('common.customer')}: </span>
          <strong>{quote.customer?.name || t('quotes.noCustomer')}</strong>
        </p>
        {quote.customer?.phone && (
          <p>
            <span className="statement-print__label">الهاتف: </span>
            {quote.customer.phone}
          </p>
        )}
        {quote.customer?.tax_number && (
          <p>
            <span className="statement-print__label">{t('companies.taxNumber')}: </span>
            {quote.customer.tax_number}
          </p>
        )}
        {quote.branch?.name && (
          <p>
            <span className="statement-print__label">{t('common.branch')}: </span>
            {quote.branch.name}
          </p>
        )}
        {quote.warehouse?.name && (
          <p>
            <span className="statement-print__label">{t('common.warehouse')}: </span>
            {quote.warehouse.name}
          </p>
        )}
        {quote.valid_until && (
          <p>
            <span className="statement-print__label">{t('common.validUntil')}: </span>
            {String(quote.valid_until).slice(0, 10)}
          </p>
        )}
        <p>
          <span className="statement-print__label">{t('common.currency')}: </span>
          {currency}
        </p>
      </section>

      {quote.notes ? (
        <p className="statement-invoice-detail__notes">ملاحظات: {quote.notes}</p>
      ) : null}

      <div className="statement-invoice-detail">
        <StatementStyleLinesTable lines={lines} currency={currency} />
      </div>

      <section className="print-avoid-break statement-print__summary statement-print__summary--footer">
        <p>
          فرعي:{' '}
          <strong className="tabular-nums">{formatMoney(Number(quote.subtotal) || 0, currency)}</strong>
        </p>
        {Number(quote.tax_amount) > 0 && (
          <p>
            ضريبة:{' '}
            <strong className="tabular-nums">{formatMoney(Number(quote.tax_amount) || 0, currency)}</strong>
          </p>
        )}
        <p className="statement-print__closing-line">
          الإجمالي:{' '}
          <strong className="tabular-nums">{formatMoney(Number(quote.total) || 0, currency)}</strong>
        </p>
      </section>

      <p className="statement-print__muted">{t('quotes.printDisclaimer')}</p>
    </div>
  )
}

export type PrintInvoicePrintData = {
  invoice_number: string
  invoice_date: string
  created_at?: string | null
  total: number
  tax_amount?: number
  subtotal: number
  currency?: string
  notes?: string | null
  customer?: { name: string; tax_number?: string; phone?: string } | null
  branch?: { name?: string; code?: string } | null
  warehouse?: { name?: string } | null
  items?: {
    product_name?: string | null
    brand?: string | null
    model?: string | null
    product?: { name: string; sku?: string; brand?: string; model?: string; unit?: { name?: string; symbol?: string } }
    quantity: number
    unit_price: number
    line_total: number
  }[]
}

function printLineProduct(item: NonNullable<PrintInvoicePrintData['items']>[number]) {
  return {
    name: item.product_name?.trim() || item.product?.name,
    brand: item.brand?.trim() || item.product?.brand,
    model: item.model?.trim() || item.product?.model,
    unit: item.product?.unit,
  }
}

export function PrintInvoicePrintView({ invoice }: { invoice: PrintInvoicePrintData }) {
  const { t } = useTranslation()
  const lines = invoice.items || []

  return (
    <div className="space-y-2 text-xs" dir="rtl">
      <InvoiceBrandHeader
        documentLabel={t('printInvoices.documentTitle')}
        invoiceNumber={invoice.invoice_number}
        invoiceDate={invoice.invoice_date}
        createdAt={invoice.created_at}
      />

      <div className="grid gap-1 sm:grid-cols-2">
        <p>
          <span className="text-black/55">{t('common.customer')}: </span>
          {invoice.customer?.name || t('printInvoices.noCustomer')}
        </p>
        {invoice.customer?.tax_number && (
          <p>
            <span className="text-black/55">{t('companies.taxNumber')}: </span>
            {invoice.customer.tax_number}
          </p>
        )}
        {invoice.branch?.name && (
          <p>
            <span className="text-black/55">{t('common.branch')}: </span>
            {invoice.branch.name}
          </p>
        )}
        {invoice.warehouse?.name && (
          <p>
            <span className="text-black/55">{t('common.warehouse')}: </span>
            {invoice.warehouse.name}
          </p>
        )}
        <p>
          <span className="text-black/55">{t('common.currency')}: </span>
          {invoice.currency || 'USD'}
        </p>
      </div>

      {invoice.notes ? (
        <div className="rounded border border-black/10 bg-black/[0.02] p-2">
          <p className="text-[11px] font-semibold text-black/55">{t('common.notes')}</p>
          <p className="mt-0.5 whitespace-pre-wrap">{invoice.notes}</p>
        </div>
      ) : null}

      <table className="data-table text-[11px]">
        <thead>
          <tr>
            <ProductIdentityHeaders />
            <th>{t('common.unit')}</th>
            <th title={t('common.quantityUnit')}>{t('common.quantity')}</th>
            <th>{t('common.price')}</th>
            <th>{t('common.total')}</th>
          </tr>
        </thead>
        <tbody>
          {lines.map((l, i) => {
            const product = printLineProduct(l)
            return (
            <tr key={i}>
              <ProductIdentityCells product={product} />
              <td>{unitFromProduct(product)}</td>
              <td className="tabular-nums">{formatQuantity(l.quantity)}</td>
              <td className="tabular-nums">{l.unit_price}</td>
              <td className="tabular-nums">{l.line_total}</td>
            </tr>
            )
          })}
        </tbody>
      </table>

      <div className="print-avoid-break ms-auto max-w-xs space-y-0.5 border-t border-black/10 pt-2 text-start">
        <p>
          <span className="text-black/55">{t('common.subtotal')}: </span>
          <span className="tabular-nums">{invoice.subtotal}</span>
        </p>
        {Number(invoice.tax_amount) > 0 && (
          <p>
            <span className="text-black/55">{t('common.tax')}: </span>
            <span className="tabular-nums">{invoice.tax_amount}</span>
          </p>
        )}
        <p className="text-sm font-bold">
          {t('common.total')} ({invoice.currency || 'USD'}):{' '}
          <span className="tabular-nums">{invoice.total}</span>
        </p>
      </div>
    </div>
  )
}

export function PurchaseInvoicePrintView({ invoice }: { invoice: PurchaseInvoicePrintData }) {
  const { t } = useTranslation()
  const lines = invoice.lines || invoice.items || []
  const currency = invoice.currency || 'USD'

  return (
    <div className="statement-print" dir="rtl">
      <InvoiceBrandHeader
        statementStyle
        documentLabel={t('purchases.invoices')}
        invoiceNumber={invoice.invoice_number}
        invoiceDate={invoice.invoice_date}
        createdAt={invoice.created_at}
      />

      <section className="statement-print__meta">
        <p>
          <span className="statement-print__label">{t('common.supplier')}: </span>
          <strong>{invoice.supplier?.name || '—'}</strong>
        </p>
        {invoice.supplier?.phone && (
          <p>
            <span className="statement-print__label">الهاتف: </span>
            {invoice.supplier.phone}
          </p>
        )}
        {invoice.supplier?.tax_number && (
          <p>
            <span className="statement-print__label">{t('companies.taxNumber')}: </span>
            {invoice.supplier.tax_number}
          </p>
        )}
        <p>
          <span className="statement-print__label">{t('common.currency')}: </span>
          {currency}
        </p>
        {invoice.payment_type && (
          <p>
            <span className="statement-print__label">{t('common.paymentType')}: </span>
            <strong>{statementPaymentTypeLabel(invoice.payment_type)}</strong>
          </p>
        )}
      </section>

      {invoice.notes ? (
        <p className="statement-invoice-detail__notes">ملاحظات: {invoice.notes}</p>
      ) : null}

      <div className="statement-invoice-detail">
        <StatementStyleLinesTable lines={lines} currency={currency} />
      </div>

      <section className="print-avoid-break statement-print__summary statement-print__summary--footer">
        {invoice.subtotal != null && (
          <p>
            فرعي:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.subtotal) || 0, currency)}</strong>
          </p>
        )}
        {Number(invoice.discount_amount) > 0 && (
          <p>
            حسم:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.discount_amount) || 0, currency)}</strong>
          </p>
        )}
        {invoice.tax_amount != null && Number(invoice.tax_amount) > 0 && (
          <p>
            ضريبة:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.tax_amount) || 0, currency)}</strong>
          </p>
        )}
        {Number(invoice.customs_amount) > 0 && (
          <p>
            {t('purchases.customs')}:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.customs_amount) || 0, currency)}</strong>
          </p>
        )}
        {Number(invoice.transport_fees) > 0 && (
          <p>
            {t('purchases.transportFees')}:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.transport_fees) || 0, currency)}</strong>
          </p>
        )}
        {Number(invoice.fines_amount) > 0 && (
          <p>
            {t('purchases.fines')}:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.fines_amount) || 0, currency)}</strong>
          </p>
        )}
        {Number(invoice.other_fees) > 0 && (
          <p>
            {t('purchases.otherFees')}:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.other_fees) || 0, currency)}</strong>
          </p>
        )}
        {invoice.paid_amount != null && (
          <p>
            المدفوع:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.paid_amount) || 0, currency)}</strong>
          </p>
        )}
        <p className="statement-print__closing-line">
          الإجمالي:{' '}
          <strong className="tabular-nums">{formatMoney(Number(invoice.total) || 0, currency)}</strong>
        </p>
      </section>
    </div>
  )
}
