import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, ArrowLeftRight, Package, Users } from 'lucide-react'
import api from '@/lib/api'
import type { DashboardSummary } from '@/types'
import { EmptyState, LoadingBlock, Panel, StatTile, formatMoney } from '@/components/ui'

export default function DashboardPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => {
      const res = await api.get('/dashboard/summary')
      return res.data.data as DashboardSummary
    },
  })

  if (isLoading) return <LoadingBlock label="جاري تحميل لوحة التحكم..." />
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
        <div className="pointer-events-none absolute inset-0 opacity-30"
          style={{ backgroundImage: 'radial-gradient(circle at 20% 20%, rgba(255,255,255,.25), transparent 40%)' }}
        />
        <div className="relative">
          <p className="text-sm font-medium text-white/70">فيوتشر أكونت</p>
          <h1 className="mt-1 text-3xl font-extrabold tracking-tight sm:text-4xl">{data.company_name}</h1>
          <p className="mt-2 max-w-xl text-sm leading-7 text-white/75">
            ملخص تشغيلي موحّد للمحاسبة والمبيعات والمخزون — العملة الأساسية {data.currency}
          </p>
          <div className="mt-5 flex flex-wrap gap-2 text-xs">
            <span className="rounded-md bg-white/15 px-2.5 py-1">{data.accounts_count} حساب</span>
            <span className="rounded-md bg-white/15 px-2.5 py-1">{data.products_count ?? 0} صنف</span>
            <span className="rounded-md bg-white/15 px-2.5 py-1">{data.customers_count ?? 0} عميل</span>
            <span className="rounded-md bg-white/15 px-2.5 py-1">{data.draft_entries_count} مسودة قيد</span>
          </div>
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
              <ArrowLeftRight size={16} className="text-teal" /> فواتير المبيعات
            </Link>
            <Link to="/warehouse" className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-mist">
              <Package size={16} className="text-teal" /> المخازن والأصناف
            </Link>
            <Link to="/partners" className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-mist">
              <Users size={16} className="text-teal" /> العملاء والموردون
            </Link>
            <Link to="/reports" className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-mist">
              <ArrowLeftRight size={16} className="text-teal" /> التقارير والطباعة
            </Link>
            <Link to="/barcodes" className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-mist">
              <Package size={16} className="text-teal" /> طباعة ملصقات الباركود
            </Link>
          </div>
          {(data.low_stock_count ?? 0) > 0 && (
            <div className="border-t border-[var(--color-line)] bg-amber/5 px-5 py-3 text-sm text-amber">
              {data.low_stock_count} أصناف تحت حد إعادة الطلب — راجع تنبيهات المخزن.
            </div>
          )}
        </Panel>
      </div>
    </div>
  )
}
