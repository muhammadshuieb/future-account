import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import api from '@/lib/api'
import { LOGO } from '@/lib/brand'
import { Button, EmptyState, Field, LoadingBlock, PageHeader, Panel, Tabs, formatMoney, formatQuantity, inputClass } from '@/components/ui'

type ReportKey =
  | 'trial-balance'
  | 'general-ledger'
  | 'income-statement'
  | 'balance-sheet'
  | 'cash-flow'
  | 'sales'
  | 'purchases'
  | 'inventory'
  | 'profit'
  | 'tax'
  | 'customer-statement'
  | 'supplier-statement'
  | 'product-movement'

const reportTitleFallback: Record<ReportKey, string> = {
  'trial-balance': 'ميزان المراجعة',
  'general-ledger': 'دفتر الأستاذ العام',
  'income-statement': 'قائمة الدخل (الأرباح والخسائر)',
  'balance-sheet': 'الميزانية العمومية',
  'cash-flow': 'قائمة التدفقات النقدية',
  sales: 'تقرير المبيعات',
  purchases: 'تقرير المشتريات',
  inventory: 'تقرير المخزون',
  profit: 'تقرير مجمل الربح',
  tax: 'تقرير الضريبة',
  'customer-statement': 'كشف حساب عميل',
  'supplier-statement': 'كشف حساب مورد',
  'product-movement': 'حركة صنف',
}

export default function ReportsPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<ReportKey>('trial-balance')
  const [from, setFrom] = useState(new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10))
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10))
  const [branchId, setBranchId] = useState('')
  const [customerId, setCustomerId] = useState('')
  const [supplierId, setSupplierId] = useState('')
  const [productId, setProductId] = useState('')

  const [accountId, setAccountId] = useState('')

  const branches = useQuery({ queryKey: ['branches'], queryFn: async () => (await api.get('/branches')).data.data })
  const accounts = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => (await api.get('/accounts')).data.data as { id: number; code: string; name: string; is_group: boolean }[],
    enabled: tab === 'general-ledger',
  })
  const customers = useQuery({
    queryKey: ['customers'],
    queryFn: async () => (await api.get('/customers')).data.data,
    enabled: tab === 'customer-statement',
  })
  const suppliers = useQuery({
    queryKey: ['suppliers'],
    queryFn: async () => (await api.get('/suppliers')).data.data,
    enabled: tab === 'supplier-statement',
  })
  const products = useQuery({
    queryKey: ['products'],
    queryFn: async () => (await api.get('/products')).data.data,
    enabled: tab === 'product-movement',
  })
  const currencies = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api.get('/currencies')).data.data as { base_currency: string },
  })
  const base = currencies.data?.base_currency || 'SYP'

  const reportUrl = useMemo(() => {
    if (tab === 'general-ledger') return accountId ? '/reports/general-ledger' : null
    if (tab === 'customer-statement') return customerId ? `/customers/${customerId}/statement` : null
    if (tab === 'supplier-statement') return supplierId ? `/suppliers/${supplierId}/statement` : null
    if (tab === 'product-movement') return productId ? `/reports/product-movement/${productId}` : null
    return `/reports/${tab}`
  }, [tab, customerId, supplierId, productId, accountId])

  const report = useQuery({
    queryKey: ['report', tab, from, to, branchId, customerId, supplierId, productId, accountId],
    enabled: !!reportUrl,
    queryFn: async () => {
      const params: Record<string, string> = {}
      if (tab === 'general-ledger') {
        params.account_id = accountId
        params.from = from
        params.to = to
      } else if (tab === 'trial-balance' || tab === 'balance-sheet') {
        params.as_of = to
      } else if (tab !== 'inventory') {
        params.from = from
        params.to = to
      }
      if (branchId && (tab === 'sales' || tab === 'purchases')) {
        params.branch_id = branchId
      }
      return (await api.get(reportUrl!, { params })).data.data
    },
  })

  function printReport() {
    window.print()
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="التقارير"
        subtitle="تقارير مالية وتشغيلية بالعملة الأساسية مع دعم الطباعة"
        actions={
          <Button variant="secondary" className="print-hide" onClick={printReport}>
            <Printer size={16} /> طباعة / PDF
          </Button>
        }
      />

      <Tabs
        tabs={Object.entries(reportTitleFallback).map(([id, label]) => ({
          id,
          label: id === 'general-ledger' ? t('reports.generalLedger') : label,
        }))}
        active={tab}
        onChange={(id) => setTab(id as ReportKey)}
      />

      <div className="print-hide flex flex-wrap gap-3">
        {tab !== 'inventory' && tab !== 'trial-balance' && tab !== 'balance-sheet' && (
          <Field label="من"><input type="date" className={inputClass} value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
        )}
        <Field label={tab === 'trial-balance' || tab === 'balance-sheet' ? 'بتاريخ' : 'إلى'}>
          <input type="date" className={inputClass} value={to} onChange={(e) => setTo(e.target.value)} />
        </Field>
        {(tab === 'sales' || tab === 'purchases') && (
          <Field label="الفرع">
            <select className={inputClass} value={branchId} onChange={(e) => setBranchId(e.target.value)}>
              <option value="">كل الفروع</option>
              {(branches.data || []).map((b: { id: number; name: string }) => (
                <option key={b.id} value={b.id}>{b.name}</option>
              ))}
            </select>
          </Field>
        )}
        {tab === 'customer-statement' && (
          <Field label="العميل">
            <select className={inputClass} value={customerId} onChange={(e) => setCustomerId(e.target.value)}>
              <option value="">اختر عميلاً</option>
              {(customers.data || []).map((c: { id: number; name: string; code: string }) => (
                <option key={c.id} value={c.id}>{c.code} — {c.name}</option>
              ))}
            </select>
          </Field>
        )}
        {tab === 'supplier-statement' && (
          <Field label="المورد">
            <select className={inputClass} value={supplierId} onChange={(e) => setSupplierId(e.target.value)}>
              <option value="">اختر مورداً</option>
              {(suppliers.data || []).map((s: { id: number; name: string; code: string }) => (
                <option key={s.id} value={s.id}>{s.code} — {s.name}</option>
              ))}
            </select>
          </Field>
        )}
        {tab === 'product-movement' && (
          <Field label="الصنف">
            <select className={inputClass} value={productId} onChange={(e) => setProductId(e.target.value)}>
              <option value="">اختر صنفاً</option>
              {(products.data || []).map((p: { id: number; name: string; sku: string }) => (
                <option key={p.id} value={p.id}>{p.sku} — {p.name}</option>
              ))}
            </select>
          </Field>
        )}
        {tab === 'general-ledger' && (
          <Field label={t('reports.selectAccount')}>
            <select className={inputClass} value={accountId} onChange={(e) => setAccountId(e.target.value)}>
              <option value="">{t('reports.selectAccount')}</option>
              {(accounts.data || []).filter((a) => !a.is_group).map((a) => (
                <option key={a.id} value={a.id}>{a.code} — {a.name}</option>
              ))}
            </select>
          </Field>
        )}
      </div>

      <Panel className="print-area">
        <div className="hidden border-b border-black/10 px-4 py-3 print:block">
          <div className="mb-2 flex items-center gap-3">
            <img src={LOGO.print} alt="SYNAMOR TECHNOLOGY" className="brand-logo brand-logo--print" />
            <div>
              <p className="text-lg font-bold">{t('app.name')} — Syna Co</p>
              <p className="text-sm font-semibold">
                {tab === 'general-ledger' ? t('reports.generalLedger') : reportTitleFallback[tab]}
              </p>
            </div>
          </div>
          <p className="text-xs text-black/60">
            العملة الأساسية: {base}
            {tab !== 'inventory' && ` · الفترة: ${from} → ${to}`}
          </p>
        </div>

        {!reportUrl && <EmptyState title="اختر عنصراً لعرض التقرير" />}
        {reportUrl && report.isLoading && <LoadingBlock />}
        {report.error && <p className="p-4 text-danger">تعذر تحميل التقرير</p>}
        {report.data && (
          <div className="space-y-4 p-4 text-sm">
            {tab === 'trial-balance' && (
              <div className="table-wrap">
                <table className="data-table">
                  <thead><tr><th>رمز</th><th>حساب</th><th>مدين</th><th>دائن</th></tr></thead>
                  <tbody>
                    {(report.data.rows || []).map((r: { code: string; name: string; debit: number; credit: number }) => (
                      <tr key={r.code}><td className="font-mono">{r.code}</td><td>{r.name}</td><td className="tabular-nums">{formatMoney(r.debit, base)}</td><td className="tabular-nums">{formatMoney(r.credit, base)}</td></tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="font-semibold"><td colSpan={2}>الإجمالي</td><td>{formatMoney(report.data.total_debit, base)}</td><td>{formatMoney(report.data.total_credit, base)}</td></tr>
                  </tfoot>
                </table>
              </div>
            )}

            {tab === 'general-ledger' && (
              <div>
                <p className="mb-3 font-semibold">
                  {report.data.account?.code} — {report.data.account?.name}
                </p>
                <div className="mb-3 grid gap-2 sm:grid-cols-2">
                  <p>{t('reports.openingBalance')}: <strong>{formatMoney(report.data.opening_balance, base)}</strong></p>
                  <p>{t('reports.closingBalance')}: <strong>{formatMoney(report.data.closing_balance, base)}</strong></p>
                </div>
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t('common.date')}</th>
                      <th>{t('reports.entryNumber')}</th>
                      <th>{t('reports.description')}</th>
                      <th>{t('common.debit')}</th>
                      <th>{t('common.credit')}</th>
                      <th>{t('common.balance')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(report.data.rows || []).map((r: {
                      date: string
                      entry_number: string
                      description: string
                      debit: number
                      credit: number
                      balance: number
                    }, i: number) => (
                      <tr key={i}>
                        <td>{r.date}</td>
                        <td className="font-mono">{r.entry_number}</td>
                        <td>{r.description}</td>
                        <td className="tabular-nums">{formatMoney(r.debit, base)}</td>
                        <td className="tabular-nums">{formatMoney(r.credit, base)}</td>
                        <td className="tabular-nums">{formatMoney(r.balance, base)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {tab === 'income-statement' && (
              <div className="space-y-4">
                <section>
                  <h3 className="mb-2 font-semibold text-teal">الإيرادات</h3>
                  <table className="data-table">
                    <tbody>
                      {(report.data.revenue?.rows || []).map((r: { code: string; name: string; amount: number }) => (
                        <tr key={r.code}><td className="font-mono">{r.code}</td><td>{r.name}</td><td className="tabular-nums">{formatMoney(r.amount, base)}</td></tr>
                      ))}
                    </tbody>
                  </table>
                  <p className="mt-2 font-semibold">الإجمالي: {formatMoney(report.data.revenue?.total || 0, base)}</p>
                </section>
                <section>
                  <h3 className="mb-2 font-semibold text-amber">المصروفات</h3>
                  <table className="data-table">
                    <tbody>
                      {(report.data.expense?.rows || []).map((r: { code: string; name: string; amount: number }) => (
                        <tr key={r.code}><td className="font-mono">{r.code}</td><td>{r.name}</td><td className="tabular-nums">{formatMoney(r.amount, base)}</td></tr>
                      ))}
                    </tbody>
                  </table>
                  <p className="mt-2 font-semibold">الإجمالي: {formatMoney(report.data.expense?.total || 0, base)}</p>
                </section>
                <p className="rounded-lg bg-teal-soft/50 px-4 py-3 text-lg font-bold text-teal-dark">
                  صافي الدخل: {formatMoney(report.data.net_income, base)}
                </p>
              </div>
            )}

            {tab === 'balance-sheet' && (
              <div className="grid gap-6 md:grid-cols-3">
                {[
                  { title: 'الأصول', total: report.data.total_assets, rows: report.data.assets },
                  { title: 'الخصوم', total: report.data.total_liabilities, rows: report.data.liabilities },
                  { title: 'حقوق الملكية', total: report.data.total_equity, rows: report.data.equity },
                ].map((col) => (
                  <div key={col.title}>
                    <h3 className="mb-2 font-semibold">{col.title}</h3>
                    <ul className="space-y-1 text-xs">
                      {(col.rows || []).map((r: { code: string; name: string; balance: number }) => (
                        <li key={r.code} className="flex justify-between gap-2 border-b border-black/5 py-1">
                          <span>{r.code} {r.name}</span>
                          <span className="tabular-nums">{formatMoney(r.balance, base)}</span>
                        </li>
                      ))}
                    </ul>
                    <p className="mt-2 font-bold">{formatMoney(col.total || 0, base)}</p>
                  </div>
                ))}
                <p className="md:col-span-3 text-sm text-black/55">يشمل صافي دخل الفترة ضمن الملكية: {formatMoney(report.data.net_income || 0, base)}</p>
              </div>
            )}

            {tab === 'cash-flow' && (
              <div>
                <div className="mb-3 grid gap-3 sm:grid-cols-3">
                  <p>وارد: <strong>{formatMoney(report.data.total_inflow, base)}</strong></p>
                  <p>منصرف: <strong>{formatMoney(report.data.total_outflow, base)}</strong></p>
                  <p>صافي: <strong>{formatMoney(report.data.net, base)}</strong></p>
                </div>
                <table className="data-table">
                  <thead><tr><th>تاريخ</th><th>قيد</th><th>بيان</th><th>وارد</th><th>منصرف</th></tr></thead>
                  <tbody>
                    {(report.data.rows || []).map((r: { entry_number: string; date: string; description: string; inflow: number; outflow: number }, i: number) => (
                      <tr key={i}><td>{r.date}</td><td className="font-mono">{r.entry_number}</td><td>{r.description}</td><td>{r.inflow}</td><td>{r.outflow}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {(tab === 'sales' || tab === 'purchases') && (
              <div>
                <p className="mb-3">عدد المستندات: {report.data.count} — الإجمالي (أساس): <strong>{formatMoney(report.data.total || report.data.total_base || 0, base)}</strong></p>
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>رقم</th><th>تاريخ</th><th>{tab === 'sales' ? 'عميل' : 'مورد'}</th><th>عملة</th><th>المبلغ</th><th>بالأساس</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(report.data.rows || []).map((r: {
                      id: number
                      invoice_number: string
                      invoice_date: string
                      total: number
                      base_amount?: number
                      currency?: string
                      customer?: { name: string }
                      supplier?: { name: string }
                    }) => (
                      <tr key={r.id}>
                        <td className="font-mono">{r.invoice_number}</td>
                        <td>{String(r.invoice_date).slice(0, 10)}</td>
                        <td>{r.customer?.name || r.supplier?.name}</td>
                        <td>{r.currency || base}</td>
                        <td className="tabular-nums">{r.total}</td>
                        <td className="tabular-nums">{r.base_amount ?? r.total}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {tab === 'inventory' && (
              <div>
                <p className="mb-2">قيمة المخزون: <strong>{formatMoney(report.data.total_value, base)}</strong></p>
                <table className="data-table">
                  <thead><tr><th>SKU</th><th>اسم</th><th>كمية</th><th>قيمة</th></tr></thead>
                  <tbody>
                    {(report.data.rows || []).map((r: { id: number; sku: string; name: string; on_hand: number; value: number }) => (
                      <tr key={r.id}><td className="font-mono">{r.sku}</td><td>{r.name}</td><td className="tabular-nums">{formatQuantity(r.on_hand)}</td><td>{formatMoney(r.value, base)}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {tab === 'profit' && (
              <div className="space-y-2 rounded-lg bg-mist/60 p-4">
                <p>المبيعات: {formatMoney(report.data.sales, base)}</p>
                <p>تكلفة المبيعات: {formatMoney(report.data.cogs, base)}</p>
                <p className="text-lg font-bold text-teal">مجمل الربح: {formatMoney(report.data.gross_profit, base)}</p>
              </div>
            )}

            {tab === 'tax' && (
              <div className="space-y-2">
                <p>ضريبة مخرجات: {formatMoney(report.data.output_vat, base)}</p>
                <p>ضريبة مدخلات: {formatMoney(report.data.input_vat, base)}</p>
                <p className="font-semibold">صافي: {formatMoney(report.data.net_vat, base)}</p>
                <p className="text-xs text-black/45">{report.data.note}</p>
              </div>
            )}

            {(tab === 'customer-statement' || tab === 'supplier-statement') && (
              <div>
                <p className="mb-3 font-semibold">
                  {report.data.customer?.name || report.data.supplier?.name}
                  {' — '}الرصيد: {formatMoney(report.data.balance || 0, base)}
                </p>
                <table className="data-table">
                  <thead><tr><th>تاريخ</th><th>نوع</th><th>رقم</th><th>مدين</th><th>دائن</th><th>رصيد</th></tr></thead>
                  <tbody>
                    {(report.data.rows || []).map((r: {
                      date: string
                      type: string
                      number: string
                      debit: number
                      credit: number
                      balance: number
                    }, i: number) => (
                      <tr key={i}>
                        <td>{r.date}</td>
                        <td>{r.type}</td>
                        <td className="font-mono">{r.number}</td>
                        <td className="tabular-nums">{r.debit}</td>
                        <td className="tabular-nums">{r.credit}</td>
                        <td className="tabular-nums">{r.balance}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {tab === 'product-movement' && (
              <table className="data-table">
                <thead><tr><th>تاريخ</th><th>مخزن</th><th>نوع</th><th title={t('common.quantityUnit')}>كمية</th><th>ملاحظات</th></tr></thead>
                <tbody>
                  {(report.data.rows || []).map((r: {
                    id: number
                    movement_date: string
                    type: string
                    quantity: number
                    notes?: string
                    warehouse?: { name: string }
                  }) => (
                    <tr key={r.id}>
                      <td>{String(r.movement_date).slice(0, 10)}</td>
                      <td>{r.warehouse?.name}</td>
                      <td>{r.type}</td>
                      <td className="tabular-nums">{formatQuantity(r.quantity)}</td>
                      <td>{r.notes}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}
      </Panel>
    </div>
  )
}
