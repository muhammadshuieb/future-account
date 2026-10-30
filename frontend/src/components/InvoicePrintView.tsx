import { useEffect, useRef, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import QRCode from 'qrcode'
import { useDocumentBrand } from '@/context/DocumentBrandContext'
import { formatMoney, formatQuantity } from '@/components/ui'
import { formatInvoiceDateTime } from '@/lib/dates'
import { ProductIdentityCells } from '@/components/ProductIdentityCells'
import PrintStamp from '@/components/PrintStamp'
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

function usePrintDocClass(extra = '') {
  const { brand } = useDocumentBrand()
  return ['statement-print', `statement-print--${brand.id}`, extra].filter(Boolean).join(' ')
}

function BrandLogo() {
  const { brand } = useDocumentBrand()
  return (
    <img
      key={brand.logoPrint}
      src={brand.logoPrint}
      alt={brand.brandMark}
      className={`brand-logo brand-logo--print print-logo${brand.id === 'albairaq' ? ' brand-logo--print-albairaq' : ''}`}
      onError={(e) => {
        const img = e.currentTarget
        if (img.dataset.fallback === '1') return
        img.dataset.fallback = '1'
        img.src = brand.logoFallback
      }}
    />
  )
}

/** Albairaq: company name (left half) + crisp emblem (right) + navy/gold doc banner. */
function AlbairaqBrandHeader({
  documentLabel,
  invoiceNumber,
  invoiceDate,
  createdAt,
  extra,
}: {
  documentLabel: string
  invoiceNumber: string
  invoiceDate: string
  createdAt?: string | null
  extra?: ReactNode
}) {
  const { brand } = useDocumentBrand()
  return (
    <header className="ab-print-header print-brand-header">
      <div className="ab-print-header__brand">
        {/* RTL: first = visual right (name), second = visual left (logo) */}
        <div className="ab-print-header__identity">
          <p className="ab-print-header__company">{brand.companyName}</p>
          <p className="ab-print-header__company-en">{brand.companyNameEn}</p>
          {brand.tagline ? <p className="ab-print-header__tagline">{brand.tagline}</p> : null}
          {brand.commercialRegister ? (
            <p className="ab-print-header__cr">سجل تجاري: {brand.commercialRegister}</p>
          ) : null}
        </div>
        <div className="ab-print-header__logo">
          <BrandLogo />
        </div>
      </div>
      <div className="ab-print-header__rule" aria-hidden="true" />
      <div className="ab-print-header__banner">
        <div className="ab-print-header__banner-main">
          <p className="ab-print-header__title">{documentLabel}</p>
          <p className="ab-print-header__number font-mono">{invoiceNumber}</p>
        </div>
        <p className="ab-print-header__date">{formatInvoiceDateTime(invoiceDate, createdAt)}</p>
        {extra}
      </div>
    </header>
  )
}

/** Shared invoice print/view header: logo left, company + title right (RTL). */
function InvoiceBrandHeader({
  documentLabel,
  invoiceNumber,
  invoiceDate,
  createdAt,
  taxNumber,
  extra,
  statementStyle = false,
}: {
  documentLabel: string
  invoiceNumber: string
  invoiceDate: string
  createdAt?: string | null
  /** Optional tax / CR line when e-invoice provides it (Syna). */
  taxNumber?: string
  extra?: ReactNode
  /** Match partner statement print header (company / brand / title). */
  statementStyle?: boolean
}) {
  const { t } = useTranslation()
  const { brand } = useDocumentBrand()

  if (brand.id === 'albairaq') {
    return (
      <AlbairaqBrandHeader
        documentLabel={documentLabel}
        invoiceNumber={invoiceNumber}
        invoiceDate={invoiceDate}
        createdAt={createdAt}
        extra={extra}
      />
    )
  }

  const brandLine = `${brand.companyName} — ${brand.companyNameEn}`
  const showBrandMark = brand.brandMark && brand.brandMark !== brand.companyNameEn
  const registryLine = brand.commercialRegister
    ? `${t('common.commercialRegister')}: ${brand.commercialRegister}`
    : taxNumber
      ? `${t('companies.taxNumber')}: ${taxNumber}`
      : null

  if (statementStyle) {
    return (
      <header className="print-brand-header statement-print__header statement-print__header--syna">
        <div className="min-w-0 text-start">
          <p className="statement-print__company">{brandLine}</p>
          {showBrandMark ? <p className="statement-print__brand">{brand.brandMark}</p> : null}
          {brand.tagline ? <p className="statement-print__muted">{brand.tagline}</p> : null}
          {registryLine ? <p className="statement-print__muted">{registryLine}</p> : null}
          <p className="statement-print__doc-title">{documentLabel}</p>
          <p className="statement-print__muted font-mono">{invoiceNumber}</p>
          <p className="statement-print__muted">{formatInvoiceDateTime(invoiceDate, createdAt)}</p>
          {extra}
        </div>
        <BrandLogo />
      </header>
    )
  }

  return (
    <header className="print-brand-header flex w-full flex-wrap items-start justify-between gap-2 border-b border-black/10 pb-2 print-brand-header--syna">
      <div className="min-w-0 text-start">
        <p className="text-base font-bold leading-tight">{brandLine}</p>
        {showBrandMark ? <p className="text-[11px] font-semibold text-black/60">{brand.brandMark}</p> : null}
        {brand.tagline ? <p className="text-[11px] text-black/55">{brand.tagline}</p> : null}
        {registryLine ? <p className="text-[11px] text-black/55">{registryLine}</p> : null}
        <p className="mt-0.5 text-[11px] font-semibold text-teal">{documentLabel}</p>
        <p className="font-mono text-sm font-bold">{invoiceNumber}</p>
        <p className="text-xs">{formatInvoiceDateTime(invoiceDate, createdAt)}</p>
        {extra}
      </div>
      <BrandLogo />
    </header>
  )
}

function PrintClosing({ children }: { children: ReactNode }) {
  const { brand } = useDocumentBrand()
  if (brand.id === 'albairaq') {
    return (
      <div className="ab-print-closing print-avoid-break">
        <div className="ab-print-closing__totals">{children}</div>
        <PrintStamp className="ab-print-closing__stamp" />
      </div>
    )
  }
  return (
    <>
      {children}
      <PrintStamp />
    </>
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
  const { brand } = useDocumentBrand()
  const rootClass = usePrintDocClass()
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const payload = eInvoice?.qr_payload
  const structured = eInvoice?.e_invoice as StructuredEInvoice | undefined
  const showEInvoiceQr = brand.id !== 'albairaq'

  useEffect(() => {
    if (!showEInvoiceQr) return
    if (canvasRef.current && payload) {
      void QRCode.toCanvas(canvasRef.current, payload, { width: 72, margin: 1 })
    }
  }, [payload, showEInvoiceQr])

  const currency = invoice.currency || 'USD'

  return (
    <div className={rootClass} dir="rtl">
      <InvoiceBrandHeader
        statementStyle
        documentLabel={t('sales.invoices')}
        invoiceNumber={invoice.invoice_number}
        invoiceDate={invoice.invoice_date}
        createdAt={invoice.created_at}
        taxNumber={structured?.seller?.tax_number}
      />

      {showEInvoiceQr && (payload || invoice.e_invoice_uuid || eInvoice?.e_invoice_uuid || structured?.uuid) && (
        <div className="statement-print__einvoice">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="statement-print__einvoice-title">
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

      <PrintClosing>
        <section className="statement-print__summary statement-print__summary--footer">
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
      </PrintClosing>
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
  const rootClass = usePrintDocClass()
  const lines = (quote.items || []).map((l) => ({
    product: quoteLineProduct(l),
    quantity: l.quantity,
    unit_price: l.unit_price,
    line_total: l.line_total,
  }))
  const currency = quote.currency || 'USD'

  return (
    <div className={rootClass} dir="rtl">
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

      <PrintClosing>
        <section className="statement-print__summary statement-print__summary--footer">
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
      </PrintClosing>
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
  const rootClass = usePrintDocClass()
  const lines = (invoice.items || []).map((l) => ({
    product: printLineProduct(l),
    quantity: l.quantity,
    unit_price: l.unit_price,
    line_total: l.line_total,
  }))
  const currency = invoice.currency || 'USD'

  return (
    <div className={rootClass} dir="rtl">
      <InvoiceBrandHeader
        statementStyle
        documentLabel={t('printInvoices.documentTitle')}
        invoiceNumber={invoice.invoice_number}
        invoiceDate={invoice.invoice_date}
        createdAt={invoice.created_at}
      />

      <section className="statement-print__meta">
        <p>
          <span className="statement-print__label">{t('common.customer')}: </span>
          <strong>{invoice.customer?.name || t('printInvoices.noCustomer')}</strong>
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
      </section>

      {invoice.notes ? (
        <p className="statement-invoice-detail__notes">ملاحظات: {invoice.notes}</p>
      ) : null}

      <div className="statement-invoice-detail">
        <StatementStyleLinesTable lines={lines} currency={currency} />
      </div>

      <PrintClosing>
        <section className="statement-print__summary statement-print__summary--footer">
          <p>
            فرعي:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.subtotal) || 0, currency)}</strong>
          </p>
          {Number(invoice.tax_amount) > 0 && (
            <p>
              ضريبة:{' '}
              <strong className="tabular-nums">{formatMoney(Number(invoice.tax_amount) || 0, currency)}</strong>
            </p>
          )}
          <p className="statement-print__closing-line">
            الإجمالي:{' '}
            <strong className="tabular-nums">{formatMoney(Number(invoice.total) || 0, currency)}</strong>
          </p>
        </section>
      </PrintClosing>
    </div>
  )
}

export function PurchaseInvoicePrintView({ invoice }: { invoice: PurchaseInvoicePrintData }) {
  const { t } = useTranslation()
  const rootClass = usePrintDocClass()
  const lines = invoice.lines || invoice.items || []
  const currency = invoice.currency || 'USD'

  return (
    <div className={rootClass} dir="rtl">
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

      <PrintClosing>
        <section className="statement-print__summary statement-print__summary--footer">
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
      </PrintClosing>
    </div>
  )
}
