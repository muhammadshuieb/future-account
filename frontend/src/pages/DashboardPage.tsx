import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AlertTriangle, ArrowLeftRight, ChevronLeft, Package, Users } from 'lucide-react'
import api from '@/lib/api'
import { resolveAlertHref } from '@/lib/alertLinks'
import type { DashboardSummary } from '@/types'
import { EmptyState, LoadingBlock, Panel, StatTile, formatMoney } from '@/components/ui'

function DailyBarChart({
  title,
  data,
  currency,
}: {
  title: string
  data: { date: string; total: number }[]
  currency: string
}) {
  const totals = data.map((d) => Number(d.total) || 0)
  const max = Math.max(...totals, 1)
  const periodTotal = totals.reduce((s, n) => s + n, 0)
  const hasData = periodTotal > 0

  return (
    <Panel>
      <div className="flex items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-3">
        <h2 className="font-semibold">{title}</h2>
        <p className="text-xs tabular-nums text-black/55">
          الإجمالي: {formatMoney(periodTotal, currency)}
        </p>
      </div>
      {!hasData ? (
        <div className="flex h-44 items-center justify-center px-4">
          <p className="text-sm text-black/45">لا توجد حركات في هذه الفترة</p>
        </div>
      ) : (
        <div className="flex h-44 gap-1 p-4 pt-3">
          {data.map((d, i) => {
            const total = totals[i]
            const pct = Math.max(total > 0 ? 8 : 0, (total / max) * 100)
            return (
              <div key={d.date} className="flex min-w-0 flex-1 flex-col items-center gap-1">
                <div className="relative flex w-full flex-1 items-end">
                  <div
                    className="w-full rounded-t bg-teal/80 transition-all"
                    style={{ height: `${pct}%` }}
                    title={`${d.date}: ${formatMoney(total, currency)}`}
                  />
                </div>
                <span className="truncate text-[9px] text-black/45">{d.date.slice(5)}</span>
              </div>
            )
          })}
        </div>
      )}
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

  const currency = data.currency || 'SYP'
  const primary = [
    { label: 'إيرادات الفترة', value: formatMoney(data.revenue, currency), tone: 'success' as const },
    { label: 'مصروفات الفترة', value: formatMoney(data.expense, currency), tone: 'amber' as const },
    { label: 'صافي الربح', value: formatMoney(data.net_income, currency), tone: 'teal' as const },
  ]

  const secondary = [
    { label: 'ذمم مدينة', value: formatMoney(data.receivables ?? 0, currency), hint: 'مستحق من العملاء' },
    { label: 'ذمم دائنة', value: formatMoney(data.payables ?? 0, currency), hint: 'مستحق للموردين' },
    { label: 'مبيعات الشهر', value: formatMoney(data.month_sales ?? 0, currency) },
    { label: 'مشتريات الشهر', value: formatMoney(data.month_purchases ?? 0, currency) },
  ]

  return (
    <div className="space-y-8">
      <header className="relative overflow-hidden rounded-2xl border border-[var(--color-line)] bg-gradient-to-l from-slate-panel via-[#154456] to-teal px-6 py-8 text-white shadow-sm">
        <div className="relative">
          <p className="text-sm font-medium text-white/70">{t('app.name')}</p>
          <h1 className="mt-1 text-3xl font-extrabold tracking-tight sm:text-4xl">{data.company_name}</h1>
          <p className="mt-2 max-w-xl text-sm leading-7 text-white/75">
            ملخص تشغيلي موحّد — العملة الأساسية {currency}
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
        <DailyBarChart title={t('dashboard.dailySales')} data={data.daily_sales || []} currency={currency} />
        <DailyBarChart title={t('dashboard.dailyPurchases')} data={data.daily_purchases || []} currency={currency} />
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
                {(data.alerts || []).map((a, i) => {
                  const href = resolveAlertHref(a)
                  const inner = (
                    <>
                      <AlertTriangle className={`mt-0.5 shrink-0 ${a.type === 'warning' ? 'text-amber' : 'text-teal'}`} size={18} />
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold">{a.title}</p>
                        <p className="text-xs text-black/55">{a.body}</p>
                      </div>
                      {href && <ChevronLeft size={16} className="shrink-0 text-black/30" />}
                    </>
                  )
                  return (
                    <li key={`${a.code || a.title}-${i}`}>
                      {href ? (
                        <Link
                          to={href}
                          className="flex cursor-pointer gap-3 px-4 py-3 transition hover:bg-mist"
                        >
                          {inner}
                        </Link>
                      ) : (
                        <div className="flex gap-3 px-4 py-3">{inner}</div>
                      )}
                    </li>
                  )
                })}
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
            <Link to="/warehouse?tab=alerts" className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-mist">
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
