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
import { fetchExcelExport } from '@/lib/excelExport'
import { normalizeWhatsAppPhone, openWhatsAppChat } from '@/lib/phone'
import { Button, Field, Modal, inputClass } from '@/components/ui'

type ShareFormat = CaptureFormat | 'xlsx'

type Props = {
  /** Prefill from customer.phone / supplier.phone */
  defaultPhone?: string
  /** Base file name without extension */
  fileName?: string
  /** Short label used in the WhatsApp draft message */
  documentLabel?: string
  /** Capture from current page (default `.print-area`) */
  captureSelector?: string
  /** When set, open this print route and capture from there */
  printPath?: string
  /** Optional Excel export API path (enables Excel format option) */
  excelPath?: string
  /** Query params for excelPath */
  excelParams?: Record<string, string | number | undefined | null>
  /** Extra lines appended to the WhatsApp draft (e.g. period summary) */
  messageExtra?: string
  variant?: 'primary' | 'secondary'
  className?: string
  disabled?: boolean
  /** Compact text-link style for table rows */
  compact?: boolean
}

async function tryNativeShare(file: File, title: string, text: string): Promise<'shared' | 'cancelled' | 'unavailable'> {
  if (typeof navigator === 'undefined' || typeof navigator.share !== 'function') {
    return 'unavailable'
  }
  const payload: ShareData = { files: [file], title, text }
  try {
    if (typeof navigator.canShare === 'function' && !navigator.canShare(payload)) {
      return 'unavailable'
    }
    await navigator.share(payload)
    return 'shared'
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') return 'cancelled'
    if (e instanceof Error && e.name === 'AbortError') return 'cancelled'
    return 'unavailable'
  }
}

export default function WhatsAppSendButton({
  defaultPhone = '',
  fileName = 'syna-document',
  documentLabel = 'مستند',
  captureSelector = '.print-area',
  printPath,
  excelPath,
  excelParams,
  messageExtra,
  variant = 'secondary',
  className = '',
  disabled = false,
  compact = false,
}: Props) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [phone, setPhone] = useState(defaultPhone ?? '')
  const [format, setFormat] = useState<ShareFormat>('pdf')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [hint, setHint] = useState('')
  const [cloudConfigured, setCloudConfigured] = useState(false)

  useEffect(() => {
    if (open) {
      setPhone(defaultPhone ?? '')
      setFormat('pdf')
      setError('')
      setHint('')
    }
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

  async function prepareFile(): Promise<{ blob: Blob; fileName: string; mimeType: string }> {
    if (format === 'xlsx') {
      if (!excelPath) throw new Error(t('whatsapp.failed'))
      const xlsxName = fileName.endsWith('.xlsx') ? fileName : `${fileName}.xlsx`
      return fetchExcelExport(excelPath, excelParams, xlsxName)
    }

    const captureFormat: CaptureFormat = format === 'png' ? 'png' : 'pdf'
    const localEl = document.querySelector<HTMLElement>(captureSelector)
    const hasLocalPrint =
      !!localEl &&
      (localEl.getAttribute('data-print-ready') === '1' ||
        localEl.innerText.replace(/\s+/g, ' ').trim().length > 20)

    if (hasLocalPrint) {
      return captureSelectorInDocument(document, captureSelector, { format: captureFormat, fileName })
    }
    if (printPath) {
      return captureFromPrintPopup(printPath, { format: captureFormat, fileName })
    }
    return captureSelectorInDocument(document, captureSelector, { format: captureFormat, fileName })
  }

  function buildDraft(file: string, cloudOk: boolean): string {
    const lines = [documentLabel]
    if (messageExtra?.trim()) lines.push(messageExtra.trim())
    if (cloudOk) {
      lines.push(t('whatsapp.draftCloud'))
    } else {
      lines.push(t('whatsapp.draftAttach', { file }))
    }
    lines.push('— Syna Co')
    return lines.join('\n')
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
    setHint(t('whatsapp.preparing'))
    try {
      const captured = await prepareFile()
      const file = new File([captured.blob], captured.fileName, { type: captured.mimeType })

      // Best path on mobile / supported browsers: OS share sheet with the file attached.
      const shareResult = await tryNativeShare(file, documentLabel, buildDraft(captured.fileName, false))
      if (shareResult === 'shared') {
        setHint(t('whatsapp.sentShare'))
        return
      }
      if (shareResult === 'cancelled') {
        setHint('')
        return
      }

      // Always download so the user has the file ready to attach in Desktop/Web.
      downloadBlob(captured.blob, captured.fileName)

      let cloudOk = false
      try {
        cloudOk = await tryCloudSend(captured.blob, captured.fileName, captured.mimeType, normalized)
      } catch {
        cloudOk = false
      }

      const draft = buildDraft(captured.fileName, cloudOk)
      openWhatsAppChat(normalized, draft)

      setHint(cloudOk ? t('whatsapp.sentCloud') : t('whatsapp.sentManual'))
    } catch (e) {
      setHint('')
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
          className={`inline-flex items-center gap-1 whitespace-nowrap text-xs text-teal disabled:opacity-40 ${className}`}
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
            <Button variant="primary" disabled={busy || !(phone ?? '').trim()} onClick={() => void handleSend()}>
              {busy ? t('whatsapp.preparing') : t('whatsapp.confirm')}
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
            {excelPath ? (
              <label className="flex items-center gap-2">
                <input
                  type="radio"
                  name="wa-format"
                  checked={format === 'xlsx'}
                  onChange={() => setFormat('xlsx')}
                  disabled={busy}
                />
                Excel
              </label>
            ) : null}
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
