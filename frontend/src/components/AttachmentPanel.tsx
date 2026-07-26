import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileImage, Paperclip, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { downloadBlob } from '@/lib/documentCapture'
import { Button, Field, inputClass, useFormMessage } from '@/components/ui'

export type AttachmentRow = {
  id: number
  original_name: string
  mime_type?: string | null
  size?: number
}

type Props = {
  attachableType: string
  attachableId: number | null | undefined
  canManage?: boolean
}

export async function uploadAttachment(attachableType: string, attachableId: number, file: File) {
  const form = new FormData()
  form.append('attachable_type', attachableType)
  form.append('attachable_id', String(attachableId))
  form.append('file', file)
  return api.post('/attachments', form, {
    headers: { 'Content-Type': undefined as unknown as string },
    transformRequest: [(data, headers) => {
      if (headers && typeof headers === 'object') {
        delete (headers as Record<string, unknown>)['Content-Type']
      }
      return data
    }],
  })
}

export function AttachmentIcon({ count }: { count?: number | null }) {
  if (!count || count < 1) return null
  return <Paperclip size={14} className="inline text-teal" title={`${count}`} aria-label="attachment" />
}

export function PendingAttachmentField({
  file,
  onChange,
}: {
  file: File | null
  onChange: (file: File | null) => void
}) {
  const { t } = useTranslation()
  return (
    <Field label={t('common.attachment')} hint={t('common.attachmentHint')}>
      <input
        type="file"
        accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf"
        className={inputClass}
        onChange={(e) => onChange(e.target.files?.[0] || null)}
      />
      {file && <p className="mt-1 text-xs text-black/55">{file.name}</p>}
    </Field>
  )
}

export default function AttachmentPanel({ attachableType, attachableId, canManage = true }: Props) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const msg = useFormMessage()
  const enabled = !!attachableId

  const list = useQuery({
    queryKey: ['attachments', attachableType, attachableId],
    queryFn: async () =>
      (await api.get('/attachments', {
        params: { attachable_type: attachableType, attachable_id: attachableId },
      })).data.data as AttachmentRow[],
    enabled,
  })

  const upload = useMutation({
    mutationFn: (file: File) => uploadAttachment(attachableType, Number(attachableId), file),
    onSuccess: () => {
      msg.setMessage(t('common.attachmentUploaded'))
      void qc.invalidateQueries({ queryKey: ['attachments', attachableType, attachableId] })
    },
    onError: msg.fromErr,
  })

  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/attachments/${id}`),
    onSuccess: () => {
      msg.setMessage(t('common.attachmentDeleted'))
      void qc.invalidateQueries({ queryKey: ['attachments', attachableType, attachableId] })
    },
    onError: msg.fromErr,
  })

  async function download(row: AttachmentRow) {
    const res = await api.get(`/attachments/${row.id}/download`, { responseType: 'blob' })
    const blob = res.data instanceof Blob ? res.data : new Blob([res.data])
    downloadBlob(blob, row.original_name)
  }

  if (!enabled) return null

  const rows = list.data || []

  return (
    <div className="space-y-2 rounded-md border border-black/10 p-3">
      <div className="flex items-center gap-2 text-sm font-medium">
        <FileImage size={16} /> {t('common.attachments')}
      </div>
      {msg.message || msg.error ? (
        <p className={`text-xs ${msg.error ? 'text-danger' : 'text-teal'}`}>{msg.error || msg.message}</p>
      ) : null}
      {list.isLoading && <p className="text-xs text-black/50">{t('common.loading')}</p>}
      {rows.length === 0 && !list.isLoading && (
        <p className="text-xs text-black/50">{t('common.noAttachments')}</p>
      )}
      <ul className="space-y-1">
        {rows.map((row) => (
          <li key={row.id} className="flex items-center justify-between gap-2 text-sm">
            <button type="button" className="truncate text-teal" onClick={() => void download(row)}>
              {row.original_name}
            </button>
            {canManage && (
              <button
                type="button"
                className="text-rose-600"
                title={t('common.delete')}
                onClick={() => {
                  if (window.confirm(t('common.confirmDelete'))) remove.mutate(row.id)
                }}
              >
                <Trash2 size={14} />
              </button>
            )}
          </li>
        ))}
      </ul>
      {canManage && (
        <div className="flex flex-wrap items-center gap-2">
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf"
            className={inputClass}
            onChange={(e) => {
              const file = e.target.files?.[0]
              if (file) upload.mutate(file)
              e.target.value = ''
            }}
          />
          {upload.isPending && <Button variant="secondary" disabled>{t('common.uploading')}</Button>}
        </div>
      )}
    </div>
  )
}
