import { useEffect } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { SalesQuotePrintView, type SalesQuotePrintData } from '@/components/InvoicePrintView'
import PrintDocumentShell from '@/components/PrintDocumentShell'
import { buildDocumentBaseName } from '@/lib/documentFileName'
import { invoiceMessageDetails } from '@/lib/whatsappDraft'

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

  const fileBaseName = buildDocumentBaseName(
    t('documents.priceQuote'),
    quote.data?.customer?.name,
  )

  useEffect(() => {
    if (quote.data) {
      document.title = `${fileBaseName} — Syna Co`
    }
  }, [quote.data, fileBaseName])

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
    <PrintDocumentShell
      hint={t('quotes.printPreviewHint')}
      fileName={fileBaseName}
      whatsapp={{
        defaultPhone: quote.data.customer?.phone,
        documentLabel: t('documents.priceQuote'),
        messageDetails: invoiceMessageDetails(t, {
          partnerName: quote.data.customer?.name,
          partnerKind: 'customer',
          documentNumber: quote.data.quote_number,
          numberLabel: t('whatsapp.quoteNumber'),
        }),
      }}
    >
      <SalesQuotePrintView quote={quote.data} />
    </PrintDocumentShell>
  )
}
