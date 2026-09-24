import { Fragment, useState, type ReactNode } from 'react'
import { ChevronDown, ChevronLeft } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ProductIdentityCells, ProductIdentityHeaders } from '@/components/ProductIdentityCells'
import { paymentTypeLabel } from '@/components/PaymentTypeFields'
import {
  isStatementPaymentRow,
  statementTypeLabel,
  type PartnerStatementData,
  type StatementInvoiceDetail,
  type StatementInvoiceLine,
  type StatementRow,
} from '@/components/StatementPrintView'
import { Button, Modal, StatTile, formatMoney, formatQuantity } from '@/components/ui'

export function partnerBalanceLabel(
  balance: number,
  kind: 'customer' | 'supplier',
  currency: string,
  labels: { owedByThem: string; owedToThem: string },
): string {
  const abs = Math.abs(balance)
  const money = formatMoney(abs, currency)
  if (kind === 'customer') {
    if (balance > 0.009) return `${money} — ${labels.owedByThem}`
    if (balance < -0.009) return `${money} — ${labels.owedToThem}`
    return formatMoney(0, currency)
  }
  if (balance > 0.009) return `${money} — ${labels.owedToThem}`
  if (balance < -0.009) return `${money} — ${labels.owedByThem}`
  return formatMoney(0, currency)
}

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-[#3d4f5a]">{label}</dt>
      <dd className="text-end font-medium text-[#111111]">{value}</dd>
    </div>
  )
}

function InvoiceMeta({
  invoice,
  fallbackCurrency,
  t,
}: {
  invoice: StatementInvoiceDetail
  fallbackCurrency: string
  t: (key: string) => string
}) {
  const docCurrency = invoice.currency || fallbackCurrency
  return (
    <dl className="mb-3 grid gap-2 text-xs text-[#1a2b34] sm:grid-cols-2 lg:grid-cols-3">
      <DetailRow
        label={t('common.paymentType')}
        value={paymentTypeLabel(invoice.payment_type, t)}
      />
      <DetailRow label={t('common.currency')} value={docCurrency} />
      <DetailRow
        label={t('common.subtotal')}
        value={formatMoney(Number(invoice.subtotal) || 0, docCurrency)}
      />
      {Number(invoice.discount_amount) > 0 && (
        <DetailRow
          label={t('common.discount')}
          value={formatMoney(Number(invoice.discount_amount) || 0, docCurrency)}
        />
      )}
      {Number(invoice.tax_amount) > 0 && (
        <DetailRow
          label={t('common.tax')}
          value={formatMoney(Number(invoice.tax_amount) || 0, docCurrency)}
        />
      )}
      <DetailRow
        label={t('common.total')}
        value={formatMoney(Number(invoice.total) || 0, docCurrency)}
      />
      <DetailRow
        label={t('common.paidAmount')}
        value={formatMoney(Number(invoice.paid_amount) || 0, docCurrency)}
      />
      {invoice.notes ? <DetailRow label={t('common.notes')} value={invoice.notes} /> : null}
    </dl>
  )
}

function InvoiceLinesTable({
  lines,
  currency,
  t,
  dense = false,
}: {
  lines: StatementInvoiceLine[]
  currency: string
  t: (key: string) => string
  dense?: boolean
}) {
  const cell = dense ? 'px-2.5 py-1.5' : 'px-3 py-2.5'
  return (
    <div className="overflow-x-auto">
      <table className="statement-invoice-lines w-full text-xs text-[#111111]">
        <thead className="bg-[rgba(13,115,119,0.12)] text-[#064e51]">
          <tr>
            <ProductIdentityHeaders className={`${cell} text-start font-bold`} />
            <th className={`${cell} text-start font-bold`}>{t('common.quantity')}</th>
            <th className={`${cell} text-start font-bold`}>{t('common.price')}</th>
            <th className={`${cell} text-start font-bold`}>{t('common.total')}</th>
          </tr>
        </thead>
        <tbody>
          {lines.map((line, i) => {
            const unitPrice = Number(line.unit_price ?? line.unit_cost ?? 0)
            const qty = Number(line.quantity) || 0
            const lineTotal = Number(line.line_total ?? qty * unitPrice)
            return (
              <tr key={i}>
                <ProductIdentityCells
                  product={line.product}
                  className={`${cell} text-[#111111]`}
                  serialNo={line.serial_no}
                />
                <td className={`${cell} tabular-nums text-[#111111]`}>{formatQuantity(qty)}</td>
                <td className={`${cell} tabular-nums text-[#111111]`}>{formatMoney(unitPrice, currency)}</td>
                <td className={`${cell} tabular-nums text-[#111111]`}>{formatMoney(lineTotal, currency)}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

export default function PartnerStatementPanel({
  data,
  kind,
  currency = 'USD',
  dense = false,
}: {
  data: PartnerStatementData
  kind: 'customer' | 'supplier'
  currency?: string
  /** Compact layout for reports print area */
  dense?: boolean
}) {
  const { t, i18n } = useTranslation()
  const [selected, setSelected] = useState<StatementRow | null>(null)
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})
  const rows = data.rows || []
  const opening = Number(data.opening_balance ?? 0)
  const closing = Number(data.closing_balance ?? data.balance ?? 0)
  const totalDebit = Number(
    data.total_debit ?? rows.reduce((s, r) => s + (Number(r.debit) || 0), 0),
  )
  const totalCredit = Number(
    data.total_credit ?? rows.reduce((s, r) => s + (Number(r.credit) || 0), 0),
  )
  const labels = {
    owedByThem: t('common.owedByThem'),
    owedToThem: t('common.owedToThem'),
  }
  const isRtl = i18n.dir() === 'rtl'

  const pad = dense ? 'px-2.5 py-2.5' : 'px-4 py-3.5'
  const theadPad = dense ? 'px-2.5 py-2.5' : 'px-4 py-3.5'
  const colCount = 7

  const rowKey = (r: StatementRow, idx: number) =>
    `${r.type}-${r.document_id ?? r.number}-${idx}`

  const toggleExpand = (key: string) => {
    setExpanded((prev) => ({ ...prev, [key]: !prev[key] }))
  }

  return (
    <>
      <div className={dense ? '' : 'px-1 pb-2'}>
      <table className={`statement-panel__movements w-full text-sm ${dense ? 'data-table' : ''}`}>
        <thead className={dense ? undefined : 'bg-mist text-right text-black/60'}>
          <tr>
            <th className={`${theadPad} w-8`} aria-hidden />
            <th className={theadPad}>{t('common.date')}</th>
            <th className={theadPad}>{t('common.type')}</th>
            <th className={theadPad}>{t('common.number')}</th>
            <th className={theadPad}>{t('common.owedByThem')}</th>
            <th className={theadPad}>{t('common.owedToThem')}</th>
            <th className={theadPad}>{t('common.balance')}</th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={colCount} className={`${pad} text-center text-black/45`}>
                {t('common.noStatementRows')}
              </td>
            </tr>
          ) : (
            rows.map((r, idx) => {
              const key = rowKey(r, idx)
              const hasInvoiceDetail = r.type === 'invoice' && !!r.invoice
              const isOpen = !!expanded[key]
              const invoice = r.invoice
              const docCurrency = invoice?.currency || r.currency || currency
              const isPayment = isStatementPaymentRow(r.type)

              return (
                <Fragment key={key}>
                  <tr
                    className={`statement-row ${isPayment ? 'statement-row--payment' : ''} ${
                      hasInvoiceDetail || r.notes ? 'row-clickable' : ''
                    }`}
                    onClick={() => {
                      if (hasInvoiceDetail) toggleExpand(key)
                      else setSelected(r)
                    }}
                    onKeyDown={(e) => {
                      if (e.key !== 'Enter') return
                      if (hasInvoiceDetail) toggleExpand(key)
                      else setSelected(r)
                    }}
                    tabIndex={0}
                    title={
                      hasInvoiceDetail
                        ? t('common.clickToExpandInvoice')
                        : t('common.clickForDetails')
                    }
                  >
                    <td className={`${pad} w-8 text-black/40`}>
                      {hasInvoiceDetail ? (
                        isOpen ? (
                          <ChevronDown size={16} aria-hidden />
                        ) : isRtl ? (
                          <ChevronLeft size={16} aria-hidden />
                        ) : (
                          <ChevronDown size={16} className="-rotate-90" aria-hidden />
                        )
                      ) : null}
                    </td>
                    <td className={pad}>{r.date}</td>
                    <td className={pad}>{statementTypeLabel(r.type)}</td>
                    <td className={`${pad} font-mono text-xs`}>{r.number}</td>
                    <td className={`${pad} tabular-nums`}>{formatMoney(Number(r.debit) || 0, currency)}</td>
                    <td className={`${pad} tabular-nums`}>{formatMoney(Number(r.credit) || 0, currency)}</td>
                    <td className={`${pad} tabular-nums`}>{formatMoney(Number(r.balance) || 0, currency)}</td>
                  </tr>
                  {hasInvoiceDetail && isOpen && invoice ? (
                    <tr className="statement-panel__detail-row">
                      <td colSpan={colCount} className={dense ? 'px-3 py-3.5' : 'px-5 py-4'}>
                        <div className="space-y-3 rounded-md border border-teal/20 bg-white p-3.5 text-[#111111] shadow-[0_1px_0_rgba(12,26,34,0.04)]">
                          <p className="text-xs font-bold tracking-wide text-[#064e51]">
                            {t('common.invoiceDetails')}
                          </p>
                          <InvoiceMeta invoice={invoice} fallbackCurrency={currency} t={t} />
                          {(invoice.lines || []).length > 0 ? (
                            <InvoiceLinesTable
                              lines={invoice.lines || []}
                              currency={docCurrency}
                              t={t}
                              dense={dense}
                            />
                          ) : (
                            <p className="text-xs text-[#3d4f5a]">{t('common.noInvoiceLines')}</p>
                          )}
                        </div>
                      </td>
                    </tr>
                  ) : null}
                </Fragment>
              )
            })
          )}
        </tbody>
        {rows.length > 0 && (
          <tfoot>
            <tr className="font-semibold">
              <td className={pad} colSpan={4}>{t('common.total')}</td>
              <td className={`${pad} tabular-nums`}>{formatMoney(totalDebit, currency)}</td>
              <td className={`${pad} tabular-nums`}>{formatMoney(totalCredit, currency)}</td>
              <td className={pad} />
            </tr>
          </tfoot>
        )}
      </table>
      </div>

      <div
        className={`statement-panel__totals grid gap-3 sm:grid-cols-2 lg:grid-cols-4 ${
          dense ? 'mt-5' : 'mt-1 border-t border-[var(--color-line)] px-4 py-5'
        }`}
      >
        <StatTile
          label={t('common.openingBalance')}
          value={formatMoney(opening, currency)}
        />
        <StatTile
          label={t('common.totalOwedByThem')}
          value={formatMoney(totalDebit, currency)}
          subtitle={t('common.owedByThem')}
          tone="amber"
        />
        <StatTile
          label={t('common.totalOwedToThem')}
          value={formatMoney(totalCredit, currency)}
          subtitle={t('common.owedToThem')}
          tone="teal"
        />
        <StatTile
          label={t('common.closingBalance')}
          value={partnerBalanceLabel(closing, kind, currency, labels)}
          tone="success"
        />
      </div>

      <Modal
        open={!!selected}
        onClose={() => setSelected(null)}
        title={selected ? `${t('common.statementLineDetails')} — ${selected.number}` : ''}
        footer={<Button variant="secondary" onClick={() => setSelected(null)}>{t('common.close')}</Button>}
      >
        {selected && (
          <div className="space-y-4 text-sm">
            <dl className="space-y-2">
              <DetailRow label={t('common.date')} value={selected.date} />
              <DetailRow label={t('common.type')} value={statementTypeLabel(selected.type)} />
              <DetailRow label={t('common.number')} value={<span className="font-mono">{selected.number}</span>} />
              {selected.currency && (
                <DetailRow
                  label={t('common.documentAmount')}
                  value={formatMoney(Number(selected.document_amount) || 0, selected.currency)}
                />
              )}
              <DetailRow
                label={t('common.owedByThem')}
                value={<span className="tabular-nums">{formatMoney(Number(selected.debit) || 0, currency)}</span>}
              />
              <DetailRow
                label={t('common.owedToThem')}
                value={<span className="tabular-nums">{formatMoney(Number(selected.credit) || 0, currency)}</span>}
              />
              <DetailRow
                label={t('common.balance')}
                value={<span className="tabular-nums">{formatMoney(Number(selected.balance) || 0, currency)}</span>}
              />
              {selected.notes && (
                <DetailRow label={t('common.notes')} value={selected.notes} />
              )}
            </dl>
          </div>
        )}
      </Modal>
    </>
  )
}
