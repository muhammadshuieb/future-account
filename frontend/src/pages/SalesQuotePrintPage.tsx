import { useEffect } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { SalesQuotePrintView, type SalesQuotePrintData } from '@/components/InvoicePrintView'
import WhatsAppSendButton from '@/components/WhatsAppSendButton'
import PdfExportButton from '@/components/PdfExportButton'
import { Button } from '@/components/ui'

export default function SalesQuotePrintPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation()
  const { user, loading: authLoading, hasPermission } = useAuth()
  const quoteId = Number(id)

  const quote = useQuery({
    queryKey: ['sales-quote-print', quoteId],
    enabled: !!user && Number.isFinite(quoteId) && quoteId > 0,
    queryFn: async () => (await api.get(`/sales-quotes/${quoteId}`)).data.data as SalesQuotePrintData,
  })

  useEffect(() => {
    if (quote.data?.quote_number) {
      document.title = `${quote.data.quote_number} — Syna Co`
    }
  }, [quote.data?.quote_number])

  if (authLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (!hasPermission('quotes.view') && !hasPermission('sales.view')) {
    return <div className="p-8 text-center text-sm text-danger">{t('quotes.noPermission')}</div>
  }
  if (!Number.isFinite(quoteId) || quoteId <= 0) {
    return <div className="p-8 text-center text-sm text-danger">{t('quotes.invalid')}</div>
  }
  if (quote.isLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (quote.error || !quote.data) {
    return <div className="p-8 text-center text-sm text-danger">{t('quotes.loadFailed')}</div>
  }

  return (
    <div className="print-document min-h-0 p-4 text-black" dir="rtl">
      <div className="print-hide mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-black/10 bg-white p-3">
        <Button variant="primary" onClick={() => window.print()}>
          <Printer size={16} /> {t('common.print')}
        </Button>
        <PdfExportButton fileName={quote.data.quote_number || `price-quote-${quoteId}`} />
        <WhatsAppSendButton
          defaultPhone={quote.data.customer?.phone}
          fileName={quote.data.quote_number || `price-quote-${quoteId}`}
          documentLabel={`${t('quotes.documentTitle')} ${quote.data.quote_number || ''}`}
        />
        <Button variant="secondary" onClick={() => window.close()}>
          {t('common.close')}
        </Button>
        <p className="text-xs text-black/45">{t('quotes.printPreviewHint')}</p>
      </div>
      <div className="print-sheet">
        <div className="print-area" data-print-ready="1">
          <SalesQuotePrintView quote={quote.data} />
        </div>
      </div>
    </div>
  )
}
