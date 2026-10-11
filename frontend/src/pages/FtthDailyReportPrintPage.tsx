import { useEffect } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import FtthReportView, { type FtthDailyReport } from '@/components/FtthReportView'
import PrintDocumentShell from '@/components/PrintDocumentShell'

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
    <PrintDocumentShell
      hint={t('ftth.printPreviewHint')}
      fileName={`FTTH-${report.data.report_number}`}
    >
      <FtthReportView report={report.data} />
    </PrintDocumentShell>
  )
}
