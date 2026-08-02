import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { FileDown, Upload } from 'lucide-react'
import { Button } from '@/components/ui'
import { downloadExcelExport } from '@/lib/excelExport'
import api from '@/lib/api'

export type ProductImportResult = {
  imported: number
  failed: number
  total_rows: number
  products: { id: number; sku: string; name: string }[]
  errors: { row: number; message: string }[]
}

type Props = {
  disabled?: boolean
  onImported?: (result: ProductImportResult) => void
  onError?: (message: string) => void
}

export default function ProductExcelImportButtons({ disabled, onImported, onError }: Props) {
  const { t } = useTranslation()
  const inputRef = useRef<HTMLInputElement>(null)
  const [downloading, setDownloading] = useState(false)
  const [importing, setImporting] = useState(false)

  async function downloadTemplate() {
    if (downloading || disabled) return
    setDownloading(true)
    try {
      await downloadExcelExport('/imports/products/template', undefined, 'قالب-استيراد-الأصناف.xlsx')
    } catch (err) {
      console.error(err)
      onError?.(t('warehouse.importTemplateFailed'))
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
      const res = await api.post('/imports/products', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      const result = res.data.data as ProductImportResult
      onImported?.(result)
    } catch (err: unknown) {
      const ax = err as {
        response?: {
          data?: {
            message?: string
            data?: ProductImportResult
            errors?: Record<string, string[]>
          }
          status?: number
        }
      }
      const partial = ax.response?.data?.data
      if (partial && typeof partial.imported === 'number') {
        onImported?.(partial)
      } else {
        const first = ax.response?.data?.errors
          ? Object.values(ax.response.data.errors)[0]?.[0]
          : undefined
        onError?.(first || ax.response?.data?.message || t('warehouse.importFailed'))
      }
    } finally {
      setImporting(false)
      if (inputRef.current) inputRef.current.value = ''
    }
  }

  return (
    <>
      <Button
        type="button"
        variant="secondary"
        disabled={disabled || downloading}
        onClick={() => void downloadTemplate()}
      >
        <FileDown size={16} />
        {downloading ? t('common.exporting') : t('warehouse.downloadImportTemplate')}
      </Button>
      <Button
        type="button"
        variant="secondary"
        disabled={disabled || importing}
        onClick={() => inputRef.current?.click()}
      >
        <Upload size={16} />
        {importing ? t('warehouse.importing') : t('warehouse.importFromExcel')}
      </Button>
      <input
        ref={inputRef}
        type="file"
        accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
        className="hidden"
        onChange={(e) => void onFileSelected(e.target.files?.[0])}
      />
    </>
  )
}
