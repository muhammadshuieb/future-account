import { useEffect, useState, type Dispatch, type SetStateAction } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { Button, Field, Modal, Msg, NumericInput, PageHeader, Panel, Tabs, formatQuantity, inputClass, useFormMessage } from '@/components/ui'

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
  const [modal, setModal] = useState<'create' | 'view' | 'edit' | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [selectedRow, setSelectedRow] = useState<Record<string, unknown> | null>(null)

  const requests = useQuery({ queryKey: ['purchase-requests'], queryFn: async () => (await api.get('/purchase-requests')).data.data, enabled: tab === 'requests' })
  const orders = useQuery({ queryKey: ['purchase-orders'], queryFn: async () => (await api.get('/purchase-orders')).data.data, enabled: tab === 'orders' })
  const invoices = useQuery({ queryKey: ['purchase-invoices'], queryFn: async () => (await api.get('/purchase-invoices')).data.data })
  const returns = useQuery({ queryKey: ['purchase-returns'], queryFn: async () => (await api.get('/purchase-returns')).data.data, enabled: tab === 'returns' })
  const payments = useQuery({ queryKey: ['supplier-payments'], queryFn: async () => (await api.get('/supplier-payments')).data.data, enabled: tab === 'payments' })
  const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: async () => (await api.get('/suppliers')).data.data })
  const products = useQuery({ queryKey: ['products'], queryFn: async () => (await api.get('/products')).data.data as ProductRow[] })
  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data.data })
  const settings = useQuery({ queryKey: ['settings'], queryFn: async () => (await api.get('/settings')).data.data as { key: string; value: string }[] })
  const defaultWarehouseId = settings.data?.find((s) => s.key === 'default_warehouse_id')?.value || ''
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

  const invalidate = () => void qc.invalidateQueries({ queryKey: ['purchase-requests', 'purchase-orders', 'purchase-invoices', 'purchase-returns', 'supplier-payments', 'stock-levels'] })
  const closeModal = () => { setModal(null); setSelectedId(null); setSelectedRow(null) }

  const purchaseDeletePath = (rowTab: string, id: number) => {
    if (rowTab === 'requests') return `/purchase-requests/${id}`
    if (rowTab === 'orders') return `/purchase-orders/${id}`
    if (rowTab === 'invoices') return `/purchase-invoices/${id}`
    if (rowTab === 'returns') return `/purchase-returns/${id}`
    return `/supplier-payments/${id}`
  }

  const canDeletePurchase = (rowTab: string, status: string) => {
    if (rowTab === 'requests' || rowTab === 'orders') return status !== 'converted'
    return status === 'draft'
  }

  const deleteDoc = useMutation({
    mutationFn: ({ path }: { path: string }) => api.delete(path),
    onSuccess: () => {
      msg.setMessage(t('common.deleted'))
      invalidate()
      closeModal()
    },
    onError: msg.fromErr,
  })

  const askDelete = (rowTab: string, id: number, status: string) => {
    if (!canDeletePurchase(rowTab, status)) return
    if (!window.confirm(t('common.confirmDelete'))) return
    deleteDoc.mutate({ path: purchaseDeletePath(rowTab, id) })
  }
  const openCreate = () => {
    setSelectedId(null)
    setSelectedRow(null)
    if (defaultWarehouseId) {
      setReq((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
      setPo((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
      setInv((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
    }
    setModal('create')
  }
  const openRow = (row: Record<string, unknown> & { id: number }, editable = false) => { setSelectedId(row.id); setSelectedRow(row); setModal(editable ? 'edit' : 'view') }

  const saveReq = useMutation({
    mutationFn: () => api.post('/purchase-requests', {
      request_date: req.request_date,
      required_date: req.required_date || undefined,
      supplier_id: Number(req.supplier_id) || undefined,
      warehouse_id: Number(req.warehouse_id) || undefined,
      lines: [purchaseLine(req.product_id, req.quantity, req.unit_cost, req.batch_no, req.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم حفظ طلب الشراء'); invalidate(); closeModal() },
    onError: msg.fromErr,
  })

  const savePo = useMutation({
    mutationFn: () => api.post('/purchase-orders', {
      order_date: po.order_date,
      supplier_id: Number(po.supplier_id),
      warehouse_id: Number(po.warehouse_id) || undefined,
      lines: [purchaseLine(po.product_id, po.quantity, po.unit_cost, po.batch_no, po.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم حفظ أمر الشراء'); invalidate(); closeModal() },
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
    onSuccess: () => { msg.setMessage('تم ترحيل فاتورة المشتريات'); invalidate(); closeModal() },
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
    onSuccess: () => { msg.setMessage('تم ترحيل مرتجع المشتريات'); invalidate(); closeModal() },
    onError: msg.fromErr,
  })

  const convertReq = useMutation({
    mutationFn: (id: number) => api.post(`/purchase-requests/${id}/convert-to-order`),
    onSuccess: () => { msg.setMessage('تم التحويل لأمر شراء'); invalidate() },
    onError: msg.fromErr,
  })

  const updateReq = useMutation({
    mutationFn: (id: number) => api.put(`/purchase-requests/${id}`, {
      request_date: req.request_date,
      required_date: req.required_date || undefined,
      supplier_id: Number(req.supplier_id) || undefined,
      warehouse_id: Number(req.warehouse_id) || undefined,
      lines: [purchaseLine(req.product_id, req.quantity, req.unit_cost, req.batch_no, req.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم تحديث طلب الشراء'); invalidate(); closeModal() },
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
    onSuccess: () => { msg.setMessage('تم ترحيل سند الصرف'); void qc.invalidateQueries({ queryKey: ['supplier-payments', 'purchase-invoices'] }); closeModal() },
    onError: msg.fromErr,
  })

  const productFields = <T extends { product_id: string; quantity: string; unit_cost: string; batch_no: string; serial_no: string }>(
    state: T,
    setState: Dispatch<SetStateAction<T>>,
  ) => (
    <>
      <Field label={t('common.product')}><select className={inputClass} value={state.product_id} onChange={(e) => setState({ ...state, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
      <div className="grid grid-cols-2 gap-2">
        <Field label={t('common.quantity')} hint={t('common.quantityUnit')}><NumericInput value={state.quantity} onChange={(v) => setState((prev) => ({ ...prev, quantity: v }))} /></Field>
        <Field label={t('common.cost')}><NumericInput value={state.unit_cost} onChange={(v) => setState((prev) => ({ ...prev, unit_cost: v }))} /></Field>
      </div>
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_batch && (
        <Field label={t('common.batch')}><input className={inputClass} value={state.batch_no} onChange={(e) => setState({ ...state, batch_no: e.target.value })} required /></Field>
      )}
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_serial && (
        <Field label={t('common.serial')}><input className={inputClass} value={state.serial_no} onChange={(e) => setState({ ...state, serial_no: e.target.value })} required /></Field>
      )}
    </>
  )

  const detailPath = tab === 'requests' ? 'purchase-requests' : tab === 'orders' ? 'purchase-orders' : tab === 'invoices' ? 'purchase-invoices' : tab === 'returns' ? 'purchase-returns' : 'supplier-payments'
  const hasDetailEndpoint = tab !== 'returns' && tab !== 'payments'
  const detail = useQuery({
    queryKey: ['purchase-detail', tab, selectedId],
    enabled: !!selectedId && modal !== null && hasDetailEndpoint,
    queryFn: async () => (await api.get(`/${detailPath}/${selectedId}`)).data.data,
  })

  useEffect(() => {
    if (modal !== 'edit' || !detail.data) return
    const data = detail.data
    const line = data.items?.[0] || data.lines?.[0] || {}
    setReq({
      request_date: String(data.request_date || '').slice(0, 10),
      required_date: String(data.required_date || '').slice(0, 10),
      supplier_id: String(data.supplier_id || data.supplier?.id || ''),
      warehouse_id: String(data.warehouse_id || data.warehouse?.id || ''),
      product_id: String(line.product_id || line.product?.id || ''),
      quantity: String(line.quantity || 1),
      unit_cost: String(line.unit_cost || ''),
      batch_no: line.batch_no || '',
      serial_no: line.serial_no || '',
      currency: data.currency || 'SYP',
      exchange_rate: String(data.exchange_rate || ''),
    })
  }, [detail.data, modal])

  const supplierFields = <T extends { supplier_id: string; warehouse_id?: string }>(state: T, setState: Dispatch<SetStateAction<T>>, warehouse = true) => (
    <>
      <Field label={t('common.supplier')}><select className={inputClass} value={state.supplier_id} onChange={(e) => setState({ ...state, supplier_id: e.target.value })} required><option value="">—</option>{(suppliers.data || []).map((s: { id: number; name: string }) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
      {warehouse && <Field label={t('common.warehouse')}><select className={inputClass} value={state.warehouse_id} onChange={(e) => setState({ ...state, warehouse_id: e.target.value })}><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>}
    </>
  )

  const summary = (data: Record<string, any>) => <div className="space-y-3 text-sm"><div className="grid gap-3 sm:grid-cols-2"><p><b>رقم:</b> {data.request_number || data.order_number || data.invoice_number || data.return_number || data.payment_number || '—'}</p><p><b>{t('common.status')}:</b> {data.status || '—'}</p><p><b>{t('common.supplier')}:</b> {data.supplier?.name || '—'}</p><p><b>{t('common.total')}:</b> {data.total || data.amount || '—'}</p></div>{(data.items || data.lines)?.length > 0 && <div className="table-wrap"><table className="data-table"><thead><tr><th>{t('common.product')}</th><th title={t('common.quantityUnit')}>{t('common.quantity')}</th><th>{t('common.total')}</th></tr></thead><tbody>{(data.items || data.lines).map((line: any, index: number) => <tr key={index}><td>{line.product?.name}</td><td className="tabular-nums">{formatQuantity(line.quantity)}</td><td>{line.line_total}</td></tr>)}</tbody></table></div>}</div>

  const tabs = [
    { id: 'requests', label: t('purchases.requests') },
    { id: 'orders', label: t('purchases.orders') },
    { id: 'invoices', label: t('purchases.invoices') },
    { id: 'returns', label: t('purchases.returns') },
    { id: 'payments', label: t('purchases.payments') },
  ]

  return (
    <div className="space-y-6">
      <PageHeader title={t('purchases.title')} subtitle={t('purchases.subtitle')} actions={<Button variant="primary" onClick={openCreate}>{t('common.add')}</Button>} />
      <Tabs tabs={tabs} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'requests' && (
        <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(requests.data || []).map((r: { id: number; request_number: string; total: number; status: string; supplier?: { name: string } }) => (
                  <tr key={r.id} className="cursor-pointer" onClick={() => openRow(r, r.status !== 'converted')}>
                    <td className="font-mono text-xs">{r.request_number}</td>
                    <td>{r.supplier?.name || '—'}</td>
                    <td>{r.total}</td>
                    <td>{r.status}</td>
                    <td className="space-x-2 space-x-reverse">
                      {r.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={(e) => { e.stopPropagation(); convertReq.mutate(r.id) }}>{t('purchases.convertToOrder')}</button>}
                      {canDeletePurchase('requests', r.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('requests', r.id, r.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={t('common.cannotDeleteConverted')}>{t('common.delete')}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
        </Panel>
      )}

      {tab === 'orders' && (
        <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(orders.data || []).map((o: { id: number; order_number: string; total: number; status: string; supplier?: { name: string } }) => (
                  <tr key={o.id} className="cursor-pointer" onClick={() => openRow(o)}>
                    <td className="font-mono text-xs">{o.order_number}</td>
                    <td>{o.supplier?.name}</td>
                    <td>{o.total}</td>
                    <td>{o.status}</td>
                    <td className="space-x-2 space-x-reverse">
                      {o.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={(e) => { e.stopPropagation(); convertPo.mutate(o.id) }}>{t('purchases.convertToInvoice')}</button>}
                      {canDeletePurchase('orders', o.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('orders', o.id, o.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={t('common.cannotDeleteConverted')}>{t('common.delete')}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
        </Panel>
      )}

      {tab === 'invoices' && (
        <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(invoices.data || []).map((i: { id: number; invoice_number: string; total: number; status: string; supplier?: { name: string } }) => (
                  <tr key={i.id} className="cursor-pointer" onClick={() => openRow(i)}>
                    <td className="font-mono text-xs">{i.invoice_number}</td>
                    <td>{i.supplier?.name}</td>
                    <td>{i.total}</td>
                    <td>{i.status}</td>
                    <td>
                      {canDeletePurchase('invoices', i.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('invoices', i.id, i.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={t('common.cannotDeletePosted')}>{t('common.delete')}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
        </Panel>
      )}

      {tab === 'returns' && (
        <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(returns.data || []).map((r: { id: number; return_number: string; total: number; status: string; supplier?: { name: string } }) => (
                  <tr key={r.id} className="cursor-pointer" onClick={() => openRow(r)}>
                    <td className="font-mono text-xs">{r.return_number}</td>
                    <td>{r.supplier?.name}</td>
                    <td>{r.total}</td>
                    <td>{r.status}</td>
                    <td>
                      {canDeletePurchase('returns', r.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('returns', r.id, r.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={t('common.cannotDeletePosted')}>{t('common.delete')}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
        </Panel>
      )}

      {tab === 'payments' && (
        <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.supplier')}</th><th>مبلغ</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(payments.data || []).map((p: { id: number; payment_number: string; amount: number; status: string; supplier?: { name: string } }) => (
                  <tr key={p.id} className="cursor-pointer" onClick={() => openRow(p)}>
                    <td className="font-mono text-xs">{p.payment_number}</td>
                    <td>{p.supplier?.name}</td>
                    <td>{p.amount}</td>
                    <td>{p.status}</td>
                    <td>
                      {canDeletePurchase('payments', p.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('payments', p.id, p.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={t('common.cannotDeletePosted')}>{t('common.delete')}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
        </Panel>
      )}
      <Modal open={modal !== null} onClose={closeModal} title={modal === 'create' ? t('common.add') : modal === 'edit' ? t('common.edit') : t('common.view')} footer={modal !== 'view' ? <><Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button><Button variant="primary" type="submit" form="purchase-form">{t('common.save')}</Button></> : <>
          {selectedId && selectedRow && canDeletePurchase(tab, String(selectedRow.status || '')) && (
            <Button variant="danger" disabled={deleteDoc.isPending} onClick={() => askDelete(tab, selectedId, String(selectedRow.status || ''))}>{t('common.delete')}</Button>
          )}
          <Button variant="secondary" onClick={closeModal}>{t('common.close')}</Button>
        </>}>
        {modal === 'view' ? (detail.isLoading ? <p>جاري التحميل...</p> : summary(detail.data || selectedRow || {})) : <form id="purchase-form" className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (tab === 'requests') modal === 'edit' && selectedId ? updateReq.mutate(selectedId) : saveReq.mutate(); else if (tab === 'orders') savePo.mutate(); else if (tab === 'invoices') saveInv.mutate(); else if (tab === 'returns') saveRet.mutate(); else savePay.mutate() }}>
          {tab === 'requests' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={req.request_date} onChange={(e) => setReq({ ...req, request_date: e.target.value })} /></Field>{supplierFields(req, setReq)}{productFields(req, setReq)}</>}
          {tab === 'orders' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={po.order_date} onChange={(e) => setPo({ ...po, order_date: e.target.value })} /></Field>{supplierFields(po, setPo)}{productFields(po, setPo)}</>}
          {tab === 'invoices' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={inv.invoice_date} onChange={(e) => setInv({ ...inv, invoice_date: e.target.value })} /></Field>{supplierFields(inv, setInv)}{productFields(inv, setInv)}</>}
          {tab === 'returns' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={ret.return_date} onChange={(e) => setRet({ ...ret, return_date: e.target.value })} /></Field>{supplierFields(ret, setRet)}<Field label="فاتورة"><select className={inputClass} value={ret.purchase_invoice_id} onChange={(e) => setRet({ ...ret, purchase_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field>{productFields(ret, setRet)}</>}
          {tab === 'payments' && <>{supplierFields(pay, setPay, false)}<Field label="فاتورة"><select className={inputClass} value={pay.purchase_invoice_id} onChange={(e) => setPay({ ...pay, purchase_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field><Field label="صندوق"><select className={inputClass} value={pay.cash_box_id} onChange={(e) => setPay({ ...pay, cash_box_id: e.target.value })}><option value="">—</option>{(cashBoxes.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field><Field label="المبلغ"><NumericInput value={pay.amount} onChange={(v) => setPay((prev) => ({ ...prev, amount: v }))} required /></Field></>}
        </form>}
      </Modal>
    </div>
  )
}
