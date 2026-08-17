import { useEffect, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { formatDateTimeLocal } from '@/lib/dates'
import ExcelExportButton from '@/components/ExcelExportButton'
import { EmptyState, ListSearchInput, LoadingBlock, PageHeader, Panel, inputClass } from '@/components/ui'
import { useListSearch } from '@/lib/useListSearch'

type AuditRow = {
  id: number
  action: string
  entity_type?: string | null
  entity_id?: number | string | null
  auditable_type?: string | null
  auditable_id?: number | string | null
  reference?: string | null
  ip_address?: string | null
  created_at: string
  user?: { name: string } | null
  old_values?: Record<string, unknown> | null
  new_values?: Record<string, unknown> | null
}

type AuditMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
  period: string
}

type PeriodId = 'day' | 'week' | 'month' | 'year' | 'all'

const PERIODS: PeriodId[] = ['day', 'week', 'month', 'year', 'all']
const PAGE_SIZES = [10, 25, 50, 100, 200]

function entityKey(row: AuditRow): string | null {
  if (row.entity_type) return row.entity_type
  const raw = row.action.split('.')[0]
  return raw || (row.auditable_type ? row.auditable_type.split('\\').pop() || null : null)
}

function actionKey(action: string): string {
  const normalized = action.toLowerCase()
  if (normalized.endsWith('.created') || normalized === 'created') return 'created'
  if (normalized.endsWith('.updated') || normalized === 'updated') return 'updated'
  if (normalized.endsWith('.deleted') || normalized === 'deleted') return 'deleted'
  if (normalized.endsWith('.posted') || normalized === 'posted' || normalized.includes('.posted')) return 'posted'
  if (normalized.includes('void')) return 'voided'
  if (normalized.includes('requested')) return 'requested'
  if (normalized.includes('approved')) return 'approved'
  if (normalized.includes('rejected')) return 'rejected'
  if (normalized.startsWith('stock.')) return 'stock'
  return action
}

function referenceOf(row: AuditRow): string {
  if (row.reference) return row.reference
  const bags = [row.new_values, row.old_values]
  for (const bag of bags) {
    if (!bag) continue
    for (const key of [
      'invoice_number', 'quote_number', 'order_number', 'request_number', 'return_number',
      'receipt_number', 'payment_number', 'entry_number', 'transfer_number', 'exchange_number',
      'count_number', 'movement_number', 'employee_number', 'sku', 'code', 'username', 'name', 'key',
    ]) {
      const value = bag[key]
      if (value != null && String(value).trim()) return String(value).trim()
    }
  }
  const id = row.entity_id ?? row.auditable_id
  return id != null ? `#${id}` : ''
}

export default function AuditLogPage() {
  const { t } = useTranslation()
  const search = useListSearch()
  const [period, setPeriod] = useState<PeriodId>('month')
  const [perPage, setPerPage] = useState(25)
  const [page, setPage] = useState(1)

  useEffect(() => {
    setPage(1)
  }, [search.debouncedQ, period, perPage])

  const logs = useQuery({
    queryKey: ['audit-logs', search.debouncedQ, period, perPage, page],
    queryFn: async () => {
      const res = await api.get('/audit-logs', {
        params: {
          ...search.params,
          period,
          per_page: perPage,
          page,
        },
      })
      return res.data as { data: AuditRow[]; meta: AuditMeta }
    },
    placeholderData: keepPreviousData,
  })

  if (logs.isLoading && !logs.data) return <LoadingBlock />

  const rows = logs.data?.data || []
  const meta = logs.data?.meta
  const lastPage = Math.max(1, meta?.last_page || 1)
  const currentPage = meta?.current_page || page
  const periodLabel: Record<PeriodId, string> = {
    day: t('audit.periodDay'),
    week: t('audit.periodWeek'),
    month: t('audit.periodMonth'),
    year: t('audit.periodYear'),
    all: t('audit.periodAll'),
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('audit.title')}
        subtitle={t('audit.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ListSearchInput value={search.q} onChange={search.setQ} />
            <ExcelExportButton path="/exports/audit-logs" params={{ period, q: search.debouncedQ || undefined }} />
          </div>
        }
      />

      <div className="print-hide flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-1">
          {PERIODS.map((id) => (
            <button
              key={id}
              type="button"
              onClick={() => setPeriod(id)}
              className={[
                'rounded-lg px-3 py-2 text-sm font-medium transition',
                period === id ? 'bg-teal text-white' : 'text-black/65 hover:bg-mist hover:text-ink',
              ].join(' ')}
            >
              {periodLabel[id]}
            </button>
          ))}
        </div>
        <label className="flex items-center gap-2 text-sm text-black/65">
          <span>{t('audit.perPage')}</span>
          <select
            className={`${inputClass} w-auto min-w-[5rem]`}
            value={perPage}
            onChange={(event) => setPerPage(Number(event.target.value))}
          >
            {PAGE_SIZES.map((size) => (
              <option key={size} value={size}>{size}</option>
            ))}
          </select>
        </label>
      </div>

      {search.debouncedQ && rows.length === 0 ? <EmptyState title={t('common.noSearchResults')} /> : null}
      <Panel>
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.date')}</th>
                <th>{t('audit.user')}</th>
                <th>{t('audit.operation')}</th>
                <th>{t('audit.ip')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const key = actionKey(row.action)
                const verb = t(`audit.actions.${key}`, { defaultValue: row.action })
                const entity = entityKey(row)
                const entityLabel = entity
                  ? t(`audit.entities.${entity}`, { defaultValue: entity.replaceAll('_', ' ') })
                  : ''
                const number = referenceOf(row)
                return (
                  <tr key={row.id}>
                    <td className="whitespace-nowrap font-mono text-xs">
                      {formatDateTimeLocal(row.created_at)}
                    </td>
                    <td>{row.user?.name || '—'}</td>
                    <td>
                      <div className="font-medium">
                        {verb}
                        {entityLabel ? ` — ${entityLabel}` : ''}
                        {number ? ` ${number}` : ''}
                      </div>
                    </td>
                    <td className="font-mono text-xs">{row.ip_address || '—'}</td>
                  </tr>
                )
              })}
              {rows.length === 0 && (
                <tr>
                  <td colSpan={4}>
                    <EmptyState title={t('audit.empty')} />
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {meta && meta.total > 0 && (
          <div className="print-hide flex flex-wrap items-center justify-between gap-3 border-t border-[var(--color-line)] px-4 py-3 text-sm text-black/60">
            <p>
              {t('audit.showing', {
                from: meta.from ?? 0,
                to: meta.to ?? 0,
                total: meta.total,
              })}
            </p>
            <div className="flex items-center gap-2">
              <button
                type="button"
                className="rounded-lg border border-black/10 px-3 py-1.5 disabled:opacity-40"
                disabled={currentPage <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                {t('audit.prevPage')}
              </button>
              <span className="tabular-nums">
                {t('audit.pageOf', { page: currentPage, pages: lastPage })}
              </span>
              <button
                type="button"
                className="rounded-lg border border-black/10 px-3 py-1.5 disabled:opacity-40"
                disabled={currentPage >= lastPage}
                onClick={() => setPage((p) => p + 1)}
              >
                {t('audit.nextPage')}
              </button>
            </div>
          </div>
        )}
      </Panel>
    </div>
  )
}
