import { useEffect, useRef } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { SalesInvoicePrintView, type EInvoiceData, type SalesInvoicePrintData } from '@/components/InvoicePrintView'
import { Button } from '@/components/ui'

export default function SalesInvoicePrintPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation()
  const { user, loading: authLoading } = useAuth()
  const autoPrinted = useRef(false)
  const invoiceId = Number(id)

  const invoice = useQuery({
    queryKey: ['sales-invoice-print', invoiceId],
    enabled: !!user && Number.isFinite(invoiceId) && invoiceId > 0,
    queryFn: async () => (await api.get(`/sales-invoices/${invoiceId}`)).data.data as SalesInvoicePrintData,
  })

  const qr = useQuery({
    queryKey: ['sales-invoice-print-qr', invoiceId],
    enabled: !!user && Number.isFinite(invoiceId) && invoiceId > 0,
    queryFn: async () => (await api.get(`/sales-invoices/${invoiceId}/qr`)).data.data as EInvoiceData,
  })

  useEffect(() => {
    if (invoice.data?.invoice_number) {
      document.title = `${invoice.data.invoice_number} — Syna Co`
    }
  }, [invoice.data?.invoice_number])

  useEffect(() => {
    if (!invoice.data || qr.isLoading || autoPrinted.current) return
    autoPrinted.current = true
    const timer = window.setTimeout(() => window.print(), 450)
    return () => window.clearTimeout(timer)
  }, [invoice.data, qr.isLoading])

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
    <div className="print-document min-h-screen bg-white p-6 text-black" dir="rtl">
      <div className="print-hide mb-4 flex flex-wrap items-center gap-2 border-b border-black/10 pb-4">
        <Button variant="primary" onClick={() => window.print()}>
          <Printer size={16} /> {t('common.print')}
        </Button>
        <Button variant="secondary" onClick={() => window.close()}>
          {t('common.close')}
        </Button>
        <p className="text-xs text-black/45">نافذة طباعة الفاتورة — بدون قائمة التطبيق</p>
      </div>
      <div className="print-area mx-auto max-w-3xl">
        <SalesInvoicePrintView invoice={invoice.data} eInvoice={qr.data} />
      </div>
    </div>
  )
}
