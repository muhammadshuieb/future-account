import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AlertTriangle, ArrowLeftRight, Package, Users } from 'lucide-react'
import api from '@/lib/api'
import type { DashboardSummary } from '@/types'
import { EmptyState, LoadingBlock, Panel, StatTile, formatMoney } from '@/components/ui'

function DailyBarChart({ title, data, currency }: { title: string; data: { date: string; total: number }[]; currency: string }) {
  const max = Math.max(...data.map((d) => d.total), 1)

  return (
    <Panel>
      <div className="border-b border-[var(--color-line)] px-5 py-3">
        <h2 className="font-semibold">{title}</h2>
      </div>
      <div className="flex h-44 items-end gap-1 p-4">
        {data.map((d) => (
          <div key={d.date} className="flex min-w-0 flex-1 flex-col items-center gap-1">
            <div
              className="w-full rounded-t bg-teal/80 transition-all"
              style={{ height: `${Math.max(4, (d.total / max) * 100)}%` }}
              title={`${d.date}: ${d.total} ${currency}`}
            />
            <span className="truncate text-[9px] text-black/45">{d.date.slice(5)}</span>
          </div>
        ))}
      </div>
    </Panel>
  )
}

export default function DashboardPage() {
  const { t } = useTranslation()
  const [days, setDays] = useState<7 | 30>(7)

  const { data, isLoading, error } = useQuery({
    queryKey: ['dashboard', days],
    queryFn: async () => {
      const res = await api.get('/dashboard/summary', { params: { days } })
      return res.data.data as DashboardSummary
    },
  })

  if (isLoading) return <LoadingBlock label={t('common.loading')} />
  if (error || !data) return <p className="text-danger">تعذر تحميل البيانات.</p>

  const primary = [
    { label: 'إيرادات الفترة', value: formatMoney(data.revenue, data.currency), tone: 'success' as const },
    { label: 'مصروفات الفترة', value: formatMoney(data.expense, data.currency), tone: 'amber' as const },
    { label: 'صافي الربح', value: formatMoney(data.net_income, data.currency), tone: 'teal' as const },
  ]

  const secondary = [
    { label: 'ذمم مدينة', value: formatMoney(data.receivables ?? 0, data.currency), hint: 'مستحق من العملاء' },
    { label: 'ذمم دائنة', value: formatMoney(data.payables ?? 0, data.currency), hint: 'مستحق للموردين' },
    { label: 'مبيعات الشهر', value: formatMoney(data.month_sales ?? 0, data.currency) },
    { label: 'مشتريات الشهر', value: formatMoney(data.month_purchases ?? 0, data.currency) },
  ]

  return (
    <div className="space-y-8">
      <header className="relative overflow-hidden rounded-2xl border border-[var(--color-line)] bg-gradient-to-l from-slate-panel via-[#154456] to-teal px-6 py-8 text-white shadow-sm">
        <div className="relative">
          <p className="text-sm font-medium text-white/70">{t('app.name')}</p>
          <h1 className="mt-1 text-3xl font-extrabold tracking-tight sm:text-4xl">{data.company_name}</h1>
          <p className="mt-2 max-w-xl text-sm leading-7 text-white/75">
            ملخص تشغيلي موحّد — العملة الأساسية {data.currency}
          </p>
        </div>
      </header>

      <section className="grid gap-3 sm:grid-cols-3">
        {primary.map((c) => (
          <StatTile key={c.label} label={c.label} value={c.value} tone={c.tone} />
        ))}
      </section>

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {secondary.map((c) => (
          <StatTile key={c.label} label={c.label} value={c.value} hint={c.hint} />
        ))}
      </section>

      <div className="flex gap-2">
        <button type="button" className={`rounded-lg px-3 py-1.5 text-sm ${days === 7 ? 'bg-teal text-white' : 'bg-mist'}`} onClick={() => setDays(7)}>{t('dashboard.last7Days')}</button>
        <button type="button" className={`rounded-lg px-3 py-1.5 text-sm ${days === 30 ? 'bg-teal text-white' : 'bg-mist'}`} onClick={() => setDays(30)}>{t('dashboard.last30Days')}</button>
      </div>

      <section className="grid gap-6 lg:grid-cols-2">
        <DailyBarChart title={t('dashboard.dailySales')} data={data.daily_sales || []} currency={data.currency} />
        <DailyBarChart title={t('dashboard.dailyPurchases')} data={data.daily_purchases || []} currency={data.currency} />
      </section>

      <div className="grid gap-6 lg:grid-cols-5">
        <Panel className="lg:col-span-3">
          <div className="border-b border-[var(--color-line)] px-5 py-3">
            <h2 className="font-semibold">التنبيهات</h2>
          </div>
          <div className="p-2">
            {(data.alerts || []).length === 0 ? (
              <EmptyState title="لا توجد تنبيهات حالياً" description="سيظهر هنا نقص المخزون والذمم والقيود المعلّقة." />
            ) : (
              <ul className="divide-y divide-[var(--color-line)]">
                {(data.alerts || []).map((a, i) => (
                  <li key={i} className="flex gap-3 px-4 py-3">
                    <AlertTriangle className={a.type === 'warning' ? 'text-amber' : 'text-teal'} size={18} />
                    <div>
                      <p className="text-sm font-semibold">{a.title}</p>
                      <p className="text-xs text-black/55">{a.body}</p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Panel>

        <Panel className="lg:col-span-2">
          <div className="border-b border-[var(--color-line)] px-5 py-3">
            <h2 className="font-semibold">اختصارات</h2>
          </div>
          <div className="grid gap-2 p-4">
            <Link to="/sales" className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-mist">
              <ArrowLeftRight size={16} className="text-teal" /> {t('sales.title')}
            </Link>
            <Link to="/warehouse" className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-mist">
              <Package size={16} className="text-teal" /> {t('warehouse.title')}
            </Link>
            <Link to="/partners" className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-mist">
              <Users size={16} className="text-teal" /> {t('nav.partners')}
            </Link>
          </div>
        </Panel>
      </div>
    </div>
  )
}
