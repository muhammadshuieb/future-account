import { useState, type Dispatch, type SetStateAction } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { Field, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

type ProductRow = { id: number; name: string; cost_price: number; track_batch?: boolean; track_serial?: boolean }

function purchaseLine(productId: string, qty: string, cost: string, batch: string, serial: string) {
  return {
    product_id: Number(productId),
    quantity: Number(qty),
    unit_cost: cost ? Number(cost) : undefined,
    tax_rate: 15,
    batch_no: batch || undefined,
    serial_no: serial || undefined,
  }
}

export default function PurchasesPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState('invoices')
  const qc = useQueryClient()
  const msg = useFormMessage()

  const requests = useQuery({ queryKey: ['purchase-requests'], queryFn: async () => (await api.get('/purchase-requests')).data.data, enabled: tab === 'requests' })
  const orders = useQuery({ queryKey: ['purchase-orders'], queryFn: async () => (await api.get('/purchase-orders')).data.data, enabled: tab === 'orders' })
  const invoices = useQuery({ queryKey: ['purchase-invoices'], queryFn: async () => (await api.get('/purchase-invoices')).data.data })
  const returns = useQuery({ queryKey: ['purchase-returns'], queryFn: async () => (await api.get('/purchase-returns')).data.data, enabled: tab === 'returns' })
  const payments = useQuery({ queryKey: ['supplier-payments'], queryFn: async () => (await api.get('/supplier-payments')).data.data, enabled: tab === 'payments' })
  const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: async () => (await api.get('/suppliers')).data.data })
  const products = useQuery({ queryKey: ['products'], queryFn: async () => (await api.get('/products')).data.data as ProductRow[] })
  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data.data })
  const cashBoxes = useQuery({ queryKey: ['cash-boxes'], queryFn: async () => (await api.get('/cash-boxes')).data.data, enabled: tab === 'payments' })

  const base = { supplier_id: '', warehouse_id: '', product_id: '', quantity: '10', unit_cost: '', batch_no: '', serial_no: '', currency: 'SYP', exchange_rate: '' }

  const [req, setReq] = useState({ request_date: new Date().toISOString().slice(0, 10), required_date: '', ...base })
  const [po, setPo] = useState({ order_date: new Date().toISOString().slice(0, 10), ...base })
  const [inv, setInv] = useState({ invoice_date: new Date().toISOString().slice(0, 10), status: 'posted', ...base })
  const [ret, setRet] = useState({
    return_date: new Date().toISOString().slice(0, 10),
    supplier_id: '',
    warehouse_id: '',
    purchase_invoice_id: '',
    product_id: '',
    quantity: '1',
    unit_cost: '',
    batch_no: '',
    serial_no: '',
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

  const invalidate = () => void qc.invalidateQueries({ queryKey: ['purchase-requests', 'purchase-orders', 'purchase-invoices', 'purchase-returns', 'stock-levels'] })

  const saveReq = useMutation({
    mutationFn: () => api.post('/purchase-requests', {
      request_date: req.request_date,
      required_date: req.required_date || undefined,
      supplier_id: Number(req.supplier_id) || undefined,
      warehouse_id: Number(req.warehouse_id) || undefined,
      lines: [purchaseLine(req.product_id, req.quantity, req.unit_cost, req.batch_no, req.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم حفظ طلب الشراء'); invalidate() },
    onError: msg.fromErr,
  })

  const savePo = useMutation({
    mutationFn: () => api.post('/purchase-orders', {
      order_date: po.order_date,
      supplier_id: Number(po.supplier_id),
      warehouse_id: Number(po.warehouse_id) || undefined,
      lines: [purchaseLine(po.product_id, po.quantity, po.unit_cost, po.batch_no, po.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم حفظ أمر الشراء'); invalidate() },
    onError: msg.fromErr,
  })

  const saveInv = useMutation({
    mutationFn: () => api.post('/purchase-invoices', {
      invoice_date: inv.invoice_date,
      supplier_id: Number(inv.supplier_id),
      warehouse_id: Number(inv.warehouse_id),
      currency: inv.currency,
      exchange_rate: inv.exchange_rate ? Number(inv.exchange_rate) : undefined,
      status: inv.status,
      lines: [purchaseLine(inv.product_id, inv.quantity, inv.unit_cost, inv.batch_no, inv.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم ترحيل فاتورة المشتريات'); invalidate() },
    onError: msg.fromErr,
  })

  const saveRet = useMutation({
    mutationFn: () => api.post('/purchase-returns', {
      return_date: ret.return_date,
      supplier_id: Number(ret.supplier_id),
      warehouse_id: Number(ret.warehouse_id) || undefined,
      purchase_invoice_id: ret.purchase_invoice_id ? Number(ret.purchase_invoice_id) : null,
      status: ret.status,
      lines: [{ product_id: Number(ret.product_id), quantity: Number(ret.quantity), unit_cost: Number(ret.unit_cost), batch_no: ret.batch_no || undefined, serial_no: ret.serial_no || undefined }],
    }),
    onSuccess: () => { msg.setMessage('تم ترحيل مرتجع المشتريات'); invalidate() },
    onError: msg.fromErr,
  })

  const convertReq = useMutation({
    mutationFn: (id: number) => api.post(`/purchase-requests/${id}/convert-to-order`),
    onSuccess: () => { msg.setMessage('تم التحويل لأمر شراء'); invalidate() },
    onError: msg.fromErr,
  })

  const convertPo = useMutation({
    mutationFn: (id: number) => api.post(`/purchase-orders/${id}/convert-to-invoice`, { status: 'posted' }),
    onSuccess: () => { msg.setMessage('تم التحويل لفاتورة'); invalidate() },
    onError: msg.fromErr,
  })

  const savePay = useMutation({
    mutationFn: () => api.post('/supplier-payments', {
      ...pay,
      supplier_id: Number(pay.supplier_id),
      purchase_invoice_id: pay.purchase_invoice_id ? Number(pay.purchase_invoice_id) : null,
      cash_box_id: pay.cash_box_id ? Number(pay.cash_box_id) : null,
      amount: Number(pay.amount),
    }),
    onSuccess: () => { msg.setMessage('تم ترحيل سند الصرف'); void qc.invalidateQueries({ queryKey: ['supplier-payments', 'purchase-invoices'] }) },
    onError: msg.fromErr,
  })

  const productFields = <T extends { product_id: string; quantity: string; unit_cost: string; batch_no: string; serial_no: string }>(
    state: T,
    setState: Dispatch<SetStateAction<T>>,
  ) => (
    <>
      <Field label={t('common.product')}><select className={inputClass} value={state.product_id} onChange={(e) => setState({ ...state, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
      <div className="grid grid-cols-2 gap-2">
        <Field label={t('common.quantity')}><input className={inputClass} value={state.quantity} onChange={(e) => setState({ ...state, quantity: e.target.value })} /></Field>
        <Field label={t('common.cost')}><input className={inputClass} value={state.unit_cost} onChange={(e) => setState({ ...state, unit_cost: e.target.value })} /></Field>
      </div>
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_batch && (
        <Field label={t('common.batch')}><input className={inputClass} value={state.batch_no} onChange={(e) => setState({ ...state, batch_no: e.target.value })} required /></Field>
      )}
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_serial && (
        <Field label={t('common.serial')}><input className={inputClass} value={state.serial_no} onChange={(e) => setState({ ...state, serial_no: e.target.value })} required /></Field>
      )}
    </>
  )

  const tabs = [
    { id: 'requests', label: t('purchases.requests') },
    { id: 'orders', label: t('purchases.orders') },
    { id: 'invoices', label: t('purchases.invoices') },
    { id: 'returns', label: t('purchases.returns') },
    { id: 'payments', label: t('purchases.payments') },
  ]

  return (
    <div className="space-y-6">
      <PageHeader title={t('purchases.title')} subtitle={t('purchases.subtitle')} />
      <Tabs tabs={tabs} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'requests' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(requests.data || []).map((r: { id: number; request_number: string; total: number; status: string; supplier?: { name: string } }) => (
                  <tr key={r.id}>
                    <td className="font-mono text-xs">{r.request_number}</td>
                    <td>{r.supplier?.name || '—'}</td>
                    <td>{r.total}</td>
                    <td>{r.status}</td>
                    <td>{r.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={() => convertReq.mutate(r.id)}>{t('purchases.convertToOrder')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveReq.mutate() }}>
            <h2 className="font-semibold">{t('purchases.newRequest')}</h2>
            <Field label={t('common.date')}><input type="date" className={inputClass} value={req.request_date} onChange={(e) => setReq({ ...req, request_date: e.target.value })} /></Field>
            <Field label={t('common.supplier')}><select className={inputClass} value={req.supplier_id} onChange={(e) => setReq({ ...req, supplier_id: e.target.value })}><option value="">—</option>{(suppliers.data || []).map((s: { id: number; name: string }) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
            <Field label={t('common.warehouse')}><select className={inputClass} value={req.warehouse_id} onChange={(e) => setReq({ ...req, warehouse_id: e.target.value })}><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            {productFields(req, setReq)}
            <button type="submit" className="btn btn-primary w-full">{t('common.save')}</button>
          </form>
        </div>
      )}

      {tab === 'orders' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(orders.data || []).map((o: { id: number; order_number: string; total: number; status: string; supplier?: { name: string } }) => (
                  <tr key={o.id}>
                    <td className="font-mono text-xs">{o.order_number}</td>
                    <td>{o.supplier?.name}</td>
                    <td>{o.total}</td>
                    <td>{o.status}</td>
                    <td>{o.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={() => convertPo.mutate(o.id)}>{t('purchases.convertToInvoice')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); savePo.mutate() }}>
            <h2 className="font-semibold">{t('purchases.newOrder')}</h2>
            <Field label={t('common.date')}><input type="date" className={inputClass} value={po.order_date} onChange={(e) => setPo({ ...po, order_date: e.target.value })} /></Field>
            <Field label={t('common.supplier')}><select className={inputClass} value={po.supplier_id} onChange={(e) => setPo({ ...po, supplier_id: e.target.value })} required><option value="">—</option>{(suppliers.data || []).map((s: { id: number; name: string }) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
            <Field label={t('common.warehouse')}><select className={inputClass} value={po.warehouse_id} onChange={(e) => setPo({ ...po, warehouse_id: e.target.value })}><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            {productFields(po, setPo)}
            <button type="submit" className="btn btn-primary w-full">{t('common.save')}</button>
          </form>
        </div>
      )}

      {tab === 'invoices' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th></tr></thead>
              <tbody>
                {(invoices.data || []).map((i: { id: number; invoice_number: string; total: number; status: string; supplier?: { name: string } }) => (
                  <tr key={i.id}><td className="font-mono text-xs">{i.invoice_number}</td><td>{i.supplier?.name}</td><td>{i.total}</td><td>{i.status}</td></tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveInv.mutate() }}>
            <h2 className="font-semibold">فاتورة مشتريات</h2>
            <Field label={t('common.date')}><input type="date" className={inputClass} value={inv.invoice_date} onChange={(e) => setInv({ ...inv, invoice_date: e.target.value })} /></Field>
            <Field label={t('common.supplier')}><select className={inputClass} value={inv.supplier_id} onChange={(e) => setInv({ ...inv, supplier_id: e.target.value })} required><option value="">—</option>{(suppliers.data || []).map((s: { id: number; name: string }) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
            <Field label={t('common.warehouse')}><select className={inputClass} value={inv.warehouse_id} onChange={(e) => setInv({ ...inv, warehouse_id: e.target.value })} required><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            {productFields(inv, setInv)}
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">{t('common.post')}</button>
          </form>
        </div>
      )}

      {tab === 'returns' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th></tr></thead>
              <tbody>
                {(returns.data || []).map((r: { id: number; return_number: string; total: number; status: string; supplier?: { name: string } }) => (
                  <tr key={r.id}><td className="font-mono text-xs">{r.return_number}</td><td>{r.supplier?.name}</td><td>{r.total}</td><td>{r.status}</td></tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveRet.mutate() }}>
            <h2 className="font-semibold">مرتجع مشتريات</h2>
            <Field label={t('common.date')}><input type="date" className={inputClass} value={ret.return_date} onChange={(e) => setRet({ ...ret, return_date: e.target.value })} /></Field>
            <Field label={t('common.supplier')}><select className={inputClass} value={ret.supplier_id} onChange={(e) => setRet({ ...ret, supplier_id: e.target.value })} required><option value="">—</option>{(suppliers.data || []).map((s: { id: number; name: string }) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
            <Field label={t('common.warehouse')}><select className={inputClass} value={ret.warehouse_id} onChange={(e) => setRet({ ...ret, warehouse_id: e.target.value })}><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            <Field label="فاتورة"><select className={inputClass} value={ret.purchase_invoice_id} onChange={(e) => setRet({ ...ret, purchase_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field>
            <Field label={t('common.product')}><select className={inputClass} value={ret.product_id} onChange={(e) => setRet({ ...ret, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label={t('common.quantity')}><input className={inputClass} value={ret.quantity} onChange={(e) => setRet({ ...ret, quantity: e.target.value })} /></Field>
              <Field label={t('common.cost')}><input className={inputClass} value={ret.unit_cost} onChange={(e) => setRet({ ...ret, unit_cost: e.target.value })} required /></Field>
            </div>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">{t('common.post')}</button>
          </form>
        </div>
      )}

      {tab === 'payments' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>مبلغ</th><th>{t('common.status')}</th></tr></thead>
              <tbody>
                {(payments.data || []).map((p: { id: number; payment_number: string; amount: number; status: string; supplier?: { name: string } }) => (
                  <tr key={p.id}><td className="font-mono text-xs">{p.payment_number}</td><td>{p.supplier?.name}</td><td>{p.amount}</td><td>{p.status}</td></tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); savePay.mutate() }}>
            <h2 className="font-semibold">سند صرف مورد</h2>
            <Field label={t('common.supplier')}><select className={inputClass} value={pay.supplier_id} onChange={(e) => setPay({ ...pay, supplier_id: e.target.value })} required><option value="">—</option>{(suppliers.data || []).map((s: { id: number; name: string }) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
            <Field label="فاتورة"><select className={inputClass} value={pay.purchase_invoice_id} onChange={(e) => setPay({ ...pay, purchase_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field>
            <Field label="صندوق"><select className={inputClass} value={pay.cash_box_id} onChange={(e) => setPay({ ...pay, cash_box_id: e.target.value })}><option value="">—</option>{(cashBoxes.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="المبلغ"><input className={inputClass} value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} required /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">{t('common.post')}</button>
          </form>
        </div>
      )}
    </div>
  )
}
