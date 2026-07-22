import { useEffect, useRef } from 'react'
import { Navigate, useParams, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { StatementPrintView, type PartnerStatementData } from '@/components/StatementPrintView'
import { Button } from '@/components/ui'

type Kind = 'customers' | 'suppliers'

export default function PartnerStatementPrintPage({ kind }: { kind: Kind }) {
  const { id } = useParams<{ id: string }>()
  const [searchParams] = useSearchParams()
  const { t } = useTranslation()
  const { user, loading: authLoading } = useAuth()
  const autoPrinted = useRef(false)
  const partnerId = Number(id)
  const from = searchParams.get('from') || undefined
  const to = searchParams.get('to') || undefined
  const isCustomer = kind === 'customers'
  const documentLabel = isCustomer ? 'كشف حساب عميل' : 'كشف حساب مورد'

  const currencies = useQuery({
    queryKey: ['currencies'],
    enabled: !!user,
    queryFn: async () => (await api.get('/currencies')).data.data as { base_currency: string },
  })
  const base = currencies.data?.base_currency || 'SYP'

  const statement = useQuery({
    queryKey: ['partner-statement-print', kind, partnerId, from, to],
    enabled: !!user && Number.isFinite(partnerId) && partnerId > 0,
    queryFn: async () => {
      const params: Record<string, string> = {}
      if (from) params.from = from
      if (to) params.to = to
      return (await api.get(`/${kind}/${partnerId}/statement`, { params })).data.data as PartnerStatementData
    },
  })

  const partnerName = isCustomer
    ? statement.data?.customer?.name
    : statement.data?.supplier?.name

  useEffect(() => {
    if (partnerName) {
      document.title = `${documentLabel} — ${partnerName} — Syna Co`
    }
  }, [partnerName, documentLabel])

  useEffect(() => {
    if (!statement.data || autoPrinted.current) return
    autoPrinted.current = true
    const timer = window.setTimeout(() => window.print(), 450)
    return () => window.clearTimeout(timer)
  }, [statement.data])

  if (authLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (!Number.isFinite(partnerId) || partnerId <= 0) {
    return <div className="p-8 text-center text-sm text-danger">شريك غير صالح</div>
  }
  if (statement.isLoading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (statement.error || !statement.data) {
    return <div className="p-8 text-center text-sm text-danger">تعذر تحميل كشف الحساب</div>
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
        <p className="text-xs text-black/45">نافذة طباعة كشف الحساب — بدون قائمة التطبيق</p>
      </div>
      <div className="print-area mx-auto max-w-3xl">
        <StatementPrintView
          data={statement.data}
          kind={isCustomer ? 'customer' : 'supplier'}
          currency={base}
          documentLabel={documentLabel}
        />
      </div>
    </div>
  )
}
