import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Field, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

type Tab = 'warehouses' | 'products' | 'stock' | 'movements' | 'transfers' | 'alerts'

export default function WarehousePage() {
  const [tab, setTab] = useState<Tab>('warehouses')
  const qc = useQueryClient()
  const msg = useFormMessage()

  const warehouses = useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.get('/warehouses')).data.data,
  })
  const products = useQuery({
    queryKey: ['products'],
    queryFn: async () => (await api.get('/products')).data.data,
  })
  const categories = useQuery({
    queryKey: ['categories'],
    queryFn: async () => (await api.get('/categories')).data.data,
  })
  const units = useQuery({
    queryKey: ['units'],
    queryFn: async () => (await api.get('/units')).data.data,
  })
  const stock = useQuery({
    queryKey: ['stock-levels'],
    queryFn: async () => (await api.get('/stock-levels')).data.data,
    enabled: tab === 'stock',
  })
  const movements = useQuery({
    queryKey: ['stock-movements'],
    queryFn: async () => (await api.get('/stock-movements')).data.data,
    enabled: tab === 'movements',
  })
  const transfers = useQuery({
    queryKey: ['warehouse-transfers'],
    queryFn: async () => (await api.get('/warehouse-transfers')).data.data,
    enabled: tab === 'transfers',
  })
  const alerts = useQuery({
    queryKey: ['stock-alerts'],
    queryFn: async () => (await api.get('/stock-alerts')).data.data,
    enabled: tab === 'alerts',
  })

  const [whForm, setWhForm] = useState({ code: '', name: '', location: '' })
  const [prForm, setPrForm] = useState({
    sku: '',
    barcode: '',
    name: '',
    category_id: '',
    unit_id: '',
    cost_price: '0',
    sale_price: '0',
    reorder_level: '0',
  })
  const [mvForm, setMvForm] = useState({
    type: 'in',
    warehouse_id: '',
    product_id: '',
    quantity: '1',
    post_to_gl: false,
  })
  const [trForm, setTrForm] = useState({
    transfer_date: new Date().toISOString().slice(0, 10),
    from_warehouse_id: '',
    to_warehouse_id: '',
    product_id: '',
    quantity: '1',
    status: 'posted',
  })

  const saveWh = useMutation({
    mutationFn: () => api.post('/warehouses', { ...whForm, is_active: true }),
    onSuccess: () => {
      msg.setMessage('تم حفظ المخزن')
      setWhForm({ code: '', name: '', location: '' })
      void qc.invalidateQueries({ queryKey: ['warehouses'] })
    },
    onError: msg.fromErr,
  })

  const savePr = useMutation({
    mutationFn: () =>
      api.post('/products', {
        ...prForm,
        category_id: prForm.category_id || null,
        unit_id: prForm.unit_id || null,
        cost_price: Number(prForm.cost_price),
        sale_price: Number(prForm.sale_price),
        reorder_level: Number(prForm.reorder_level),
        is_active: true,
      }),
    onSuccess: () => {
      msg.setMessage('تم حفظ الصنف')
      void qc.invalidateQueries({ queryKey: ['products'] })
    },
    onError: msg.fromErr,
  })

  const saveMv = useMutation({
    mutationFn: () =>
      api.post('/stock-movements', {
        ...mvForm,
        warehouse_id: Number(mvForm.warehouse_id),
        product_id: Number(mvForm.product_id),
        quantity: Number(mvForm.quantity),
      }),
    onSuccess: () => {
      msg.setMessage('تم تسجيل الحركة')
      void qc.invalidateQueries({ queryKey: ['stock-levels', 'stock-movements', 'stock-alerts'] })
    },
    onError: msg.fromErr,
  })

  const saveTr = useMutation({
    mutationFn: () =>
      api.post('/warehouse-transfers', {
        transfer_date: trForm.transfer_date,
        from_warehouse_id: Number(trForm.from_warehouse_id),
        to_warehouse_id: Number(trForm.to_warehouse_id),
        status: trForm.status,
        lines: [{ product_id: Number(trForm.product_id), quantity: Number(trForm.quantity) }],
      }),
    onSuccess: () => {
      msg.setMessage('تم التحويل')
      void qc.invalidateQueries({ queryKey: ['warehouse-transfers', 'stock-levels'] })
    },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-6">
      <PageHeader title="المخازن والمخزون" subtitle="مستودعات، أصناف، حركات، تحويلات، وتنبيهات إعادة الطلب" />
      <Tabs
        tabs={[
          { id: 'warehouses', label: 'المخازن' },
          { id: 'products', label: 'الأصناف' },
          { id: 'stock', label: 'الأرصدة' },
          { id: 'movements', label: 'الحركات' },
          { id: 'transfers', label: 'التحويلات' },
          { id: 'alerts', label: 'تنبيهات' },
        ]}
        active={tab}
        onChange={(id) => setTab(id as Tab)}
      />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'warehouses' && (
        <div className="grid gap-6 lg:grid-cols-[1fr_0.7fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr>
                  <th className="px-4 py-3">الرمز</th>
                  <th className="px-4 py-3">الاسم</th>
                  <th className="px-4 py-3">الموقع</th>
                </tr>
              </thead>
              <tbody>
                {(warehouses.data || []).map((w: { id: number; code: string; name: string; location?: string }) => (
                  <tr key={w.id} className="border-t border-black/5">
                    <td className="px-4 py-3 font-mono">{w.code}</td>
                    <td className="px-4 py-3">{w.name}</td>
                    <td className="px-4 py-3">{w.location || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form
            className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm"
            onSubmit={(e) => {
              e.preventDefault()
              saveWh.mutate()
            }}
          >
            <h2 className="font-semibold">مخزن جديد</h2>
            <Field label="الرمز"><input className={inputClass} value={whForm.code} onChange={(e) => setWhForm({ ...whForm, code: e.target.value })} required /></Field>
            <Field label="الاسم"><input className={inputClass} value={whForm.name} onChange={(e) => setWhForm({ ...whForm, name: e.target.value })} required /></Field>
            <Field label="الموقع"><input className={inputClass} value={whForm.location} onChange={(e) => setWhForm({ ...whForm, location: e.target.value })} /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">حفظ</button>
          </form>
        </div>
      )}

      {tab === 'products' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr>
                  <th className="px-4 py-3">SKU</th>
                  <th className="px-4 py-3">الاسم</th>
                  <th className="px-4 py-3">تكلفة</th>
                  <th className="px-4 py-3">بيع</th>
                  <th className="px-4 py-3">رصيد</th>
                </tr>
              </thead>
              <tbody>
                {(products.data || []).map((p: { id: number; sku: string; name: string; cost_price: number; sale_price: number; on_hand?: number }) => (
                  <tr key={p.id} className="border-t border-black/5">
                    <td className="px-4 py-3 font-mono">{p.sku}</td>
                    <td className="px-4 py-3">{p.name}</td>
                    <td className="px-4 py-3">{p.cost_price}</td>
                    <td className="px-4 py-3">{p.sale_price}</td>
                    <td className="px-4 py-3">{p.on_hand ?? 0}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form
            className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm"
            onSubmit={(e) => {
              e.preventDefault()
              savePr.mutate()
            }}
          >
            <h2 className="font-semibold">صنف جديد</h2>
            <Field label="SKU"><input className={inputClass} value={prForm.sku} onChange={(e) => setPrForm({ ...prForm, sku: e.target.value })} required /></Field>
            <Field label="باركود"><input className={inputClass} value={prForm.barcode} onChange={(e) => setPrForm({ ...prForm, barcode: e.target.value })} /></Field>
            <Field label="الاسم"><input className={inputClass} value={prForm.name} onChange={(e) => setPrForm({ ...prForm, name: e.target.value })} required /></Field>
            <Field label="فئة">
              <select className={inputClass} value={prForm.category_id} onChange={(e) => setPrForm({ ...prForm, category_id: e.target.value })}>
                <option value="">—</option>
                {(categories.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <Field label="وحدة">
              <select className={inputClass} value={prForm.unit_id} onChange={(e) => setPrForm({ ...prForm, unit_id: e.target.value })}>
                <option value="">—</option>
                {(units.data || []).map((u: { id: number; name: string }) => <option key={u.id} value={u.id}>{u.name}</option>)}
              </select>
            </Field>
            <div className="grid grid-cols-3 gap-2">
              <Field label="تكلفة"><input className={inputClass} value={prForm.cost_price} onChange={(e) => setPrForm({ ...prForm, cost_price: e.target.value })} /></Field>
              <Field label="بيع"><input className={inputClass} value={prForm.sale_price} onChange={(e) => setPrForm({ ...prForm, sale_price: e.target.value })} /></Field>
              <Field label="حد الطلب"><input className={inputClass} value={prForm.reorder_level} onChange={(e) => setPrForm({ ...prForm, reorder_level: e.target.value })} /></Field>
            </div>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">حفظ</button>
          </form>
        </div>
      )}

      {tab === 'stock' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr>
                <th className="px-4 py-3">مخزن</th>
                <th className="px-4 py-3">صنف</th>
                <th className="px-4 py-3">كمية</th>
              </tr>
            </thead>
            <tbody>
              {(stock.data || []).map((s: { id: number; quantity: number; warehouse?: { name: string }; product?: { name: string; sku: string } }) => (
                <tr key={s.id} className="border-t border-black/5">
                  <td className="px-4 py-3">{s.warehouse?.name}</td>
                  <td className="px-4 py-3">{s.product?.sku} — {s.product?.name}</td>
                  <td className="px-4 py-3">{s.quantity}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'movements' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr>
                  <th className="px-4 py-3">رقم</th>
                  <th className="px-4 py-3">نوع</th>
                  <th className="px-4 py-3">صنف</th>
                  <th className="px-4 py-3">كمية</th>
                </tr>
              </thead>
              <tbody>
                {(movements.data || []).map((m: { id: number; movement_number: string; type: string; quantity: number; product?: { name: string } }) => (
                  <tr key={m.id} className="border-t border-black/5">
                    <td className="px-4 py-3 font-mono text-xs">{m.movement_number}</td>
                    <td className="px-4 py-3">{m.type}</td>
                    <td className="px-4 py-3">{m.product?.name}</td>
                    <td className="px-4 py-3">{m.quantity}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveMv.mutate() }}>
            <h2 className="font-semibold">حركة يدوية</h2>
            <Field label="النوع">
              <select className={inputClass} value={mvForm.type} onChange={(e) => setMvForm({ ...mvForm, type: e.target.value })}>
                <option value="in">وارد</option>
                <option value="out">منصرف</option>
                <option value="adjustment">تسوية</option>
              </select>
            </Field>
            <Field label="مخزن">
              <select className={inputClass} value={mvForm.warehouse_id} onChange={(e) => setMvForm({ ...mvForm, warehouse_id: e.target.value })} required>
                <option value="">—</option>
                {(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}
              </select>
            </Field>
            <Field label="صنف">
              <select className={inputClass} value={mvForm.product_id} onChange={(e) => setMvForm({ ...mvForm, product_id: e.target.value })} required>
                <option value="">—</option>
                {(products.data || []).map((p: { id: number; name: string }) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </Field>
            <Field label="كمية"><input className={inputClass} value={mvForm.quantity} onChange={(e) => setMvForm({ ...mvForm, quantity: e.target.value })} /></Field>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={mvForm.post_to_gl} onChange={(e) => setMvForm({ ...mvForm, post_to_gl: e.target.checked })} />
              ترحيل محاسبي
            </label>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">تسجيل</button>
          </form>
        </div>
      )}

      {tab === 'transfers' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr>
                  <th className="px-4 py-3">رقم</th>
                  <th className="px-4 py-3">من</th>
                  <th className="px-4 py-3">إلى</th>
                  <th className="px-4 py-3">حالة</th>
                </tr>
              </thead>
              <tbody>
                {(transfers.data || []).map((t: { id: number; transfer_number: string; status: string; from_warehouse?: { name: string }; to_warehouse?: { name: string } }) => (
                  <tr key={t.id} className="border-t border-black/5">
                    <td className="px-4 py-3 font-mono text-xs">{t.transfer_number}</td>
                    <td className="px-4 py-3">{t.from_warehouse?.name}</td>
                    <td className="px-4 py-3">{t.to_warehouse?.name}</td>
                    <td className="px-4 py-3">{t.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveTr.mutate() }}>
            <h2 className="font-semibold">تحويل بين مخازن</h2>
            <Field label="التاريخ"><input type="date" className={inputClass} value={trForm.transfer_date} onChange={(e) => setTrForm({ ...trForm, transfer_date: e.target.value })} /></Field>
            <Field label="من">
              <select className={inputClass} value={trForm.from_warehouse_id} onChange={(e) => setTrForm({ ...trForm, from_warehouse_id: e.target.value })} required>
                <option value="">—</option>
                {(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}
              </select>
            </Field>
            <Field label="إلى">
              <select className={inputClass} value={trForm.to_warehouse_id} onChange={(e) => setTrForm({ ...trForm, to_warehouse_id: e.target.value })} required>
                <option value="">—</option>
                {(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}
              </select>
            </Field>
            <Field label="صنف">
              <select className={inputClass} value={trForm.product_id} onChange={(e) => setTrForm({ ...trForm, product_id: e.target.value })} required>
                <option value="">—</option>
                {(products.data || []).map((p: { id: number; name: string }) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </Field>
            <Field label="كمية"><input className={inputClass} value={trForm.quantity} onChange={(e) => setTrForm({ ...trForm, quantity: e.target.value })} /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">ترحيل التحويل</button>
          </form>
        </div>
      )}

      {tab === 'alerts' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr>
                <th className="px-4 py-3">SKU</th>
                <th className="px-4 py-3">الصنف</th>
                <th className="px-4 py-3">الرصيد</th>
                <th className="px-4 py-3">حد الطلب</th>
              </tr>
            </thead>
            <tbody>
              {(alerts.data || []).map((a: { id: number; sku: string; name: string; on_hand: number; reorder_level: number }) => (
                <tr key={a.id} className="border-t border-black/5">
                  <td className="px-4 py-3 font-mono">{a.sku}</td>
                  <td className="px-4 py-3">{a.name}</td>
                  <td className="px-4 py-3 text-danger">{a.on_hand}</td>
                  <td className="px-4 py-3">{a.reorder_level}</td>
                </tr>
              ))}
              {(alerts.data || []).length === 0 && (
                <tr><td className="px-4 py-6 text-black/50" colSpan={4}>لا توجد تنبيهات حالياً</td></tr>
              )}
            </tbody>
          </table>
        </Panel>
      )}
    </div>
  )
}
