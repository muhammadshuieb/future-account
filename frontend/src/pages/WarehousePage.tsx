import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import BarcodeScanInput from '@/components/BarcodeScanInput'
import { Button, Field, Modal, Msg, NumericInput, PageHeader, Panel, Tabs, formatQuantity, inputClass, useFormMessage } from '@/components/ui'

type Tab = 'warehouses' | 'products' | 'categories' | 'units' | 'stock' | 'movements' | 'transfers' | 'alerts' | 'counts'

type StockLocation = { warehouse_id: number; warehouse_name: string; batch_no: string; quantity: number }

const emptyWh = { code: '', name: '', location: '' }
const emptyCat = { name: '', parent_id: '' }
const emptyUnit = { name: '', symbol: '' }
const emptyPr = {
  sku: '',
  barcode: '',
  name: '',
  category_id: '',
  unit_id: '',
  cost_price: '0',
  sale_price: '0',
  reorder_level: '0',
  track_batch: false,
  track_serial: false,
}
const emptyMv = {
  type: 'in',
  warehouse_id: '',
  product_id: '',
  quantity: '1',
  batch_no: '',
  serial_no: '',
  post_to_gl: false,
}
const emptyCnt = {
  count_date: new Date().toISOString().slice(0, 10),
  warehouse_id: '',
  product_id: '',
  counted_qty: '0',
}
const emptyTr = {
  transfer_date: new Date().toISOString().slice(0, 10),
  from_warehouse_id: '',
  to_warehouse_id: '',
  product_id: '',
  quantity: '1',
  status: 'posted',
}

export default function WarehousePage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>('warehouses')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [viewRow, setViewRow] = useState<Record<string, unknown> | null>(null)

  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data.data })
  const products = useQuery({ queryKey: ['products'], queryFn: async () => (await api.get('/products')).data.data })
  const categories = useQuery({ queryKey: ['categories'], queryFn: async () => (await api.get('/categories')).data.data })
  const units = useQuery({ queryKey: ['units'], queryFn: async () => (await api.get('/units')).data.data })
  const stock = useQuery({ queryKey: ['stock-levels'], queryFn: async () => (await api.get('/stock-levels')).data.data, enabled: tab === 'stock' })
  const movements = useQuery({ queryKey: ['stock-movements'], queryFn: async () => (await api.get('/stock-movements')).data.data, enabled: tab === 'movements' })
  const transfers = useQuery({ queryKey: ['warehouse-transfers'], queryFn: async () => (await api.get('/warehouse-transfers')).data.data, enabled: tab === 'transfers' })
  const alerts = useQuery({ queryKey: ['stock-alerts'], queryFn: async () => (await api.get('/stock-alerts')).data.data, enabled: tab === 'alerts' })
  const counts = useQuery({ queryKey: ['inventory-counts'], queryFn: async () => (await api.get('/inventory-counts')).data.data, enabled: tab === 'counts' })

  const [productFilter, setProductFilter] = useState('')
  const [whForm, setWhForm] = useState(emptyWh)
  const [catForm, setCatForm] = useState(emptyCat)
  const [unitForm, setUnitForm] = useState(emptyUnit)
  const [prForm, setPrForm] = useState(emptyPr)
  const [mvForm, setMvForm] = useState(emptyMv)
  const [cntForm, setCntForm] = useState(emptyCnt)
  const [trForm, setTrForm] = useState(emptyTr)

  const canAdd = !['stock', 'alerts'].includes(tab)

  function closeModal() {
    setModalOpen(false)
    setEditingId(null)
    setViewRow(null)
  }

  function openCreate() {
    setEditingId(null)
    setViewRow(null)
    if (tab === 'warehouses') setWhForm(emptyWh)
    if (tab === 'products') setPrForm(emptyPr)
    if (tab === 'categories') setCatForm(emptyCat)
    if (tab === 'units') setUnitForm(emptyUnit)
    if (tab === 'movements') setMvForm(emptyMv)
    if (tab === 'transfers') setTrForm(emptyTr)
    if (tab === 'counts') setCntForm(emptyCnt)
    setModalOpen(true)
  }

  async function handleProductBarcodeScan(code: string) {
    try {
      const res = await api.get(`/products?barcode=${encodeURIComponent(code)}`)
      const found = (res.data.data as { id: number; name: string; sku: string; barcode?: string; cost_price: number; sale_price: number; category_id?: number; unit_id?: number; reorder_level?: number; track_batch?: boolean; track_serial?: boolean }[])[0]
      if (!found) {
        msg.setError('لم يُعثر على صنف بهذا الباركود')
        return
      }
      setProductFilter(found.name)
      setEditingId(found.id)
      setPrForm({
        sku: found.sku,
        barcode: found.barcode || code,
        name: found.name,
        category_id: found.category_id ? String(found.category_id) : '',
        unit_id: found.unit_id ? String(found.unit_id) : '',
        cost_price: String(found.cost_price),
        sale_price: String(found.sale_price),
        reorder_level: String(found.reorder_level ?? 0),
        track_batch: !!found.track_batch,
        track_serial: !!found.track_serial,
      })
      setTab('products')
      setModalOpen(true)
      msg.setMessage(`تم العثور على: ${found.name}`)
    } catch {
      msg.setError('تعذر البحث بالباركود')
    }
  }

  const filteredProducts = (products.data || []).filter((p: { name: string; sku: string; barcode?: string }) => {
    if (!productFilter.trim()) return true
    const q = productFilter.trim().toLowerCase()
    return p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q) || (p.barcode || '').includes(q)
  })

  const saveWh = useMutation({
    mutationFn: () => {
      const payload = { ...whForm, is_active: true }
      if (editingId) return api.put(`/warehouses/${editingId}`, payload)
      return api.post('/warehouses', payload)
    },
    onSuccess: () => { msg.setMessage('تم حفظ المخزن'); closeModal(); void qc.invalidateQueries({ queryKey: ['warehouses'] }) },
    onError: msg.fromErr,
  })

  const saveCat = useMutation({
    mutationFn: () => {
      const payload = { name: catForm.name, parent_id: catForm.parent_id || null }
      if (editingId) return api.put(`/categories/${editingId}`, payload)
      return api.post('/categories', payload)
    },
    onSuccess: () => { msg.setMessage('تم حفظ التصنيف'); closeModal(); void qc.invalidateQueries({ queryKey: ['categories'] }) },
    onError: msg.fromErr,
  })

  const saveUnit = useMutation({
    mutationFn: () => {
      if (editingId) return api.put(`/units/${editingId}`, unitForm)
      return api.post('/units', unitForm)
    },
    onSuccess: () => { msg.setMessage('تم حفظ الوحدة'); closeModal(); void qc.invalidateQueries({ queryKey: ['units'] }) },
    onError: msg.fromErr,
  })

  const savePr = useMutation({
    mutationFn: () => {
      const payload = {
        ...prForm,
        category_id: prForm.category_id || null,
        unit_id: prForm.unit_id || null,
        cost_price: Number(prForm.cost_price),
        sale_price: Number(prForm.sale_price),
        reorder_level: Number(prForm.reorder_level),
        track_batch: prForm.track_batch,
        track_serial: prForm.track_serial,
        is_active: true,
      }
      if (editingId) return api.put(`/products/${editingId}`, payload)
      return api.post('/products', payload)
    },
    onSuccess: () => { msg.setMessage('تم حفظ الصنف'); closeModal(); void qc.invalidateQueries({ queryKey: ['products'] }) },
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
    onSuccess: () => { msg.setMessage('تم تسجيل الحركة'); closeModal(); void qc.invalidateQueries({ queryKey: ['stock-levels', 'stock-movements', 'stock-alerts'] }) },
    onError: msg.fromErr,
  })

  const saveCnt = useMutation({
    mutationFn: () =>
      api.post('/inventory-counts', {
        warehouse_id: Number(cntForm.warehouse_id),
        count_date: cntForm.count_date,
        lines: [{ product_id: Number(cntForm.product_id), counted_qty: Number(cntForm.counted_qty) }],
      }),
    onSuccess: () => { msg.setMessage('تم حفظ الجرد'); closeModal(); void qc.invalidateQueries({ queryKey: ['inventory-counts', 'stock-levels'] }) },
    onError: msg.fromErr,
  })

  const postCnt = useMutation({
    mutationFn: (id: number) => api.post(`/inventory-counts/${id}/post`),
    onSuccess: () => {
      msg.setMessage('تم ترحيل فروقات الجرد')
      void qc.invalidateQueries({ queryKey: ['inventory-counts', 'stock-levels', 'stock-movements'] })
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
    onSuccess: () => { msg.setMessage('تم التحويل'); closeModal(); void qc.invalidateQueries({ queryKey: ['warehouse-transfers', 'stock-levels'] }) },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-6">
      <PageHeader
        title="المخازن والمخزون"
        subtitle="مستودعات، أصناف، حركات، تحويلات، وتنبيهات إعادة الطلب"
        actions={canAdd ? <Button variant="primary" onClick={openCreate}>إضافة</Button> : undefined}
      />
      <Tabs
        tabs={[
          { id: 'warehouses', label: 'المخازن' },
          { id: 'products', label: 'الأصناف' },
          { id: 'categories', label: t('warehouse.categories') },
          { id: 'units', label: t('warehouse.units') },
          { id: 'stock', label: 'الأرصدة' },
          { id: 'movements', label: 'الحركات' },
          { id: 'transfers', label: 'التحويلات' },
          { id: 'counts', label: t('warehouse.counts') },
          { id: 'alerts', label: 'تنبيهات' },
        ]}
        active={tab}
        onChange={(id) => { setTab(id as Tab); closeModal() }}
      />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'warehouses' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">الرمز</th><th className="px-4 py-3">الاسم</th><th className="px-4 py-3">الموقع</th></tr>
            </thead>
            <tbody>
              {(warehouses.data || []).map((w: { id: number; code: string; name: string; location?: string }) => (
                <tr
                  key={w.id}
                  className="row-clickable border-t border-black/5"
                  onClick={() => {
                    setEditingId(w.id)
                    setWhForm({ code: w.code, name: w.name, location: w.location || '' })
                    setModalOpen(true)
                  }}
                  tabIndex={0}
                >
                  <td className="px-4 py-3 font-mono">{w.code}</td>
                  <td className="px-4 py-3">{w.name}</td>
                  <td className="px-4 py-3">{w.location || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'products' && (
        <Panel>
          <div className="border-b border-[var(--color-line)] p-4">
            <BarcodeScanInput
              label="بحث بالباركود"
              hint="امسح باركود الصنف للبحث أو فتح نموذج التعديل"
              onScan={(code) => void handleProductBarcodeScan(code)}
            />
            <div className="mt-3">
              <Field label="تصفية الأصناف">
                <input className={inputClass} value={productFilter} onChange={(e) => setProductFilter(e.target.value)} placeholder="اسم أو SKU أو باركود..." />
              </Field>
            </div>
          </div>
          <div className="table-wrap">
            <table className="data-table text-sm">
              <thead><tr><th>SKU</th><th>الاسم</th><th>تكلفة</th><th>بيع</th><th>رصيد</th><th>{t('sales.stockLocation')}</th></tr></thead>
              <tbody>
                {filteredProducts.map((p: { id: number; sku: string; name: string; barcode?: string; category_id?: number; unit_id?: number; cost_price: number; sale_price: number; reorder_level?: number; track_batch?: boolean; track_serial?: boolean; on_hand?: number; stock_locations?: StockLocation[] }) => (
                  <tr
                    key={p.id}
                    className="row-clickable"
                    onClick={() => {
                      setEditingId(p.id)
                      setPrForm({
                        sku: p.sku,
                        barcode: p.barcode || '',
                        name: p.name,
                        category_id: p.category_id ? String(p.category_id) : '',
                        unit_id: p.unit_id ? String(p.unit_id) : '',
                        cost_price: String(p.cost_price),
                        sale_price: String(p.sale_price),
                        reorder_level: String(p.reorder_level ?? 0),
                        track_batch: !!p.track_batch,
                        track_serial: !!p.track_serial,
                      })
                      setModalOpen(true)
                    }}
                    tabIndex={0}
                  >
                    <td className="font-mono">{p.sku}</td>
                    <td>{p.name}</td>
                    <td>{p.cost_price}</td>
                    <td>{p.sale_price}</td>
                    <td className="tabular-nums">{formatQuantity(p.on_hand ?? 0)}</td>
                    <td className="max-w-xs text-xs text-black/60">
                      {(p.stock_locations || []).length > 0
                        ? (p.stock_locations || []).map((loc, i) => (
                            <span key={`${loc.warehouse_id}-${loc.batch_no}-${i}`}>
                              {i > 0 ? '؛ ' : ''}
                              {loc.warehouse_name}: {formatQuantity(loc.quantity)}
                              {loc.batch_no ? ` (${t('common.batch')} ${loc.batch_no})` : ''}
                            </span>
                          ))
                        : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
      )}

      {tab === 'categories' && (
        <Panel>
          <table className="data-table">
            <thead><tr><th>{t('common.name')}</th><th>تصنيف أب</th></tr></thead>
            <tbody>
              {(categories.data || []).map((c: { id: number; name: string; parent_id?: number; parent?: { name: string } }) => (
                <tr
                  key={c.id}
                  className="row-clickable"
                  onClick={() => {
                    setEditingId(c.id)
                    setCatForm({ name: c.name, parent_id: c.parent_id ? String(c.parent_id) : '' })
                    setModalOpen(true)
                  }}
                  tabIndex={0}
                >
                  <td>{c.name}</td>
                  <td>{c.parent?.name || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'units' && (
        <Panel>
          <table className="data-table">
            <thead><tr><th>{t('common.name')}</th><th>رمز</th></tr></thead>
            <tbody>
              {(units.data || []).map((u: { id: number; name: string; symbol?: string }) => (
                <tr
                  key={u.id}
                  className="row-clickable"
                  onClick={() => {
                    setEditingId(u.id)
                    setUnitForm({ name: u.name, symbol: u.symbol || '' })
                    setModalOpen(true)
                  }}
                  tabIndex={0}
                >
                  <td>{u.name}</td>
                  <td>{u.symbol || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'stock' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">مخزن</th><th className="px-4 py-3">صنف</th><th className="px-4 py-3" title={t('common.quantityUnit')}>كمية</th><th className="px-4 py-3">دفعة</th></tr>
            </thead>
            <tbody>
              {(stock.data || []).map((s: { id: number; quantity: number; batch_no?: string; warehouse?: { name: string }; product?: { name: string; sku: string } }) => (
                <tr key={s.id} className="border-t border-black/5">
                  <td className="px-4 py-3">{s.warehouse?.name}</td>
                  <td className="px-4 py-3">{s.product?.sku} — {s.product?.name}</td>
                  <td className="px-4 py-3 tabular-nums">{formatQuantity(s.quantity)}</td>
                  <td className="px-4 py-3 font-mono text-xs">{s.batch_no || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'movements' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">نوع</th><th className="px-4 py-3">صنف</th><th className="px-4 py-3" title={t('common.quantityUnit')}>كمية</th><th className="px-4 py-3">دفعة/تسلسلي</th></tr>
            </thead>
            <tbody>
              {(movements.data || []).map((m: { id: number; movement_number: string; type: string; quantity: number; batch_no?: string; serial_no?: string; product?: { name: string } }) => (
                <tr
                  key={m.id}
                  className="row-clickable border-t border-black/5"
                  onClick={() => { setViewRow(m as unknown as Record<string, unknown>); setModalOpen(true) }}
                  tabIndex={0}
                >
                  <td className="px-4 py-3 font-mono text-xs">{m.movement_number}</td>
                  <td className="px-4 py-3">{m.type}</td>
                  <td className="px-4 py-3">{m.product?.name}</td>
                  <td className="px-4 py-3 tabular-nums">{formatQuantity(m.quantity)}</td>
                  <td className="px-4 py-3 font-mono text-xs">{m.batch_no || m.serial_no || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'transfers' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">من</th><th className="px-4 py-3">إلى</th><th className="px-4 py-3">حالة</th></tr>
            </thead>
            <tbody>
              {(transfers.data || []).map((tr: { id: number; transfer_number: string; status: string; from_warehouse?: { name: string }; to_warehouse?: { name: string } }) => (
                <tr
                  key={tr.id}
                  className="row-clickable border-t border-black/5"
                  onClick={() => { setViewRow(tr as unknown as Record<string, unknown>); setModalOpen(true) }}
                  tabIndex={0}
                >
                  <td className="px-4 py-3 font-mono text-xs">{tr.transfer_number}</td>
                  <td className="px-4 py-3">{tr.from_warehouse?.name}</td>
                  <td className="px-4 py-3">{tr.to_warehouse?.name}</td>
                  <td className="px-4 py-3">{tr.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'counts' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">مخزن</th><th className="px-4 py-3">تاريخ</th><th className="px-4 py-3">حالة</th><th className="px-4 py-3"></th></tr>
            </thead>
            <tbody>
              {(counts.data || []).map((c: { id: number; count_number: string; count_date: string; status: string; warehouse?: { name: string } }) => (
                <tr
                  key={c.id}
                  className="row-clickable border-t border-black/5"
                  onClick={() => { setViewRow(c as unknown as Record<string, unknown>); setModalOpen(true) }}
                  tabIndex={0}
                >
                  <td className="px-4 py-3 font-mono text-xs">{c.count_number}</td>
                  <td className="px-4 py-3">{c.warehouse?.name}</td>
                  <td className="px-4 py-3">{String(c.count_date).slice(0, 10)}</td>
                  <td className="px-4 py-3">{c.status}</td>
                  <td className="px-4 py-3">
                    {c.status === 'draft' && (
                      <button type="button" className="text-xs text-teal" onClick={(e) => { e.stopPropagation(); postCnt.mutate(c.id) }}>
                        {t('warehouse.postCount')}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'alerts' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">SKU</th><th className="px-4 py-3">الصنف</th><th className="px-4 py-3">الرصيد</th><th className="px-4 py-3">حد الطلب</th></tr>
            </thead>
            <tbody>
              {(alerts.data || []).map((a: { id: number; sku: string; name: string; on_hand: number; reorder_level: number }) => (
                <tr key={a.id} className="border-t border-black/5">
                  <td className="px-4 py-3 font-mono">{a.sku}</td>
                  <td className="px-4 py-3">{a.name}</td>
                  <td className="px-4 py-3 text-danger tabular-nums">{formatQuantity(a.on_hand)}</td>
                  <td className="px-4 py-3 tabular-nums">{formatQuantity(a.reorder_level)}</td>
                </tr>
              ))}
              {(alerts.data || []).length === 0 && (
                <tr><td className="px-4 py-6 text-black/50" colSpan={4}>لا توجد تنبيهات حالياً</td></tr>
              )}
            </tbody>
          </table>
        </Panel>
      )}

      {/* Create/Edit modals */}
      <Modal open={modalOpen && tab === 'warehouses'} onClose={closeModal} title={editingId ? 'تعديل مخزن' : 'مخزن جديد'} footer={<><Button variant="secondary" onClick={closeModal}>إلغاء</Button><Button variant="primary" disabled={saveWh.isPending} onClick={() => saveWh.mutate()}>حفظ</Button></>}>
        <div className="space-y-3">
          <Field label="الرمز"><input className={inputClass} value={whForm.code} onChange={(e) => setWhForm({ ...whForm, code: e.target.value })} required /></Field>
          <Field label="الاسم"><input className={inputClass} value={whForm.name} onChange={(e) => setWhForm({ ...whForm, name: e.target.value })} required /></Field>
          <Field label="الموقع"><input className={inputClass} value={whForm.location} onChange={(e) => setWhForm({ ...whForm, location: e.target.value })} /></Field>
        </div>
      </Modal>

      <Modal open={modalOpen && tab === 'products'} onClose={closeModal} title={editingId ? 'تعديل صنف' : 'صنف جديد'} size="lg" footer={<><Button variant="secondary" onClick={closeModal}>إلغاء</Button><Button variant="primary" disabled={savePr.isPending} onClick={() => savePr.mutate()}>حفظ</Button></>}>
        <div className="space-y-3">
          <Field label="SKU"><input className={inputClass} value={prForm.sku} onChange={(e) => setPrForm({ ...prForm, sku: e.target.value })} required /></Field>
          <Field label="باركود"><input className={inputClass} value={prForm.barcode} onChange={(e) => setPrForm({ ...prForm, barcode: e.target.value })} /></Field>
          <Field label="الاسم"><input className={inputClass} value={prForm.name} onChange={(e) => setPrForm({ ...prForm, name: e.target.value })} required /></Field>
          <Field label="فئة"><select className={inputClass} value={prForm.category_id} onChange={(e) => setPrForm({ ...prForm, category_id: e.target.value })}><option value="">—</option>{(categories.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
          <Field label="وحدة"><select className={inputClass} value={prForm.unit_id} onChange={(e) => setPrForm({ ...prForm, unit_id: e.target.value })}><option value="">—</option>{(units.data || []).map((u: { id: number; name: string }) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
          <div className="form-grid-3">
            <Field label="تكلفة"><NumericInput value={prForm.cost_price} onChange={(v) => setPrForm((prev) => ({ ...prev, cost_price: v }))} /></Field>
            <Field label="بيع"><NumericInput value={prForm.sale_price} onChange={(v) => setPrForm((prev) => ({ ...prev, sale_price: v }))} /></Field>
            <Field label="حد الطلب"><NumericInput value={prForm.reorder_level} onChange={(v) => setPrForm((prev) => ({ ...prev, reorder_level: v }))} /></Field>
          </div>
          <div className="flex flex-wrap gap-4 text-sm">
            <label className="flex items-center gap-2"><input type="checkbox" checked={prForm.track_batch} onChange={(e) => setPrForm({ ...prForm, track_batch: e.target.checked })} />{t('warehouse.trackBatch')}</label>
            <label className="flex items-center gap-2"><input type="checkbox" checked={prForm.track_serial} onChange={(e) => setPrForm({ ...prForm, track_serial: e.target.checked })} />{t('warehouse.trackSerial')}</label>
          </div>
        </div>
      </Modal>

      <Modal open={modalOpen && tab === 'categories'} onClose={closeModal} title={editingId ? 'تعديل تصنيف' : t('warehouse.newCategory')} footer={<><Button variant="secondary" onClick={closeModal}>إلغاء</Button><Button variant="primary" disabled={saveCat.isPending} onClick={() => saveCat.mutate()}>{t('common.save')}</Button></>}>
        <div className="space-y-3">
          <Field label={t('common.name')}><input className={inputClass} value={catForm.name} onChange={(e) => setCatForm({ ...catForm, name: e.target.value })} required /></Field>
          <Field label="تصنيف أب"><select className={inputClass} value={catForm.parent_id} onChange={(e) => setCatForm({ ...catForm, parent_id: e.target.value })}><option value="">—</option>{(categories.data || []).filter((c: { id: number }) => c.id !== editingId).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
        </div>
      </Modal>

      <Modal open={modalOpen && tab === 'units'} onClose={closeModal} title={editingId ? 'تعديل وحدة' : t('warehouse.newUnit')} footer={<><Button variant="secondary" onClick={closeModal}>إلغاء</Button><Button variant="primary" disabled={saveUnit.isPending} onClick={() => saveUnit.mutate()}>{t('common.save')}</Button></>}>
        <div className="space-y-3">
          <Field label={t('common.name')}><input className={inputClass} value={unitForm.name} onChange={(e) => setUnitForm({ ...unitForm, name: e.target.value })} required /></Field>
          <Field label="رمز"><input className={inputClass} value={unitForm.symbol} onChange={(e) => setUnitForm({ ...unitForm, symbol: e.target.value })} /></Field>
        </div>
      </Modal>

      <Modal open={modalOpen && tab === 'movements' && !viewRow} onClose={closeModal} title="حركة يدوية" footer={<><Button variant="secondary" onClick={closeModal}>إلغاء</Button><Button variant="primary" disabled={saveMv.isPending} onClick={() => saveMv.mutate()}>تسجيل</Button></>}>
        <div className="space-y-3">
          <Field label="النوع"><select className={inputClass} value={mvForm.type} onChange={(e) => setMvForm({ ...mvForm, type: e.target.value })}><option value="in">وارد</option><option value="out">منصرف</option><option value="adjustment">تسوية</option></select></Field>
          <Field label="مخزن"><select className={inputClass} value={mvForm.warehouse_id} onChange={(e) => setMvForm({ ...mvForm, warehouse_id: e.target.value })} required><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
          <Field label="صنف"><select className={inputClass} value={mvForm.product_id} onChange={(e) => setMvForm({ ...mvForm, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p: { id: number; name: string }) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
          <Field label="كمية"><NumericInput value={mvForm.quantity} onChange={(v) => setMvForm((prev) => ({ ...prev, quantity: v }))} /></Field>
          <Field label={t('common.batch')}><input className={inputClass} value={mvForm.batch_no} onChange={(e) => setMvForm({ ...mvForm, batch_no: e.target.value })} /></Field>
          <Field label={t('common.serial')}><input className={inputClass} value={mvForm.serial_no} onChange={(e) => setMvForm({ ...mvForm, serial_no: e.target.value })} /></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={mvForm.post_to_gl} onChange={(e) => setMvForm({ ...mvForm, post_to_gl: e.target.checked })} />ترحيل محاسبي</label>
        </div>
      </Modal>

      <Modal open={modalOpen && tab === 'movements' && !!viewRow} onClose={closeModal} title="عرض حركة" footer={<Button variant="secondary" onClick={closeModal}>إغلاق</Button>}>
        {viewRow && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-black/50">رقم</dt><dd className="font-mono">{String(viewRow.movement_number)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">نوع</dt><dd>{String(viewRow.type)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">صنف</dt><dd>{(viewRow.product as { name?: string } | undefined)?.name}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">كمية</dt><dd className="tabular-nums">{formatQuantity(viewRow.quantity as number)}</dd></div>
          </dl>
        )}
      </Modal>

      <Modal open={modalOpen && tab === 'transfers' && !viewRow} onClose={closeModal} title="تحويل بين مخازن" footer={<><Button variant="secondary" onClick={closeModal}>إلغاء</Button><Button variant="primary" disabled={saveTr.isPending} onClick={() => saveTr.mutate()}>ترحيل التحويل</Button></>}>
        <div className="space-y-3">
          <Field label="التاريخ"><input type="date" className={inputClass} value={trForm.transfer_date} onChange={(e) => setTrForm({ ...trForm, transfer_date: e.target.value })} /></Field>
          <Field label="من"><select className={inputClass} value={trForm.from_warehouse_id} onChange={(e) => setTrForm({ ...trForm, from_warehouse_id: e.target.value })} required><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
          <Field label="إلى"><select className={inputClass} value={trForm.to_warehouse_id} onChange={(e) => setTrForm({ ...trForm, to_warehouse_id: e.target.value })} required><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
          <Field label="صنف"><select className={inputClass} value={trForm.product_id} onChange={(e) => setTrForm({ ...trForm, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p: { id: number; name: string }) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
          <Field label="كمية"><NumericInput value={trForm.quantity} onChange={(v) => setTrForm((prev) => ({ ...prev, quantity: v }))} /></Field>
        </div>
      </Modal>

      <Modal open={modalOpen && tab === 'transfers' && !!viewRow} onClose={closeModal} title="عرض تحويل" footer={<Button variant="secondary" onClick={closeModal}>إغلاق</Button>}>
        {viewRow && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-black/50">رقم</dt><dd className="font-mono">{String(viewRow.transfer_number)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">من</dt><dd>{(viewRow.from_warehouse as { name?: string } | undefined)?.name}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">إلى</dt><dd>{(viewRow.to_warehouse as { name?: string } | undefined)?.name}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">حالة</dt><dd>{String(viewRow.status)}</dd></div>
          </dl>
        )}
      </Modal>

      <Modal open={modalOpen && tab === 'counts' && !viewRow} onClose={closeModal} title={t('warehouse.newCount')} footer={<><Button variant="secondary" onClick={closeModal}>إلغاء</Button><Button variant="primary" disabled={saveCnt.isPending} onClick={() => saveCnt.mutate()}>{t('common.save')}</Button></>}>
        <div className="space-y-3">
          <Field label={t('common.date')}><input type="date" className={inputClass} value={cntForm.count_date} onChange={(e) => setCntForm({ ...cntForm, count_date: e.target.value })} /></Field>
          <Field label={t('common.warehouse')}><select className={inputClass} value={cntForm.warehouse_id} onChange={(e) => setCntForm({ ...cntForm, warehouse_id: e.target.value })} required><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
          <Field label={t('common.product')}><select className={inputClass} value={cntForm.product_id} onChange={(e) => setCntForm({ ...cntForm, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p: { id: number; name: string }) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
          <Field label="الكمية المعدودة"><NumericInput value={cntForm.counted_qty} onChange={(v) => setCntForm((prev) => ({ ...prev, counted_qty: v }))} /></Field>
        </div>
      </Modal>

      <Modal open={modalOpen && tab === 'counts' && !!viewRow} onClose={closeModal} title="عرض جرد" footer={<Button variant="secondary" onClick={closeModal}>إغلاق</Button>}>
        {viewRow && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-black/50">رقم</dt><dd className="font-mono">{String(viewRow.count_number)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">مخزن</dt><dd>{(viewRow.warehouse as { name?: string } | undefined)?.name}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">تاريخ</dt><dd>{String(viewRow.count_date).slice(0, 10)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">حالة</dt><dd>{String(viewRow.status)}</dd></div>
          </dl>
        )}
      </Modal>
    </div>
  )
}
