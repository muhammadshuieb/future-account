import { useEffect } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { PurchaseInvoicePrintView, type PurchaseInvoicePrintData } from '@/components/InvoicePrintView'
import PrintDocumentShell from '@/components/PrintDocumentShell'
import { buildDocumentBaseName } from '@/lib/documentFileName'
import { invoiceMessageDetails } from '@/lib/whatsappDraft'

export default function PurchaseInvoicePrintPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation()
  const { user, loading: authLoading } = useAuth()
  const invoiceId = Number(id)

  const invoice = useQuery({
    queryKey: ['purchase-invoice-print', invoiceId],
    enabled: !!user && Number.isFinite(invoiceId) && invoiceId > 0,
    queryFn: async () => (await api.get(`/purchase-invoices/${invoiceId}`)).data.data as PurchaseInvoicePrintData,
  })

  const fileBaseName = buildDocumentBaseName(
    t('documents.purchaseInvoice'),
    invoice.data?.supplier?.name,
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
  if (!Number.isFinite(invoiceId) || invoiceId <= 0) {
    return <div className="p-8 text-center text-sm text-danger">فاتورة غير صالحة</div>
  }
  if (invoice.isLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (invoice.error || !invoice.data) {
    return <div className="p-8 text-center text-sm text-danger">تعذر تحميل الفاتورة</div>
  }

  return (
    <PrintDocumentShell
      hint="معاينة A4 — نافذة طباعة فاتورة المشتريات"
      fileName={fileBaseName}
      whatsapp={{
        defaultPhone: invoice.data.supplier?.phone,
        documentLabel: t('documents.purchaseInvoice'),
        messageDetails: invoiceMessageDetails(t, {
          partnerName: invoice.data.supplier?.name,
          partnerKind: 'supplier',
          documentNumber: invoice.data.invoice_number,
        }),
      }}
    >
      <PurchaseInvoicePrintView invoice={invoice.data} />
    </PrintDocumentShell>
  )
}
