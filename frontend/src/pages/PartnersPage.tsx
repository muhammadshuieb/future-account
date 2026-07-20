import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Field, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

export default function PartnersPage() {
  const [tab, setTab] = useState('customers')
  const [statementId, setStatementId] = useState<number | null>(null)
  const qc = useQueryClient()
  const msg = useFormMessage()

  const customers = useQuery({ queryKey: ['customers'], queryFn: async () => (await api.get('/customers')).data.data })
  const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: async () => (await api.get('/suppliers')).data.data })
  const statement = useQuery({
    queryKey: ['statement', tab, statementId],
    queryFn: async () => (await api.get(`/${tab}/${statementId}/statement`)).data.data,
    enabled: !!statementId,
  })

  const [form, setForm] = useState({ code: '', name: '', phone: '', credit_limit: '0' })

  const save = useMutation({
    mutationFn: () => api.post(`/${tab}`, { ...form, credit_limit: Number(form.credit_limit), is_active: true }),
    onSuccess: () => {
      msg.setMessage('تم الحفظ')
      setForm({ code: '', name: '', phone: '', credit_limit: '0' })
      void qc.invalidateQueries({ queryKey: [tab] })
    },
    onError: msg.fromErr,
  })

  const rows = tab === 'customers' ? customers.data : suppliers.data

  return (
    <div className="space-y-6">
      <PageHeader title="العملاء والموردون" subtitle="بطاقات الاتصال، حدود الائتمان، وكشوف الحساب" />
      <Tabs tabs={[{ id: 'customers', label: 'العملاء' }, { id: 'suppliers', label: 'الموردون' }]} active={tab} onChange={(id) => { setTab(id); setStatementId(null) }} />
      <Msg message={msg.message} error={msg.error} />

      <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">رمز</th><th className="px-4 py-3">الاسم</th><th className="px-4 py-3">هاتف</th><th className="px-4 py-3"></th></tr></thead>
            <tbody>
              {(rows || []).map((r: { id: number; code: string; name: string; phone?: string }) => (
                <tr key={r.id} className="border-t border-black/5">
                  <td className="px-4 py-3 font-mono">{r.code}</td>
                  <td className="px-4 py-3">{r.name}</td>
                  <td className="px-4 py-3">{r.phone || '—'}</td>
                  <td className="px-4 py-3"><button type="button" className="text-teal" onClick={() => setStatementId(r.id)}>كشف حساب</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
        <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <h2 className="font-semibold">{tab === 'customers' ? 'عميل جديد' : 'مورد جديد'}</h2>
          <Field label="الرمز"><input className={inputClass} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} required /></Field>
          <Field label="الاسم"><input className={inputClass} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
          <Field label="الهاتف"><input className={inputClass} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
          <Field label="حد الائتمان"><input className={inputClass} value={form.credit_limit} onChange={(e) => setForm({ ...form, credit_limit: e.target.value })} /></Field>
          <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">حفظ</button>
        </form>
      </div>

      {statementId && statement.data && (
        <Panel>
          <div className="border-b border-black/5 px-4 py-3 font-semibold">كشف حساب — الرصيد: {statement.data.balance}</div>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">تاريخ</th><th className="px-4 py-3">نوع</th><th className="px-4 py-3">رقم</th><th className="px-4 py-3">مدين</th><th className="px-4 py-3">دائن</th><th className="px-4 py-3">رصيد</th></tr></thead>
            <tbody>
              {(statement.data.rows || []).map((r: { date: string; type: string; number: string; debit: number; credit: number; balance: number }, idx: number) => (
                <tr key={idx} className="border-t border-black/5">
                  <td className="px-4 py-3">{r.date}</td>
                  <td className="px-4 py-3">{r.type}</td>
                  <td className="px-4 py-3 font-mono text-xs">{r.number}</td>
                  <td className="px-4 py-3">{r.debit}</td>
                  <td className="px-4 py-3">{r.credit}</td>
                  <td className="px-4 py-3">{r.balance}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}
    </div>
  )
}
