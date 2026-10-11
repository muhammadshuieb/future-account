import { useEffect } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import FtthReportView, { type FtthDailyReport } from '@/components/FtthReportView'
import PdfExportButton from '@/components/PdfExportButton'
import { Button } from '@/components/ui'

export default function FtthDailyReportPrintPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation()
  const { user, loading: authLoading, hasPermission } = useAuth()
  const reportId = Number(id)

  const report = useQuery({
    queryKey: ['ftth-daily-report-print', reportId],
    enabled: !!user && Number.isFinite(reportId) && reportId > 0,
    queryFn: async () => (await api.get(`/ftth/daily-reports/${reportId}`)).data.data as FtthDailyReport,
  })

  useEffect(() => {
    if (report.data) {
      document.title = `تقرير FTTH ${report.data.report_number} — Syna Co`
    }
  }, [report.data])

  if (authLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (!user) return <Navigate to="/login" replace />
  if (!hasPermission('ftth.view')) {
    return <div className="p-8 text-center text-sm text-danger">{t('ftth.noPermission')}</div>
  }
  if (!Number.isFinite(reportId) || reportId <= 0) {
    return <div className="p-8 text-center text-sm text-danger">{t('ftth.invalid')}</div>
  }
  if (report.isLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (report.error || !report.data) {
    return <div className="p-8 text-center text-sm text-danger">{t('ftth.loadFailed')}</div>
  }

  return (
    <div className="print-document min-h-0 p-4 text-black" dir="rtl">
      <div className="print-hide mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-black/10 bg-white p-3">
        <Button variant="primary" onClick={() => window.print()}>
          <Printer size={16} /> {t('common.print')}
        </Button>
        <PdfExportButton fileName={`FTTH-${report.data.report_number}`} />
        <Button variant="secondary" onClick={() => window.close()}>
          {t('common.close')}
        </Button>
        <p className="text-xs text-black/45">{t('ftth.printPreviewHint')}</p>
      </div>
      <div className="print-sheet">
        <div className="print-area" data-print-ready="1">
          <FtthReportView report={report.data} />
        </div>
      </div>
    </div>
  )
}
