import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Field, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

export default function PurchasesPage() {
  const [tab, setTab] = useState('invoices')
  const qc = useQueryClient()
  const msg = useFormMessage()

  const invoices = useQuery({ queryKey: ['purchase-invoices'], queryFn: async () => (await api.get('/purchase-invoices')).data.data })
  const payments = useQuery({ queryKey: ['supplier-payments'], queryFn: async () => (await api.get('/supplier-payments')).data.data, enabled: tab === 'payments' })
  const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: async () => (await api.get('/suppliers')).data.data })
  const products = useQuery({ queryKey: ['products'], queryFn: async () => (await api.get('/products')).data.data })
  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data.data })
  const cashBoxes = useQuery({ queryKey: ['cash-boxes'], queryFn: async () => (await api.get('/cash-boxes')).data.data, enabled: tab === 'payments' })

  const [inv, setInv] = useState({
    invoice_date: new Date().toISOString().slice(0, 10),
    supplier_id: '',
    warehouse_id: '',
    product_id: '',
    quantity: '10',
    unit_cost: '',
    currency: 'SYP',
    exchange_rate: '',
    status: 'posted',
  })
  const [pay, setPay] = useState({
    payment_date: new Date().toISOString().slice(0, 10),
    supplier_id: '',
    purchase_invoice_id: '',
    cash_box_id: '',
    amount: '',
    method: 'cash',
    status: 'posted',
  })

  const saveInv = useMutation({
    mutationFn: () =>
      api.post('/purchase-invoices', {
        invoice_date: inv.invoice_date,
        supplier_id: Number(inv.supplier_id),
        warehouse_id: Number(inv.warehouse_id),
        currency: inv.currency,
        exchange_rate: inv.exchange_rate ? Number(inv.exchange_rate) : undefined,
        status: inv.status,
        lines: [{ product_id: Number(inv.product_id), quantity: Number(inv.quantity), unit_cost: inv.unit_cost ? Number(inv.unit_cost) : undefined, tax_rate: 15 }],
      }),
    onSuccess: () => { msg.setMessage('تم ترحيل فاتورة المشتريات وإدخال المخزون'); void qc.invalidateQueries({ queryKey: ['purchase-invoices', 'stock-levels', 'products'] }) },
    onError: msg.fromErr,
  })

  const savePay = useMutation({
    mutationFn: () =>
      api.post('/supplier-payments', {
        ...pay,
        supplier_id: Number(pay.supplier_id),
        purchase_invoice_id: pay.purchase_invoice_id ? Number(pay.purchase_invoice_id) : null,
        cash_box_id: pay.cash_box_id ? Number(pay.cash_box_id) : null,
        amount: Number(pay.amount),
      }),
    onSuccess: () => { msg.setMessage('تم ترحيل سند الصرف'); void qc.invalidateQueries({ queryKey: ['supplier-payments', 'purchase-invoices'] }) },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-6">
      <PageHeader title="المشتريات" subtitle="فواتير الموردين والمدفوعات مع أثر مخزني ومحاسبي" />
      <Tabs tabs={[{ id: 'invoices', label: 'فواتير الموردين' }, { id: 'payments', label: 'المدفوعات' }]} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'invoices' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">مورد</th><th className="px-4 py-3">إجمالي</th><th className="px-4 py-3">حالة</th></tr></thead>
              <tbody>
                {(invoices.data || []).map((i: { id: number; invoice_number: string; total: number; status: string; supplier?: { name: string } }) => (
                  <tr key={i.id} className="border-t border-black/5"><td className="px-4 py-3 font-mono text-xs">{i.invoice_number}</td><td className="px-4 py-3">{i.supplier?.name}</td><td className="px-4 py-3">{i.total}</td><td className="px-4 py-3">{i.status}</td></tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveInv.mutate() }}>
            <h2 className="font-semibold">فاتورة مشتريات</h2>
            <Field label="التاريخ"><input type="date" className={inputClass} value={inv.invoice_date} onChange={(e) => setInv({ ...inv, invoice_date: e.target.value })} /></Field>
            <Field label="مورد"><select className={inputClass} value={inv.supplier_id} onChange={(e) => setInv({ ...inv, supplier_id: e.target.value })} required><option value="">—</option>{(suppliers.data || []).map((s: { id: number; name: string }) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
            <Field label="مخزن"><select className={inputClass} value={inv.warehouse_id} onChange={(e) => setInv({ ...inv, warehouse_id: e.target.value })} required><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label="العملة">
                <select className={inputClass} value={inv.currency} onChange={(e) => setInv({ ...inv, currency: e.target.value })}>
                  <option value="SYP">SYP</option>
                  <option value="TRY">TRY</option>
                  <option value="USD">USD</option>
                </select>
              </Field>
              <Field label="سعر الصرف"><input className={inputClass} value={inv.exchange_rate} onChange={(e) => setInv({ ...inv, exchange_rate: e.target.value })} placeholder="تلقائي" /></Field>
            </div>
            <Field label="صنف"><select className={inputClass} value={inv.product_id} onChange={(e) => setInv({ ...inv, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p: { id: number; name: string }) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label="كمية"><input className={inputClass} value={inv.quantity} onChange={(e) => setInv({ ...inv, quantity: e.target.value })} /></Field>
              <Field label="تكلفة"><input className={inputClass} value={inv.unit_cost} onChange={(e) => setInv({ ...inv, unit_cost: e.target.value })} /></Field>
            </div>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">ترحيل الشراء</button>
          </form>
        </div>
      )}

      {tab === 'payments' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">مورد</th><th className="px-4 py-3">مبلغ</th><th className="px-4 py-3">حالة</th></tr></thead>
              <tbody>
                {(payments.data || []).map((p: { id: number; payment_number: string; amount: number; status: string; supplier?: { name: string } }) => (
                  <tr key={p.id} className="border-t border-black/5"><td className="px-4 py-3 font-mono text-xs">{p.payment_number}</td><td className="px-4 py-3">{p.supplier?.name}</td><td className="px-4 py-3">{p.amount}</td><td className="px-4 py-3">{p.status}</td></tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); savePay.mutate() }}>
            <h2 className="font-semibold">سند صرف مورد</h2>
            <Field label="مورد"><select className={inputClass} value={pay.supplier_id} onChange={(e) => setPay({ ...pay, supplier_id: e.target.value })} required><option value="">—</option>{(suppliers.data || []).map((s: { id: number; name: string }) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
            <Field label="فاتورة"><select className={inputClass} value={pay.purchase_invoice_id} onChange={(e) => setPay({ ...pay, purchase_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field>
            <Field label="صندوق"><select className={inputClass} value={pay.cash_box_id} onChange={(e) => setPay({ ...pay, cash_box_id: e.target.value })}><option value="">—</option>{(cashBoxes.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="المبلغ"><input className={inputClass} value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} required /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">ترحيل الصرف</button>
          </form>
        </div>
      )}
    </div>
  )
}
