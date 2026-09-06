import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { productLabel } from '@/lib/productLabel'
import {
  statementTypeLabel,
  type PartnerStatementData,
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

type InvoiceLine = {
  quantity?: number
  unit_price?: number
  unit_cost?: number
  line_total?: number
  tax_rate?: number
  batch_no?: string
  serial_no?: string
  product?: { name?: string; sku?: string; brand?: string; model?: string; unit?: { name?: string } }
}

type DocDetail = {
  notes?: string | null
  payment_type?: string
  paid_amount?: number
  discount_amount?: number
  tax_amount?: number
  subtotal?: number
  total?: number
  currency?: string
  exchange_rate?: number
  warehouse?: { name?: string }
  cash_box?: { name?: string }
  lines?: InvoiceLine[]
}

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-black/50">{label}</dt>
      <dd className="text-end">{value}</dd>
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
  const { t } = useTranslation()
  const [selected, setSelected] = useState<StatementRow | null>(null)
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

  const docDetail = useQuery({
    queryKey: ['statement-doc', kind, selected?.type, selected?.document_id],
    enabled: !!selected && selected.type === 'invoice' && !!selected.document_id,
    queryFn: async () => {
      const path =
        kind === 'customer'
          ? `/sales-invoices/${selected!.document_id}`
          : `/purchase-invoices/${selected!.document_id}`
      return (await api.get(path)).data.data as DocDetail
    },
  })

  const pad = dense ? 'px-2 py-2' : 'px-4 py-3'
  const theadPad = dense ? 'px-2 py-2' : 'px-4 py-3'

  return (
    <>
      <div className={`grid gap-3 ${dense ? 'mb-3 sm:grid-cols-2 lg:grid-cols-4' : 'px-4 py-3 sm:grid-cols-2 lg:grid-cols-4'}`}>
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

      <table className={`w-full text-sm ${dense ? 'data-table' : ''}`}>
        <thead className={dense ? undefined : 'bg-mist text-right text-black/60'}>
          <tr>
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
              <td colSpan={6} className={`${pad} text-center text-black/45`}>
                {t('common.noStatementRows')}
              </td>
            </tr>
          ) : (
            rows.map((r, idx) => (
              <tr
                key={`${r.number}-${idx}`}
                className="row-clickable border-t border-black/5"
                onClick={() => setSelected(r)}
                onKeyDown={(e) => e.key === 'Enter' && setSelected(r)}
                tabIndex={0}
                title={t('common.clickForDetails')}
              >
                <td className={pad}>{r.date}</td>
                <td className={pad}>{statementTypeLabel(r.type)}</td>
                <td className={`${pad} font-mono text-xs`}>{r.number}</td>
                <td className={`${pad} tabular-nums`}>{formatMoney(Number(r.debit) || 0, currency)}</td>
                <td className={`${pad} tabular-nums`}>{formatMoney(Number(r.credit) || 0, currency)}</td>
                <td className={`${pad} tabular-nums`}>{formatMoney(Number(r.balance) || 0, currency)}</td>
              </tr>
            ))
          )}
        </tbody>
        {rows.length > 0 && (
          <tfoot>
            <tr className="border-t border-black/10 font-semibold">
              <td className={pad} colSpan={3}>{t('common.total')}</td>
              <td className={`${pad} tabular-nums`}>{formatMoney(totalDebit, currency)}</td>
              <td className={`${pad} tabular-nums`}>{formatMoney(totalCredit, currency)}</td>
              <td className={pad} />
            </tr>
          </tfoot>
        )}
      </table>

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
              {(selected.notes || docDetail.data?.notes) && (
                <DetailRow label={t('common.notes')} value={selected.notes || docDetail.data?.notes || '—'} />
              )}
            </dl>

            {selected.type === 'invoice' && selected.document_id && (
              <div className="border-t border-black/10 pt-3">
                <p className="mb-2 font-semibold">{t('common.lines')}</p>
                {docDetail.isLoading && <p className="text-black/45">{t('common.loading')}</p>}
                {docDetail.error && <p className="text-danger">{t('common.loadDocumentFailed')}</p>}
                {docDetail.data && (
                  <>
                    <dl className="mb-3 space-y-1 text-xs text-black/60">
                      {docDetail.data.warehouse?.name && (
                        <DetailRow label={t('common.warehouse')} value={docDetail.data.warehouse.name} />
                      )}
                      {docDetail.data.payment_type && (
                        <DetailRow label={t('common.paymentType')} value={docDetail.data.payment_type} />
                      )}
                      {docDetail.data.discount_amount != null && Number(docDetail.data.discount_amount) > 0 && (
                        <DetailRow
                          label={t('common.discount')}
                          value={formatMoney(Number(docDetail.data.discount_amount), docDetail.data.currency || currency)}
                        />
                      )}
                    </dl>
                    <table className="w-full text-xs">
                      <thead className="bg-mist text-black/60">
                        <tr>
                          <th className="px-2 py-2 text-start">{t('common.product')}</th>
                          <th className="px-2 py-2 text-start">{t('common.quantity')}</th>
                          <th className="px-2 py-2 text-start">{t('common.price')}</th>
                          <th className="px-2 py-2 text-start">{t('common.total')}</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(docDetail.data.lines || []).map((line, i) => {
                          const unitPrice = Number(line.unit_price ?? line.unit_cost ?? 0)
                          const qty = Number(line.quantity) || 0
                          const lineTotal = Number(line.line_total ?? qty * unitPrice)
                          return (
                            <tr key={i} className="border-t border-black/5">
                              <td className="px-2 py-2">
                                {line.product ? productLabel(line.product) : '—'}
                                {line.serial_no ? (
                                  <span className="mt-0.5 block font-mono text-[10px] text-black/45">{line.serial_no}</span>
                                ) : null}
                              </td>
                              <td className="px-2 py-2 tabular-nums">{formatQuantity(qty)}</td>
                              <td className="px-2 py-2 tabular-nums">
                                {formatMoney(unitPrice, docDetail.data.currency || currency)}
                              </td>
                              <td className="px-2 py-2 tabular-nums">
                                {formatMoney(lineTotal, docDetail.data.currency || currency)}
                              </td>
                            </tr>
                          )
                        })}
                      </tbody>
                    </table>
                  </>
                )}
              </div>
            )}
          </div>
        )}
      </Modal>
    </>
  )
}
