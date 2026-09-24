import { Fragment } from 'react'
import { LOGO } from '@/lib/brand'
import { todayYmd } from '@/lib/dates'
import { productLabel } from '@/lib/productLabel'
import { formatMoney, formatQuantity } from '@/components/ui'

export type StatementInvoiceLine = {
  quantity?: number
  unit_price?: number
  unit_cost?: number
  line_total?: number
  tax_rate?: number
  batch_no?: string | null
  serial_no?: string | null
  product?: { name?: string; sku?: string; brand?: string; model?: string } | null
}

export type StatementInvoiceDetail = {
  payment_type?: string | null
  subtotal?: number
  discount_amount?: number
  tax_amount?: number
  total?: number
  paid_amount?: number
  currency?: string
  notes?: string | null
  lines?: StatementInvoiceLine[]
}

export type StatementRow = {
  date: string
  type: string
  number: string
  document_id?: number
  currency?: string
  document_amount?: number
  notes?: string | null
  debit: number
  credit: number
  balance: number
  invoice?: StatementInvoiceDetail | null
}

export type PartnerStatementData = {
  customer?: { id: number; code?: string; name: string; phone?: string }
  supplier?: { id: number; code?: string; name: string; phone?: string }
  from?: string | null
  to?: string | null
  currency?: string
  opening_balance?: number
  closing_balance?: number
  total_debit?: number
  total_credit?: number
  balance?: number
  rows?: StatementRow[]
}

const TYPE_LABELS: Record<string, string> = {
  invoice: 'فاتورة',
  receipt: 'سند قبض',
  payment: 'سند صرف',
  return: 'مرتجع',
}

const PAYMENT_TYPE_LABELS: Record<string, string> = {
  cash: 'نقدي',
  credit: 'آجل',
  partial: 'دفعة من المبلغ',
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

export function statementTypeLabel(type: string): string {
  return TYPE_LABELS[type] || type
}

export function statementPaymentTypeLabel(type?: string | null): string {
  if (!type) return '—'
  return PAYMENT_TYPE_LABELS[type] || type
}

function InvoiceDetailBlock({
  invoice,
  fallbackCurrency,
}: {
  invoice: StatementInvoiceDetail
  fallbackCurrency: string
}) {
  const docCurrency = invoice.currency || fallbackCurrency
  const lines = invoice.lines || []

  return (
    <div className="mt-1 space-y-1 rounded border border-black/10 bg-mist/30 p-1.5 text-[10px]">
      <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-black/70">
        <span>
          نوع الدفع: <strong>{statementPaymentTypeLabel(invoice.payment_type)}</strong>
        </span>
        <span>
          العملة: <strong>{docCurrency}</strong>
        </span>
        <span>
          فرعي: <strong className="tabular-nums">{formatMoney(Number(invoice.subtotal) || 0, docCurrency)}</strong>
        </span>
        {Number(invoice.discount_amount) > 0 && (
          <span>
            حسم: <strong className="tabular-nums">{formatMoney(Number(invoice.discount_amount) || 0, docCurrency)}</strong>
          </span>
        )}
        {Number(invoice.tax_amount) > 0 && (
          <span>
            ضريبة: <strong className="tabular-nums">{formatMoney(Number(invoice.tax_amount) || 0, docCurrency)}</strong>
          </span>
        )}
        <span>
          الإجمالي: <strong className="tabular-nums">{formatMoney(Number(invoice.total) || 0, docCurrency)}</strong>
        </span>
        <span>
          المدفوع: <strong className="tabular-nums">{formatMoney(Number(invoice.paid_amount) || 0, docCurrency)}</strong>
        </span>
      </div>
      {invoice.notes ? (
        <p className="text-black/55">
          ملاحظات: {invoice.notes}
        </p>
      ) : null}
      {lines.length > 0 && (
        <table className="w-full border-collapse text-[10px]">
          <thead>
            <tr className="text-black/55">
              <th className="border-b border-black/10 py-0.5 text-start font-medium">الصنف</th>
              <th className="border-b border-black/10 py-0.5 text-start font-medium">كمية</th>
              <th className="border-b border-black/10 py-0.5 text-start font-medium">سعر</th>
              <th className="border-b border-black/10 py-0.5 text-start font-medium">الإجمالي</th>
            </tr>
          </thead>
          <tbody>
            {lines.map((line, i) => {
              const unitPrice = Number(line.unit_price ?? line.unit_cost ?? 0)
              const qty = Number(line.quantity) || 0
              const lineTotal = Number(line.line_total ?? qty * unitPrice)
              return (
                <tr key={i}>
                  <td className="py-0.5 align-top">
                    {productLabel(line.product)}
                    {line.serial_no ? (
                      <span className="mt-0.5 block font-mono text-[9px] text-black/45">{line.serial_no}</span>
                    ) : null}
                  </td>
                  <td className="py-0.5 tabular-nums">{formatQuantity(qty)}</td>
                  <td className="py-0.5 tabular-nums">{formatMoney(unitPrice, docCurrency)}</td>
                  <td className="py-0.5 tabular-nums">{formatMoney(lineTotal, docCurrency)}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
    </div>
  )
}

export function StatementPrintView({
  data,
  kind,
  currency = 'USD',
  documentLabel,
}: {
  data: PartnerStatementData
  kind: 'customer' | 'supplier'
  currency?: string
  documentLabel: string
}) {
  const partner = kind === 'customer' ? data.customer : data.supplier
  const opening = Number(data.opening_balance ?? 0)
  const closing = Number(data.closing_balance ?? data.balance ?? 0)
  const rows = data.rows || []
  const totalDebit = Number(
    data.total_debit ?? rows.reduce((s, r) => s + (Number(r.debit) || 0), 0),
  )
  const totalCredit = Number(
    data.total_credit ?? rows.reduce((s, r) => s + (Number(r.credit) || 0), 0),
  )
  const partnerLabel = kind === 'customer' ? 'العميل' : 'المورد'
  const period =
    data.from || data.to
      ? `${data.from || '—'} → ${data.to || '—'}`
      : 'كامل الفترة'

  return (
    <div className="space-y-2 text-xs" dir="rtl">
      <header className="print-brand-header flex w-full flex-wrap items-start justify-between gap-2 border-b border-black/10 pb-2">
        {/* First in RTL → visual right: company + report title */}
        <div className="min-w-0 text-start">
          <p className="text-base font-bold leading-tight">شركة ساينا — Syna Co</p>
          <p className="text-[11px] text-black/55">SYNAMOR TECHNOLOGY</p>
          <p className="mt-0.5 text-[11px] font-semibold text-teal">{documentLabel}</p>
          <p className="mt-0.5 text-[11px] text-black/55">تاريخ الطباعة: {todayYmd()}</p>
        </div>
        {/* Second in RTL → visual left: logo */}
        <BrandLogo />
      </header>

      <div className="grid gap-1 sm:grid-cols-2">
        <p>
          <span className="text-black/55">{partnerLabel}: </span>
          <strong>
            {partner?.code ? `${partner.code} — ` : ''}
            {partner?.name || '—'}
          </strong>
        </p>
        {partner?.phone && (
          <p>
            <span className="text-black/55">الهاتف: </span>
            {partner.phone}
          </p>
        )}
        <p>
          <span className="text-black/55">الفترة: </span>
          {period}
        </p>
        <p>
          <span className="text-black/55">العملة: </span>
          {currency}
        </p>
      </div>

      <div className="grid gap-1 rounded border border-black/10 bg-mist/40 p-2 sm:grid-cols-2 lg:grid-cols-4">
        <p>
          الرصيد الافتتاحي:{' '}
          <strong className="tabular-nums">{formatMoney(opening, currency)}</strong>
        </p>
        <p>
          إجمالي عليه:{' '}
          <strong className="tabular-nums">{formatMoney(totalDebit, currency)}</strong>
        </p>
        <p>
          إجمالي له:{' '}
          <strong className="tabular-nums">{formatMoney(totalCredit, currency)}</strong>
        </p>
        <p>
          الرصيد الختامي:{' '}
          <strong className="tabular-nums">{formatMoney(closing, currency)}</strong>
        </p>
      </div>

      <table className="data-table text-[11px]">
        <thead>
          <tr>
            <th>التاريخ</th>
            <th>النوع</th>
            <th>الرقم</th>
            <th>عليه</th>
            <th>له</th>
            <th>الرصيد</th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={6} className="py-6 text-center text-black/45">
                لا توجد حركات في الفترة المحددة
              </td>
            </tr>
          ) : (
            rows.map((r, i) => (
              <Fragment key={`${r.number}-${i}`}>
                <tr>
                  <td>{r.date}</td>
                  <td>{statementTypeLabel(r.type)}</td>
                  <td className="font-mono text-xs">{r.number}</td>
                  <td className="tabular-nums">{formatMoney(Number(r.debit) || 0, currency)}</td>
                  <td className="tabular-nums">{formatMoney(Number(r.credit) || 0, currency)}</td>
                  <td className="tabular-nums">{formatMoney(Number(r.balance) || 0, currency)}</td>
                </tr>
                {r.type === 'invoice' && r.invoice ? (
                  <tr className="print-avoid-break">
                    <td colSpan={6} className="!border-t-0 bg-transparent p-1">
                      <InvoiceDetailBlock invoice={r.invoice} fallbackCurrency={currency} />
                    </td>
                  </tr>
                ) : null}
              </Fragment>
            ))
          )}
        </tbody>
        {rows.length > 0 && (
          <tfoot>
            <tr className="font-semibold">
              <td colSpan={3}>الإجمالي</td>
              <td className="tabular-nums">{formatMoney(totalDebit, currency)}</td>
              <td className="tabular-nums">{formatMoney(totalCredit, currency)}</td>
              <td />
            </tr>
          </tfoot>
        )}
      </table>

      <div className="print-avoid-break ms-auto max-w-xs space-y-0.5 border-t border-black/10 pt-2 text-start">
        <p className="text-sm font-bold">
          الرصيد الختامي ({currency}):{' '}
          <span className="tabular-nums">{formatMoney(closing, currency)}</span>
        </p>
      </div>
    </div>
  )
}
