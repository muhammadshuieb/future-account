import { useEffect } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { PrintInvoicePrintView, type PrintInvoicePrintData } from '@/components/InvoicePrintView'
import PrintDocumentShell from '@/components/PrintDocumentShell'
import { buildDocumentBaseName } from '@/lib/documentFileName'
import { invoiceMessageDetails } from '@/lib/whatsappDraft'

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

  const fileBaseName = buildDocumentBaseName(
    t('documents.printInvoice'),
    invoice.data?.customer?.name,
  )

  useEffect(() => {
    if (invoice.data) {
      document.title = `${fileBaseName} — Syna Co`
    }
  }, [invoice.data, fileBaseName])

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
    <PrintDocumentShell
      hint={t('printInvoices.printPreviewHint')}
      fileName={fileBaseName}
      whatsapp={{
        defaultPhone: invoice.data.customer?.phone,
        documentLabel: t('documents.printInvoice'),
        messageDetails: invoiceMessageDetails(t, {
          partnerName: invoice.data.customer?.name,
          partnerKind: 'customer',
          documentNumber: invoice.data.invoice_number,
        }),
      }}
    >
      <PrintInvoicePrintView invoice={invoice.data} />
    </PrintDocumentShell>
  )
}
