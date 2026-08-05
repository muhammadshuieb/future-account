import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { Button, EmptyState, Modal, Msg, PageHeader, Panel, Tabs, useFormMessage } from '@/components/ui'
import { formatDateTimeLocal } from '@/lib/dates'

type Approval = {
  id: number
  action_type: string
  status: 'pending' | 'approved' | 'rejected'
  before_payload?: Record<string, unknown> | null
  after_payload: Record<string, unknown>
  review_comment?: string | null
  created_at: string
  reviewed_at?: string | null
  requester?: { id: number; name: string }
  reviewer?: { id: number; name: string }
  warehouse?: { id: number; name: string }
  review_context?: {
    source_warehouse?: { id: number; name: string; code: string }
    target_warehouse?: { id: number; name: string; code: string }
    lines: {
      product?: { id: number; name: string; sku: string }
      quantity: number
      batch_no?: string | null
      serial_no?: string | null
      source_current_stock: number
      target_current_stock: number
    }[]
  }
}

export default function WarehouseApprovalsPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const qc = useQueryClient()
  const msg = useFormMessage()
  const [status, setStatus] = useState<'pending' | 'approved' | 'rejected'>('pending')
  const [selected, setSelected] = useState<Approval | null>(null)
  const canReview = hasPermission('warehouse.approvals.review')

  const approvals = useQuery({
    queryKey: ['warehouse-approvals', status],
    queryFn: async () => (await api.get('/warehouse-approvals', { params: { status } })).data.data as {
      items: Approval[]
      pending_count: number
    },
  })

  const review = useMutation({
    mutationFn: async ({ id, decision }: { id: number; decision: 'approve' | 'reject' }) => {
      let comment = ''
      if (decision === 'reject') {
        comment = window.prompt(t('approvals.rejectionReason'))?.trim() || ''
        if (!comment) throw new Error(t('approvals.reasonRequired'))
      } else {
        comment = window.prompt(t('approvals.optionalComment'))?.trim() || ''
      }
      return api.post(`/warehouse-approvals/${id}/${decision}`, { comment: comment || undefined })
    },
    onSuccess: () => {
      msg.setMessage(t('approvals.reviewSaved'))
      setSelected(null)
      void qc.invalidateQueries({ queryKey: ['warehouse-approvals'] })
      void qc.invalidateQueries({ queryKey: ['notifications'] })
      void qc.invalidateQueries({ queryKey: ['warehouses'] })
      void qc.invalidateQueries({ queryKey: ['products'] })
      void qc.invalidateQueries({ queryKey: ['stock-levels'] })
    },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-5">
      <PageHeader title={t('approvals.title')} subtitle={canReview ? t('approvals.adminSubtitle') : t('approvals.managerSubtitle')} />
      <Tabs
        tabs={[
          { id: 'pending', label: t('status.pending') },
          { id: 'approved', label: t('status.approved') },
          { id: 'rejected', label: t('status.rejected') },
        ]}
        active={status}
        onChange={(id) => setStatus(id as typeof status)}
      />
      <Msg message={msg.message} error={msg.error} />
      <Panel>
        {!approvals.isLoading && !(approvals.data?.items || []).length && <EmptyState title={t('approvals.empty')} />}
        <div className="table-wrap">
          <table className="data-table text-sm">
            <thead><tr><th>#</th><th>{t('approvals.requester')}</th><th>{t('common.warehouse')}</th><th>{t('approvals.action')}</th><th>{t('common.date')}</th><th>{t('common.status')}</th></tr></thead>
            <tbody>
              {(approvals.data?.items || []).map((row) => (
                <tr key={row.id} className="row-clickable" onClick={() => setSelected(row)}>
                  <td>{row.id}</td>
                  <td>{row.requester?.name || '—'}</td>
                  <td>{row.warehouse?.name || '—'}</td>
                  <td>{t(`approvals.actions.${row.action_type}`, { defaultValue: row.action_type })}</td>
                  <td>{formatDateTimeLocal(row.created_at)}</td>
                  <td>{t(`status.${row.status}`)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>

      <Modal
        open={!!selected}
        onClose={() => setSelected(null)}
        title={selected ? `${t('approvals.request')} #${selected.id}` : ''}
        size="lg"
        footer={selected ? <>
          <Button variant="secondary" onClick={() => setSelected(null)}>{t('common.close')}</Button>
          {canReview && selected.status === 'pending' && <>
            <Button variant="danger" disabled={review.isPending} onClick={() => review.mutate({ id: selected.id, decision: 'reject' })}>{t('approvals.reject')}</Button>
            <Button variant="primary" disabled={review.isPending} onClick={() => review.mutate({ id: selected.id, decision: 'approve' })}>{t('approvals.approve')}</Button>
          </>}
        </> : undefined}
      >
        {selected && (
          <div className="space-y-4 text-sm">
            <div className="grid gap-2 sm:grid-cols-2">
              <p><span className="text-black/45">{t('approvals.requester')}:</span> {selected.requester?.name}</p>
              <p><span className="text-black/45">{t('common.warehouse')}:</span> {selected.warehouse?.name}</p>
            </div>
            {selected.review_context && <TransferReviewContext context={selected.review_context} />}
            <div className="grid gap-4 lg:grid-cols-2">
              <Payload title={t('approvals.before')} value={selected.before_payload} />
              <Payload title={t('approvals.after')} value={selected.after_payload} />
            </div>
            {selected.review_comment && <p className="rounded-lg bg-mist p-3">{selected.review_comment}</p>}
          </div>
        )}
      </Modal>
    </div>
  )
}

function TransferReviewContext({ context }: { context: NonNullable<Approval['review_context']> }) {
  return (
    <div className="space-y-3 rounded-lg border border-black/10 bg-mist p-4">
      <div className="grid gap-2 sm:grid-cols-2">
        <p><span className="text-black/45">من المخزن:</span> {context.source_warehouse?.code} — {context.source_warehouse?.name}</p>
        <p><span className="text-black/45">إلى المخزن:</span> {context.target_warehouse?.code} — {context.target_warehouse?.name}</p>
      </div>
      <div className="table-wrap">
        <table className="data-table text-xs">
          <thead><tr><th>الصنف</th><th>الكمية</th><th>دفعة/تسلسلي</th><th>الرصيد الحالي بالمصدر</th><th>الرصيد الحالي بالهدف</th></tr></thead>
          <tbody>
            {context.lines.map((line, index) => (
              <tr key={`${line.product?.id ?? index}-${index}`}>
                <td>{line.product?.sku} — {line.product?.name}</td>
                <td>{line.quantity}</td>
                <td>{line.batch_no || line.serial_no || '—'}</td>
                <td>{line.source_current_stock}</td>
                <td>{line.target_current_stock}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function Payload({ title, value }: { title: string; value?: Record<string, unknown> | null }) {
  return (
    <div>
      <h3 className="mb-2 font-semibold">{title}</h3>
      <pre className="max-h-96 overflow-auto rounded-lg bg-ink p-3 text-left text-xs text-white" dir="ltr">
        {value ? JSON.stringify(value, null, 2) : '—'}
      </pre>
    </div>
  )
}
