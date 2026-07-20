import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Field, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

export default function CashBanksPage() {
  const [tab, setTab] = useState('boxes')
  const qc = useQueryClient()
  const msg = useFormMessage()

  const boxes = useQuery({ queryKey: ['cash-boxes'], queryFn: async () => (await api.get('/cash-boxes')).data.data })
  const banks = useQuery({ queryKey: ['banks'], queryFn: async () => (await api.get('/banks')).data.data })
  const transfers = useQuery({ queryKey: ['cash-transfers'], queryFn: async () => (await api.get('/cash-transfers')).data.data, enabled: tab === 'transfers' })
  const reconciliations = useQuery({ queryKey: ['bank-reconciliations'], queryFn: async () => (await api.get('/bank-reconciliations')).data.data, enabled: tab === 'reconcile' })

  const [boxForm, setBoxForm] = useState({ code: '', name: '', opening_balance: '0' })
  const [bankForm, setBankForm] = useState({ code: '', name: '', account_number: '', opening_balance: '0' })
  const [trForm, setTrForm] = useState({
    transfer_date: new Date().toISOString().slice(0, 10),
    from_type: 'cash_box',
    from_id: '',
    to_type: 'bank',
    to_id: '',
    amount: '',
    status: 'posted',
  })
  const [recForm, setRecForm] = useState({
    bank_id: '',
    statement_date: new Date().toISOString().slice(0, 10),
    statement_balance: '',
  })

  const saveBox = useMutation({
    mutationFn: () => api.post('/cash-boxes', { ...boxForm, opening_balance: Number(boxForm.opening_balance), is_active: true }),
    onSuccess: () => { msg.setMessage('تم حفظ الصندوق'); void qc.invalidateQueries({ queryKey: ['cash-boxes'] }) },
    onError: msg.fromErr,
  })
  const saveBank = useMutation({
    mutationFn: () => api.post('/banks', { ...bankForm, opening_balance: Number(bankForm.opening_balance), currency: 'SAR', is_active: true }),
    onSuccess: () => { msg.setMessage('تم حفظ الحساب البنكي'); void qc.invalidateQueries({ queryKey: ['banks'] }) },
    onError: msg.fromErr,
  })
  const saveTr = useMutation({
    mutationFn: () => api.post('/cash-transfers', { ...trForm, from_id: Number(trForm.from_id), to_id: Number(trForm.to_id), amount: Number(trForm.amount) }),
    onSuccess: () => { msg.setMessage('تم ترحيل التحويل'); void qc.invalidateQueries({ queryKey: ['cash-transfers'] }) },
    onError: msg.fromErr,
  })
  const saveRec = useMutation({
    mutationFn: () => api.post('/bank-reconciliations', { ...recForm, bank_id: Number(recForm.bank_id), statement_balance: Number(recForm.statement_balance) }),
    onSuccess: () => { msg.setMessage('تم إنشاء التسوية'); void qc.invalidateQueries({ queryKey: ['bank-reconciliations'] }) },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-6">
      <PageHeader title="الصناديق والبنوك" subtitle="صناديق نقدية، حسابات بنكية، تحويلات، وتسوية كشف حساب" />
      <Tabs tabs={[{ id: 'boxes', label: 'الصناديق' }, { id: 'banks', label: 'البنوك' }, { id: 'transfers', label: 'التحويلات' }, { id: 'reconcile', label: 'التسويات' }]} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'boxes' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">رمز</th><th className="px-4 py-3">اسم</th><th className="px-4 py-3">افتتاحي</th></tr></thead>
              <tbody>{(boxes.data || []).map((b: { id: number; code: string; name: string; opening_balance: number }) => <tr key={b.id} className="border-t border-black/5"><td className="px-4 py-3 font-mono">{b.code}</td><td className="px-4 py-3">{b.name}</td><td className="px-4 py-3">{b.opening_balance}</td></tr>)}</tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveBox.mutate() }}>
            <h2 className="font-semibold">صندوق جديد</h2>
            <Field label="رمز"><input className={inputClass} value={boxForm.code} onChange={(e) => setBoxForm({ ...boxForm, code: e.target.value })} required /></Field>
            <Field label="اسم"><input className={inputClass} value={boxForm.name} onChange={(e) => setBoxForm({ ...boxForm, name: e.target.value })} required /></Field>
            <Field label="رصيد افتتاحي"><input className={inputClass} value={boxForm.opening_balance} onChange={(e) => setBoxForm({ ...boxForm, opening_balance: e.target.value })} /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">حفظ</button>
          </form>
        </div>
      )}

      {tab === 'banks' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">رمز</th><th className="px-4 py-3">اسم</th><th className="px-4 py-3">رقم الحساب</th></tr></thead>
              <tbody>{(banks.data || []).map((b: { id: number; code: string; name: string; account_number?: string }) => <tr key={b.id} className="border-t border-black/5"><td className="px-4 py-3 font-mono">{b.code}</td><td className="px-4 py-3">{b.name}</td><td className="px-4 py-3">{b.account_number || '—'}</td></tr>)}</tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveBank.mutate() }}>
            <h2 className="font-semibold">حساب بنكي</h2>
            <Field label="رمز"><input className={inputClass} value={bankForm.code} onChange={(e) => setBankForm({ ...bankForm, code: e.target.value })} required /></Field>
            <Field label="اسم"><input className={inputClass} value={bankForm.name} onChange={(e) => setBankForm({ ...bankForm, name: e.target.value })} required /></Field>
            <Field label="رقم الحساب"><input className={inputClass} value={bankForm.account_number} onChange={(e) => setBankForm({ ...bankForm, account_number: e.target.value })} /></Field>
            <Field label="رصيد افتتاحي"><input className={inputClass} value={bankForm.opening_balance} onChange={(e) => setBankForm({ ...bankForm, opening_balance: e.target.value })} /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">حفظ</button>
          </form>
        </div>
      )}

      {tab === 'transfers' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">من → إلى</th><th className="px-4 py-3">مبلغ</th><th className="px-4 py-3">حالة</th></tr></thead>
              <tbody>{(transfers.data || []).map((t: { id: number; transfer_number: string; from_type: string; to_type: string; amount: number; status: string }) => <tr key={t.id} className="border-t border-black/5"><td className="px-4 py-3 font-mono text-xs">{t.transfer_number}</td><td className="px-4 py-3">{t.from_type} → {t.to_type}</td><td className="px-4 py-3">{t.amount}</td><td className="px-4 py-3">{t.status}</td></tr>)}</tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveTr.mutate() }}>
            <h2 className="font-semibold">تحويل</h2>
            <Field label="من نوع"><select className={inputClass} value={trForm.from_type} onChange={(e) => setTrForm({ ...trForm, from_type: e.target.value })}><option value="cash_box">صندوق</option><option value="bank">بنك</option></select></Field>
            <Field label="من معرف"><select className={inputClass} value={trForm.from_id} onChange={(e) => setTrForm({ ...trForm, from_id: e.target.value })} required><option value="">—</option>{(trForm.from_type === 'cash_box' ? boxes.data : banks.data || []).map((x: { id: number; name: string }) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
            <Field label="إلى نوع"><select className={inputClass} value={trForm.to_type} onChange={(e) => setTrForm({ ...trForm, to_type: e.target.value })}><option value="cash_box">صندوق</option><option value="bank">بنك</option></select></Field>
            <Field label="إلى معرف"><select className={inputClass} value={trForm.to_id} onChange={(e) => setTrForm({ ...trForm, to_id: e.target.value })} required><option value="">—</option>{(trForm.to_type === 'cash_box' ? boxes.data : banks.data || []).map((x: { id: number; name: string }) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
            <Field label="المبلغ"><input className={inputClass} value={trForm.amount} onChange={(e) => setTrForm({ ...trForm, amount: e.target.value })} required /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">ترحيل</button>
          </form>
        </div>
      )}

      {tab === 'reconcile' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">بنك</th><th className="px-4 py-3">كشف</th><th className="px-4 py-3">دفاتر</th><th className="px-4 py-3">فرق</th></tr></thead>
              <tbody>{(reconciliations.data || []).map((r: { id: number; statement_balance: number; book_balance: number; difference: number; bank?: { name: string } }) => <tr key={r.id} className="border-t border-black/5"><td className="px-4 py-3">{r.bank?.name}</td><td className="px-4 py-3">{r.statement_balance}</td><td className="px-4 py-3">{r.book_balance}</td><td className="px-4 py-3">{r.difference}</td></tr>)}</tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveRec.mutate() }}>
            <h2 className="font-semibold">تسوية بنكية</h2>
            <Field label="بنك"><select className={inputClass} value={recForm.bank_id} onChange={(e) => setRecForm({ ...recForm, bank_id: e.target.value })} required><option value="">—</option>{(banks.data || []).map((b: { id: number; name: string }) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></Field>
            <Field label="تاريخ الكشف"><input type="date" className={inputClass} value={recForm.statement_date} onChange={(e) => setRecForm({ ...recForm, statement_date: e.target.value })} /></Field>
            <Field label="رصيد الكشف"><input className={inputClass} value={recForm.statement_balance} onChange={(e) => setRecForm({ ...recForm, statement_balance: e.target.value })} required /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">حفظ التسوية</button>
          </form>
        </div>
      )}
    </div>
  )
}
