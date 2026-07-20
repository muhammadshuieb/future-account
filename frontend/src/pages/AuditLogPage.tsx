import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { EmptyState, LoadingBlock, PageHeader, Panel } from '@/components/ui'

type AuditRow = {
  id: number
  action: string
  entity_type?: string
  entity_id?: number
  ip_address?: string
  created_at: string
  user?: { name: string }
  meta?: Record<string, unknown>
}

export default function AuditLogPage() {
  const { t } = useTranslation()
  const logs = useQuery({
    queryKey: ['audit-logs'],
    queryFn: async () => (await api.get('/audit-logs')).data.data as AuditRow[],
  })

  if (logs.isLoading) return <LoadingBlock />

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
              {(logs.data || []).map((row) => (
                <tr key={row.id}>
                  <td className="whitespace-nowrap font-mono text-xs">{String(row.created_at).slice(0, 19).replace('T', ' ')}</td>
                  <td>{row.user?.name || '—'}</td>
                  <td>{row.action}</td>
                  <td className="text-black/60">
                    {row.entity_type ? `${row.entity_type}${row.entity_id ? ` #${row.entity_id}` : ''}` : '—'}
                  </td>
                  <td className="font-mono text-xs">{row.ip_address || '—'}</td>
                </tr>
              ))}
              {(logs.data || []).length === 0 && (
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
