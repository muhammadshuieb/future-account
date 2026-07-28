import { useState, type MouseEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { FileDown } from 'lucide-react'
import { Button } from '@/components/ui'
import { exportMergedPdfFromPrintPaths, exportPdf } from '@/lib/documentCapture'

type SingleProps = {
  /** Base file name without extension */
  fileName?: string
  /** Capture from current page (default `.print-area`) */
  captureSelector?: string
  /** When set (and no local print area), open this print route in a hidden iframe */
  printPath?: string
  /** Merge several print routes into one PDF */
  printPaths?: never
  disabled?: boolean
  className?: string
  label?: string
  variant?: 'primary' | 'secondary' | 'ghost'
  compact?: boolean
}

type BulkProps = {
  fileName?: string
  captureSelector?: never
  printPath?: never
  printPaths: string[]
  disabled?: boolean
  className?: string
  label?: string
  variant?: 'primary' | 'secondary' | 'ghost'
  compact?: boolean
}

type Props = SingleProps | BulkProps

export default function PdfExportButton(props: Props) {
  const {
    fileName = 'syna-document',
    disabled = false,
    className = '',
    label,
    variant = 'secondary',
    compact = false,
  } = props
  const { t } = useTranslation()
  const [busy, setBusy] = useState(false)

  async function onClick(e?: MouseEvent) {
    e?.stopPropagation()
    if (busy || disabled) return
    setBusy(true)
    try {
      if ('printPaths' in props && props.printPaths) {
        if (props.printPaths.length === 0) {
          window.alert(t('common.exportPdfEmpty'))
          return
        }
        const max = 40
        const paths = props.printPaths.slice(0, max)
        if (props.printPaths.length > max) {
          window.alert(t('common.exportPdfLimited', { max, total: props.printPaths.length }))
        }
        await exportMergedPdfFromPrintPaths(paths, { fileName })
      } else {
        await exportPdf({
          fileName,
          captureSelector: props.captureSelector,
          printPath: props.printPath,
        })
      }
    } catch (err) {
      console.error(err)
      window.alert(err instanceof Error ? err.message : t('common.exportPdfFailed'))
    } finally {
      setBusy(false)
    }
  }

  const text = busy ? t('common.exportingPdf') : (label || t('common.exportPdf'))

  if (compact) {
    return (
      <button
        type="button"
        className={`inline-flex items-center gap-1 text-teal disabled:opacity-40 ${className}`}
        disabled={disabled || busy}
        onClick={(e) => void onClick(e)}
      >
        <FileDown size={14} /> {text}
      </button>
    )
  }

  return (
    <Button
      type="button"
      variant={variant}
      className={className}
      disabled={disabled || busy}
      onClick={() => void onClick()}
    >
      <FileDown size={16} /> {text}
    </Button>
  )
}
