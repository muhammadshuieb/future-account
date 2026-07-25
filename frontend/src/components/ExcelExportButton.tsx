import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { FileSpreadsheet } from 'lucide-react'
import { Button } from '@/components/ui'
import { downloadExcelExport } from '@/lib/excelExport'

type Props = {
  path: string
  params?: Record<string, string | number | undefined | null>
  fileName?: string
  disabled?: boolean
  className?: string
  label?: string
  variant?: 'secondary' | 'ghost' | 'primary'
}

export default function ExcelExportButton({
  path,
  params,
  fileName,
  disabled,
  className,
  label,
  variant = 'secondary',
}: Props) {
  const { t } = useTranslation()
  const [busy, setBusy] = useState(false)

  async function onClick() {
    if (busy || disabled) return
    setBusy(true)
    try {
      await downloadExcelExport(path, params, fileName)
    } catch (err) {
      console.error(err)
      window.alert(t('common.exportFailed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Button
      type="button"
      variant={variant}
      className={className}
      disabled={disabled || busy}
      onClick={() => void onClick()}
    >
      <FileSpreadsheet size={16} />
      {busy ? t('common.exporting') : (label || t('common.exportExcel'))}
    </Button>
  )
}
