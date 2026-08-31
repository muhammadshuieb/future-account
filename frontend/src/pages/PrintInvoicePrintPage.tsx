import { useEffect } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { PrintInvoicePrintView, type PrintInvoicePrintData } from '@/components/InvoicePrintView'
import WhatsAppSendButton from '@/components/WhatsAppSendButton'
import PdfExportButton from '@/components/PdfExportButton'
import { Button } from '@/components/ui'

export default function PrintInvoicePrintPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation()
  const { user, loading: authLoading, hasPermission } = useAuth()
  const invoiceId = Number(id)

  const invoice = useQuery({
    queryKey: ['print-invoice-print', invoiceId],
    enabled: !!user && Number.isFinite(invoiceId) && invoiceId > 0,
    queryFn: async () => (await api.get(`/print-invoices/${invoiceId}`)).data.data as PrintInvoicePrintData,
  })

  useEffect(() => {
    if (invoice.data?.invoice_number) {
      document.title = `${invoice.data.invoice_number} — Syna Co`
    }
  }, [invoice.data?.invoice_number])

  if (authLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (!hasPermission('print_invoices.view') && !hasPermission('sales.view')) {
    return <div className="p-8 text-center text-sm text-danger">{t('printInvoices.noPermission')}</div>
  }
  if (!Number.isFinite(invoiceId) || invoiceId <= 0) {
    return <div className="p-8 text-center text-sm text-danger">{t('printInvoices.invalid')}</div>
  }
  if (invoice.isLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (invoice.error || !invoice.data) {
    return <div className="p-8 text-center text-sm text-danger">{t('printInvoices.loadFailed')}</div>
  }

  return (
    <div className="print-document min-h-0 p-4 text-black" dir="rtl">
      <div className="print-hide mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-black/10 bg-white p-3">
        <Button variant="primary" onClick={() => window.print()}>
          <Printer size={16} /> {t('common.print')}
        </Button>
        <PdfExportButton fileName={invoice.data.invoice_number || `print-invoice-${invoiceId}`} />
        <WhatsAppSendButton
          defaultPhone={invoice.data.customer?.phone}
          fileName={invoice.data.invoice_number || `print-invoice-${invoiceId}`}
          documentLabel={`${t('printInvoices.documentTitle')} ${invoice.data.invoice_number || ''}`}
        />
        <Button variant="secondary" onClick={() => window.close()}>
          {t('common.close')}
        </Button>
        <p className="text-xs text-black/45">{t('printInvoices.printPreviewHint')}</p>
      </div>
      <div className="print-sheet">
        <div className="print-area" data-print-ready="1">
          <PrintInvoicePrintView invoice={invoice.data} />
        </div>
      </div>
    </div>
  )
}
