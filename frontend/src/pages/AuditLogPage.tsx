import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { EmptyState, LoadingBlock, PageHeader, Panel } from '@/components/ui'

type AuditRow = {
  id: number
  action: string
  entity_type?: string | null
  entity_id?: number | string | null
  auditable_type?: string | null
  auditable_id?: number | string | null
  ip_address?: string | null
  created_at: string
  user?: { name: string } | null
  old_values?: Record<string, unknown> | null
  new_values?: Record<string, unknown> | null
}

function entityLabel(row: AuditRow): string {
  const type = row.entity_type || (row.auditable_type ? row.auditable_type.split('\\').pop() : null)
  const id = row.entity_id ?? row.auditable_id
  if (!type) return '—'
  return id != null ? `${type} #${id}` : type
}

function actionKey(action: string): string {
  const normalized = action.toLowerCase()
  if (normalized.endsWith('.created') || normalized === 'created') return 'created'
  if (normalized.endsWith('.updated') || normalized === 'updated') return 'updated'
  if (normalized.endsWith('.deleted') || normalized === 'deleted') return 'deleted'
  if (normalized.endsWith('.posted') || normalized === 'posted' || normalized.includes('.posted')) return 'posted'
  if (normalized.includes('void')) return 'voided'
  if (normalized.startsWith('stock.')) return 'stock'
  return action
}

export default function AuditLogPage() {
  const { t } = useTranslation()
  const logs = useQuery({
    queryKey: ['audit-logs'],
    queryFn: async () => (await api.get('/audit-logs')).data.data as AuditRow[],
  })

  if (logs.isLoading) return <LoadingBlock />

  const rows = logs.data || []

  return (
    <div className="space-y-6">
      <PageHeader title={t('audit.title')} subtitle={t('audit.subtitle')} />
      <Panel>
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.date')}</th>
                <th>{t('audit.user')}</th>
                <th>{t('audit.action')}</th>
                <th>{t('audit.entity')}</th>
                <th>{t('audit.ip')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const key = actionKey(row.action)
                const label = t(`audit.actions.${key}`, { defaultValue: row.action })
                return (
                  <tr key={row.id}>
                    <td className="whitespace-nowrap font-mono text-xs">
                      {String(row.created_at).slice(0, 19).replace('T', ' ')}
                    </td>
                    <td>{row.user?.name || '—'}</td>
                    <td>
                      <div>{label}</div>
                      {key !== row.action && (
                        <div className="font-mono text-[11px] text-black/45">{row.action}</div>
                      )}
                    </td>
                    <td className="text-black/60">{entityLabel(row)}</td>
                    <td className="font-mono text-xs">{row.ip_address || '—'}</td>
                  </tr>
                )
              })}
              {rows.length === 0 && (
                <tr>
                  <td colSpan={5}>
                    <EmptyState title={t('audit.empty')} />
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  )
}
