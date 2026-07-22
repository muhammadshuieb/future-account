import { LOGO } from '@/lib/brand'
import { todayYmd } from '@/lib/dates'
import { formatMoney } from '@/components/ui'

export type StatementRow = {
  date: string
  type: string
  number: string
  debit: number
  credit: number
  balance: number
}

export type PartnerStatementData = {
  customer?: { id: number; code?: string; name: string; phone?: string }
  supplier?: { id: number; code?: string; name: string; phone?: string }
  from?: string | null
  to?: string | null
  opening_balance?: number
  closing_balance?: number
  balance?: number
  rows?: StatementRow[]
}

const TYPE_LABELS: Record<string, string> = {
  invoice: 'فاتورة',
  receipt: 'سند قبض',
  payment: 'سند صرف',
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

export function statementTypeLabel(type: string): string {
  return TYPE_LABELS[type] || type
}

export function StatementPrintView({
  data,
  kind,
  currency = 'SYP',
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
  const partnerLabel = kind === 'customer' ? 'العميل' : 'المورد'
  const period =
    data.from || data.to
      ? `${data.from || '—'} → ${data.to || '—'}`
      : 'كامل الفترة'

  return (
    <div className="space-y-4 text-sm" dir="rtl">
      <header className="flex w-full flex-wrap items-start justify-between gap-4 border-b border-black/10 pb-4">
        {/* First in RTL → visual right: company + report title */}
        <div className="min-w-0 text-start">
          <p className="text-lg font-bold">شركة ساينا — Syna Co</p>
          <p className="text-xs text-black/55">SYNAMOR TECHNOLOGY</p>
          <p className="mt-1 text-xs font-semibold text-teal">{documentLabel}</p>
          <p className="mt-1 text-xs text-black/55">تاريخ الطباعة: {todayYmd()}</p>
        </div>
        {/* Second in RTL → visual left: logo */}
        <BrandLogo />
      </header>

      <div className="grid gap-2 sm:grid-cols-2">
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

      <div className="grid gap-2 rounded-lg border border-black/10 bg-mist/40 p-3 sm:grid-cols-2">
        <p>
          الرصيد الافتتاحي:{' '}
          <strong className="tabular-nums">{formatMoney(opening, currency)}</strong>
        </p>
        <p>
          الرصيد الختامي:{' '}
          <strong className="tabular-nums">{formatMoney(closing, currency)}</strong>
        </p>
      </div>

      <table className="data-table">
        <thead>
          <tr>
            <th>التاريخ</th>
            <th>النوع</th>
            <th>الرقم</th>
            <th>مدين</th>
            <th>دائن</th>
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
              <tr key={`${r.number}-${i}`}>
                <td>{r.date}</td>
                <td>{statementTypeLabel(r.type)}</td>
                <td className="font-mono text-xs">{r.number}</td>
                <td className="tabular-nums">{formatMoney(Number(r.debit) || 0, currency)}</td>
                <td className="tabular-nums">{formatMoney(Number(r.credit) || 0, currency)}</td>
                <td className="tabular-nums">{formatMoney(Number(r.balance) || 0, currency)}</td>
              </tr>
            ))
          )}
        </tbody>
      </table>

      <div className="ms-auto max-w-xs space-y-1 border-t border-black/10 pt-3 text-start">
        <p className="text-base font-bold">
          الرصيد الختامي ({currency}):{' '}
          <span className="tabular-nums">{formatMoney(closing, currency)}</span>
        </p>
      </div>
    </div>
  )
}
