import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { FileDown, Upload } from 'lucide-react'
import { Button } from '@/components/ui'
import { downloadExcelExport } from '@/lib/excelExport'
import api from '@/lib/api'

export type PurchaseInvoiceImportLine = {
  row: number
  product_id: number | null
  product_code: string
  product_name: string
  brand?: string | null
  model?: string | null
  sku?: string | null
  quantity: number
  unit_cost?: number | null
  batch_no?: string | null
  serial_no?: string | null
  notes: string
  matched: boolean
  match_reason?: string | null
  warning?: string | null
}

export type PurchaseInvoiceLinesPreview = {
  imported: number
  matched: number
  unmatched: number
  skipped: number
  lines: PurchaseInvoiceImportLine[]
  errors: { row: number; message: string }[]
}

type Props = {
  disabled?: boolean
  /** API path for template download (default: purchase invoice lines). */
  templatePath?: string
  /** API path for Excel preview/import (default: purchase invoice lines). */
  previewPath?: string
  /** Preferred downloaded filename. */
  fileName?: string
  /** i18n namespace for button/hint/error strings (default: purchases). */
  i18nPrefix?: 'purchases' | 'warehouse'
  /** Optional hint override (defaults to `${i18nPrefix}.importModelHint`). */
  hintKey?: string
  onImported?: (result: PurchaseInvoiceLinesPreview) => void
  onError?: (message: string) => void
}

export default function PurchaseInvoiceExcelImportButtons({
  disabled,
  templatePath = '/imports/purchase-invoices/lines/template',
  previewPath = '/imports/purchase-invoices/lines/preview',
  fileName = 'purchase-invoice-lines-template.xlsx',
  i18nPrefix = 'purchases',
  hintKey,
  onImported,
  onError,
}: Props) {
  const { t } = useTranslation()
  const inputRef = useRef<HTMLInputElement>(null)
  const [downloading, setDownloading] = useState(false)
  const [importing, setImporting] = useState(false)
  const hint = hintKey || `${i18nPrefix}.importModelHint`

  async function downloadTemplate() {
    if (downloading || disabled) return
    setDownloading(true)
    try {
      await downloadExcelExport(templatePath, undefined, fileName)
    } catch (err) {
      console.error(err)
      onError?.(t(`${i18nPrefix}.importTemplateFailed`))
    } finally {
      setDownloading(false)
    }
  }

  async function onFileSelected(file: File | undefined) {
    if (!file || importing || disabled) return
    setImporting(true)
    try {
      const form = new FormData()
      form.append('file', file)
      const res = await api.post(previewPath, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      const result = res.data.data as PurchaseInvoiceLinesPreview
      if (!result?.lines?.length) {
        onError?.(res.data.message || t(`${i18nPrefix}.importEmpty`))
        return
      }
      onImported?.(result)
    } catch (err: unknown) {
      const ax = err as {
        response?: {
          data?: {
            message?: string
            data?: PurchaseInvoiceLinesPreview
            errors?: Record<string, string[]>
          }
        }
      }
      const partial = ax.response?.data?.data
      if (partial && Array.isArray(partial.lines) && partial.lines.length > 0) {
        onImported?.(partial)
      } else {
        const first = ax.response?.data?.errors
          ? Object.values(ax.response.data.errors)[0]?.[0]
          : undefined
        onError?.(first || ax.response?.data?.message || t(`${i18nPrefix}.importFailed`))
      }
    } finally {
      setImporting(false)
      if (inputRef.current) inputRef.current.value = ''
    }
  }

  return (
    <div className="flex flex-col items-end gap-1">
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Button
          type="button"
          variant="secondary"
          disabled={disabled || downloading}
          onClick={() => void downloadTemplate()}
        >
          <FileDown size={16} />
          {downloading ? t('common.exporting') : t(`${i18nPrefix}.downloadImportTemplate`)}
        </Button>
        <Button
          type="button"
          variant="secondary"
          disabled={disabled || importing}
          onClick={() => inputRef.current?.click()}
        >
          <Upload size={16} />
          {importing ? t(`${i18nPrefix}.importing`) : t(`${i18nPrefix}.importFromExcel`)}
        </Button>
      </div>
      <p className="max-w-md text-right text-xs text-black/55 dark:text-white/50">{t(hint)}</p>
      <input
        ref={inputRef}
        type="file"
        accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
        className="hidden"
        onChange={(e) => void onFileSelected(e.target.files?.[0])}
      />
    </div>
  )
}
