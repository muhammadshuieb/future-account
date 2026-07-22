import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MessageCircle } from 'lucide-react'
import api from '@/lib/api'
import {
  captureFromPrintPopup,
  captureSelectorInDocument,
  downloadBlob,
  type CaptureFormat,
} from '@/lib/documentCapture'
import { normalizeWhatsAppPhone, whatsAppChatUrl } from '@/lib/phone'
import { Button, Field, Modal, inputClass } from '@/components/ui'

type Props = {
  /** Prefill from customer.phone / supplier.phone */
  defaultPhone?: string
  /** Base file name without extension */
  fileName?: string
  /** Short Arabic label used in the WhatsApp draft message */
  documentLabel?: string
  /** Capture from current page (default `.print-area`) */
  captureSelector?: string
  /** When set, open this print route in a popup and capture from there */
  printPath?: string
  variant?: 'primary' | 'secondary'
  className?: string
  disabled?: boolean
  /** Compact text-link style for table rows */
  compact?: boolean
}

export default function WhatsAppSendButton({
  defaultPhone = '',
  fileName = 'syna-document',
  documentLabel = 'مستند',
  captureSelector = '.print-area',
  printPath,
  variant = 'secondary',
  className = '',
  disabled = false,
  compact = false,
}: Props) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [phone, setPhone] = useState(defaultPhone)
  const [format, setFormat] = useState<CaptureFormat>('pdf')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [hint, setHint] = useState('')
  const [cloudConfigured, setCloudConfigured] = useState(false)

  useEffect(() => {
    if (open) setPhone(defaultPhone || '')
  }, [open, defaultPhone])

  useEffect(() => {
    if (!open) return
    let cancelled = false
    void api
      .get('/whatsapp/status')
      .then((res) => {
        if (!cancelled) setCloudConfigured(Boolean(res.data?.data?.configured))
      })
      .catch(() => {
        if (!cancelled) setCloudConfigured(false)
      })
    return () => {
      cancelled = true
    }
  }, [open])

  async function tryCloudSend(blob: Blob, name: string, mime: string, to: string) {
    if (!cloudConfigured) return false
    const form = new FormData()
    form.append('phone', to)
    form.append('caption', `${documentLabel} — Syna Co`)
    form.append('file', blob, name)
    form.append('mime_type', mime)
    await api.post('/whatsapp/send', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return true
  }

  async function handleSend() {
    setError('')
    setHint('')
    const normalized = normalizeWhatsAppPhone(phone)
    if (!normalized) {
      setError(t('whatsapp.invalidPhone'))
      return
    }

    setBusy(true)
    try {
      const captured = printPath
        ? await captureFromPrintPopup(printPath, { format, fileName })
        : await captureSelectorInDocument(document, captureSelector, { format, fileName })

      downloadBlob(captured.blob, captured.fileName)

      let cloudOk = false
      try {
        cloudOk = await tryCloudSend(captured.blob, captured.fileName, captured.mimeType, normalized)
      } catch {
        cloudOk = false
      }

      const draft = cloudOk
        ? `${documentLabel} — تم الإرسال تلقائياً من Syna Co`
        : `${documentLabel} — يرجى إرفاق الملف الذي تم تنزيله (${captured.fileName})`
      const url = whatsAppChatUrl(normalized, draft)
      if (url) window.open(url, '_blank', 'noopener,noreferrer')

      setHint(
        cloudOk
          ? t('whatsapp.sentCloud')
          : t('whatsapp.sentManual'),
      )
    } catch (e) {
      setError(e instanceof Error ? e.message : t('whatsapp.failed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      {compact ? (
        <button
          type="button"
          className={`inline-flex items-center gap-1 text-teal disabled:opacity-40 ${className}`}
          disabled={disabled}
          onClick={(e) => {
            e.stopPropagation()
            setOpen(true)
          }}
        >
          <MessageCircle size={14} /> {t('whatsapp.button')}
        </button>
      ) : (
        <Button
          variant={variant}
          className={className}
          disabled={disabled}
          onClick={() => setOpen(true)}
        >
          <MessageCircle size={16} /> {t('whatsapp.button')}
        </Button>
      )}

      <Modal
        open={open}
        onClose={() => !busy && setOpen(false)}
        title={t('whatsapp.title')}
        size="md"
        footer={
          <>
            <Button variant="secondary" disabled={busy} onClick={() => setOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" disabled={busy || !phone.trim()} onClick={() => void handleSend()}>
              {busy ? t('whatsapp.sending') : t('whatsapp.confirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-4 text-sm">
          <Field label={t('whatsapp.phone')} hint={t('whatsapp.phoneHint')}>
            <input
              className={inputClass}
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              inputMode="tel"
              placeholder="09xxxxxxxx"
              disabled={busy}
            />
          </Field>

          <fieldset className="space-y-2">
            <legend className="mb-1.5 block text-sm font-medium text-black/65">{t('whatsapp.format')}</legend>
            <label className="flex items-center gap-2">
              <input
                type="radio"
                name="wa-format"
                checked={format === 'pdf'}
                onChange={() => setFormat('pdf')}
                disabled={busy}
              />
              PDF
            </label>
            <label className="flex items-center gap-2">
              <input
                type="radio"
                name="wa-format"
                checked={format === 'png'}
                onChange={() => setFormat('png')}
                disabled={busy}
              />
              {t('whatsapp.imagePng')}
            </label>
          </fieldset>

          <p className="rounded-lg border border-black/10 bg-mist/60 px-3 py-2 text-xs text-black/65">
            {cloudConfigured ? t('whatsapp.cloudReady') : t('whatsapp.manualHint')}
          </p>

          {error && <p className="text-sm text-danger">{error}</p>}
          {hint && <p className="text-sm text-success">{hint}</p>}
        </div>
      </Modal>
    </>
  )
}
