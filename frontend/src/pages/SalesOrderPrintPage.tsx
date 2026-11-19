import { useEffect } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { SalesOrderPrintView, type SalesOrderPrintData } from '@/components/InvoicePrintView'
import PrintDocumentShell from '@/components/PrintDocumentShell'
import { buildDocumentBaseName } from '@/lib/documentFileName'
import { invoiceMessageDetails } from '@/lib/whatsappDraft'

export default function SalesOrderPrintPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation()
  const { user, loading: authLoading, hasPermission } = useAuth()
  const orderId = Number(id)

  const order = useQuery({
    queryKey: ['sales-order-print', orderId],
    enabled: !!user && Number.isFinite(orderId) && orderId > 0,
    queryFn: async () => (await api.get(`/sales-orders/${orderId}`)).data.data as SalesOrderPrintData,
  })

  const fileBaseName = buildDocumentBaseName(
    t('documents.salesOrder'),
    order.data?.customer?.name,
  )

  useEffect(() => {
    if (order.data) {
      document.title = `${fileBaseName} — Syna Co`
    }
  }, [order.data, fileBaseName])

  if (authLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (!hasPermission('sales.view')) {
    return <div className="p-8 text-center text-sm text-danger">{t('sales.noPermission')}</div>
  }
  if (!Number.isFinite(orderId) || orderId <= 0) {
    return <div className="p-8 text-center text-sm text-danger">{t('sales.invalidOrder')}</div>
  }
  if (order.isLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (order.error || !order.data) {
    return <div className="p-8 text-center text-sm text-danger">{t('sales.loadOrderFailed')}</div>
  }

  return (
    <PrintDocumentShell
      hint={t('sales.printPreviewHint')}
      fileName={fileBaseName}
      whatsapp={{
        defaultPhone: order.data.customer?.phone,
        documentLabel: t('documents.salesOrder'),
        messageDetails: invoiceMessageDetails(t, {
          partnerName: order.data.customer?.name,
          partnerKind: 'customer',
          documentNumber: order.data.order_number,
          numberLabel: t('whatsapp.orderNumber'),
        }),
      }}
    >
      <SalesOrderPrintView order={order.data} />
    </PrintDocumentShell>
  )
}
