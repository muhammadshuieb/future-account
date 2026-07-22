import { useCallback, useEffect, useRef, useState, type Dispatch, type SetStateAction } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import QRCode from 'qrcode'
import { Printer } from 'lucide-react'
import api from '@/lib/api'
import BarcodeScanInput from '@/components/BarcodeScanInput'
import { Button, Field, Modal, Msg, NumericInput, PageHeader, Panel, Tabs, formatQuantity, inputClass, useFormMessage } from '@/components/ui'

type ProductRow = { id: number; name: string; sale_price: number; track_batch?: boolean; track_serial?: boolean }

function linePayload(productId: string, qty: string, price: string, batch: string, serial: string) {
  return {
    product_id: Number(productId),
    quantity: Number(qty),
    unit_price: price ? Number(price) : undefined,
    tax_rate: 15,
    batch_no: batch || undefined,
    serial_no: serial || undefined,
  }
}

async function fetchAvailableStock(productId: string, warehouseId: string): Promise<number | null> {
  if (!productId || !warehouseId) return null
  try {
    const res = await api.get(`/products/${productId}/stock`, { params: { warehouse_id: warehouseId } })
    return Number(res.data.data.available_qty ?? 0)
  } catch {
    return null
  }
}

export default function SalesPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState('invoices')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const [modal, setModal] = useState<'create' | 'view' | 'edit' | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [selectedRow, setSelectedRow] = useState<Record<string, unknown> | null>(null)

  const quotes = useQuery({ queryKey: ['sales-quotes'], queryFn: async () => (await api.get('/sales-quotes')).data.data, enabled: tab === 'quotes' })
  const orders = useQuery({ queryKey: ['sales-orders'], queryFn: async () => (await api.get('/sales-orders')).data.data, enabled: tab === 'orders' })
  const invoices = useQuery({ queryKey: ['sales-invoices'], queryFn: async () => (await api.get('/sales-invoices')).data.data })
  const returns = useQuery({ queryKey: ['sales-returns'], queryFn: async () => (await api.get('/sales-returns')).data.data, enabled: tab === 'returns' })
  const receipts = useQuery({ queryKey: ['receipts'], queryFn: async () => (await api.get('/receipts')).data.data, enabled: tab === 'receipts' })
  const customers = useQuery({ queryKey: ['customers'], queryFn: async () => (await api.get('/customers')).data.data })
  const products = useQuery({ queryKey: ['products'], queryFn: async () => (await api.get('/products')).data.data as ProductRow[] })
  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data.data })
  const settings = useQuery({ queryKey: ['settings'], queryFn: async () => (await api.get('/settings')).data.data as { key: string; value: string }[] })
  const defaultWarehouseId = settings.data?.find((s) => s.key === 'default_warehouse_id')?.value || ''
  const cashBoxes = useQuery({ queryKey: ['cash-boxes'], queryFn: async () => (await api.get('/cash-boxes')).data.data, enabled: tab === 'receipts' })

  const emptyLine = { customer_id: '', warehouse_id: '', product_id: '', quantity: '1', unit_price: '', batch_no: '', serial_no: '', currency: 'SYP', exchange_rate: '' }

  const [quote, setQuote] = useState({ quote_date: new Date().toISOString().slice(0, 10), valid_until: '', ...emptyLine })
  const [order, setOrder] = useState({ order_date: new Date().toISOString().slice(0, 10), ...emptyLine })
  const [inv, setInv] = useState({ invoice_date: new Date().toISOString().slice(0, 10), status: 'posted', ...emptyLine })
  const [ret, setRet] = useState({
    return_date: new Date().toISOString().slice(0, 10),
    customer_id: '',
    warehouse_id: '',
    sales_invoice_id: '',
    product_id: '',
    quantity: '1',
    unit_price: '',
    batch_no: '',
    serial_no: '',
    status: 'posted',
  })
  const [rc, setRc] = useState({
    receipt_date: new Date().toISOString().slice(0, 10),
    customer_id: '',
    sales_invoice_id: '',
    cash_box_id: '',
    amount: '',
    method: 'cash',
    status: 'posted',
  })
  const [printId, setPrintId] = useState<number | null>(null)
  const [availableStock, setAvailableStock] = useState<number | null>(null)
  const skipStockAutofill = useRef(false)

  const selectedProduct = (products.data || []).find((p) => String(p.id) === inv.product_id)

  const applyStockToForm = useCallback(async <T extends { product_id: string; warehouse_id?: string; quantity: string }>(
    setState: Dispatch<SetStateAction<T>>,
    productId: string,
    warehouseId: string,
  ) => {
    const qty = await fetchAvailableStock(productId, warehouseId)
    if (qty === null) {
      setAvailableStock(null)
      return
    }
    setAvailableStock(qty)
    setState((prev) => ({ ...prev, quantity: String(qty) }))
  }, [])

  async function handleBarcodeScan(code: string, target: 'inv' | 'order' | 'quote' = 'inv') {
    try {
      const res = await api.get(`/products?barcode=${encodeURIComponent(code)}`)
      const found = (res.data.data as ProductRow[])[0]
      if (!found) {
        msg.setError('لم يُعثر على صنف بهذا الباركود')
        return
      }
      const patch = { product_id: String(found.id), unit_price: String(found.sale_price) }
      if (target === 'inv') {
        setInv((prev) => ({ ...prev, ...patch }))
        if (inv.warehouse_id) void applyStockToForm(setInv, String(found.id), inv.warehouse_id)
      } else if (target === 'order') {
        setOrder((prev) => ({ ...prev, ...patch }))
        if (order.warehouse_id) void applyStockToForm(setOrder, String(found.id), order.warehouse_id)
      } else {
        setQuote((prev) => ({ ...prev, ...patch }))
        if (quote.warehouse_id) void applyStockToForm(setQuote, String(found.id), quote.warehouse_id)
      }
      msg.setMessage(`تم العثور على: ${found.name}`)
    } catch {
      msg.setError('تعذر البحث بالباركود')
    }
  }

  const invalidateSales = () => void qc.invalidateQueries({ queryKey: ['sales-quotes', 'sales-orders', 'sales-invoices', 'sales-returns', 'stock-levels'] })
  const closeModal = () => { setModal(null); setSelectedId(null); setSelectedRow(null); setAvailableStock(null) }
  const openCreate = () => {
    setPrintId(null)
    setSelectedId(null)
    setSelectedRow(null)
    setAvailableStock(null)
    skipStockAutofill.current = false
    if (defaultWarehouseId) {
      setQuote((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
      setOrder((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
      setInv((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
    }
    setModal('create')
  }
  const openRow = (row: Record<string, unknown> & { id: number }, editable = false) => { setPrintId(null); setSelectedId(row.id); setSelectedRow(row); setModal(editable ? 'edit' : 'view') }

  const saveQuote = useMutation({
    mutationFn: () => api.post('/sales-quotes', {
      quote_date: quote.quote_date,
      valid_until: quote.valid_until || undefined,
      customer_id: Number(quote.customer_id),
      warehouse_id: Number(quote.warehouse_id) || undefined,
      currency: quote.currency,
      lines: [linePayload(quote.product_id, quote.quantity, quote.unit_price, quote.batch_no, quote.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم حفظ عرض السعر'); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  const saveOrder = useMutation({
    mutationFn: () => api.post('/sales-orders', {
      order_date: order.order_date,
      customer_id: Number(order.customer_id),
      warehouse_id: Number(order.warehouse_id) || undefined,
      currency: order.currency,
      lines: [linePayload(order.product_id, order.quantity, order.unit_price, order.batch_no, order.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم حفظ أمر البيع'); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  const saveInv = useMutation({
    mutationFn: () => api.post('/sales-invoices', {
      invoice_date: inv.invoice_date,
      customer_id: Number(inv.customer_id),
      warehouse_id: Number(inv.warehouse_id),
      currency: inv.currency,
      exchange_rate: inv.exchange_rate ? Number(inv.exchange_rate) : undefined,
      status: inv.status,
      lines: [linePayload(inv.product_id, inv.quantity, inv.unit_price, inv.batch_no, inv.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم إنشاء/ترحيل فاتورة المبيعات'); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  const saveRet = useMutation({
    mutationFn: () => api.post('/sales-returns', {
      return_date: ret.return_date,
      customer_id: Number(ret.customer_id),
      warehouse_id: Number(ret.warehouse_id) || undefined,
      sales_invoice_id: ret.sales_invoice_id ? Number(ret.sales_invoice_id) : null,
      status: ret.status,
      lines: [{ product_id: Number(ret.product_id), quantity: Number(ret.quantity), unit_price: Number(ret.unit_price), batch_no: ret.batch_no || undefined, serial_no: ret.serial_no || undefined }],
    }),
    onSuccess: () => { msg.setMessage('تم ترحيل مرتجع المبيعات'); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  const convertQuote = useMutation({
    mutationFn: (id: number) => api.post(`/sales-quotes/${id}/convert-to-order`),
    onSuccess: () => { msg.setMessage('تم التحويل لأمر بيع'); invalidateSales() },
    onError: msg.fromErr,
  })

  const updateQuote = useMutation({
    mutationFn: (id: number) => api.put(`/sales-quotes/${id}`, {
      quote_date: quote.quote_date,
      valid_until: quote.valid_until || undefined,
      customer_id: Number(quote.customer_id),
      warehouse_id: Number(quote.warehouse_id) || undefined,
      currency: quote.currency,
      lines: [linePayload(quote.product_id, quote.quantity, quote.unit_price, quote.batch_no, quote.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم تحديث عرض السعر'); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  const convertOrder = useMutation({
    mutationFn: (id: number) => api.post(`/sales-orders/${id}/convert-to-invoice`, { status: 'posted' }),
    onSuccess: () => { msg.setMessage('تم التحويل لفاتورة'); invalidateSales() },
    onError: msg.fromErr,
  })

  const saveRc = useMutation({
    mutationFn: () => api.post('/receipts', {
      ...rc,
      customer_id: Number(rc.customer_id),
      sales_invoice_id: rc.sales_invoice_id ? Number(rc.sales_invoice_id) : null,
      cash_box_id: rc.cash_box_id ? Number(rc.cash_box_id) : null,
      amount: Number(rc.amount),
    }),
    onSuccess: () => { msg.setMessage('تم تسجيل سند القبض'); void qc.invalidateQueries({ queryKey: ['receipts', 'sales-invoices'] }); closeModal() },
    onError: msg.fromErr,
  })

  const invoiceDetail = useQuery({
    queryKey: ['sales-invoice', printId],
    enabled: !!printId,
    queryFn: async () => (await api.get(`/sales-invoices/${printId}`)).data.data,
  })

  const invoiceQr = useQuery({
    queryKey: ['sales-invoice-qr', printId],
    enabled: !!printId,
    queryFn: async () => (await api.get(`/sales-invoices/${printId}/qr`)).data.data as {
      qr_payload: string
      e_invoice?: Record<string, unknown>
      e_invoice_uuid?: string
    },
  })

  const detailPath = tab === 'quotes' ? 'sales-quotes' : tab === 'orders' ? 'sales-orders' : tab === 'invoices' ? 'sales-invoices' : tab === 'returns' ? 'sales-returns' : 'receipts'
  const hasDetailEndpoint = tab !== 'returns' && tab !== 'receipts'
  const detail = useQuery({
    queryKey: ['sales-detail', tab, selectedId],
    enabled: !!selectedId && modal !== null && hasDetailEndpoint,
    queryFn: async () => (await api.get(`/${detailPath}/${selectedId}`)).data.data,
  })

  useEffect(() => {
    if (modal !== 'edit' || !detail.data) return
    skipStockAutofill.current = true
    const d = detail.data
    const line = d.items?.[0] || d.lines?.[0] || {}
    setQuote({
      quote_date: String(d.quote_date || '').slice(0, 10),
      valid_until: String(d.valid_until || '').slice(0, 10),
      customer_id: String(d.customer_id || d.customer?.id || ''),
      warehouse_id: String(d.warehouse_id || d.warehouse?.id || ''),
      product_id: String(line.product_id || line.product?.id || ''),
      quantity: String(line.quantity || 1),
      unit_price: String(line.unit_price || ''),
      batch_no: line.batch_no || '',
      serial_no: line.serial_no || '',
      currency: d.currency || 'SYP',
      exchange_rate: String(d.exchange_rate || ''),
    })
    setAvailableStock(null)
  }, [detail.data, modal])

  const tabs = [
    { id: 'quotes', label: t('sales.quotes') },
    { id: 'orders', label: t('sales.orders') },
    { id: 'invoices', label: t('sales.invoices') },
    { id: 'returns', label: t('sales.returns') },
    { id: 'receipts', label: t('sales.receipts') },
  ]

  const productFields = <T extends { product_id: string; warehouse_id?: string; quantity: string; unit_price: string; batch_no: string; serial_no: string }>(
    state: T,
    setState: Dispatch<SetStateAction<T>>,
    onScan?: (code: string) => void,
    autoFillStock = false,
  ) => (
    <>
      {onScan && (
        <BarcodeScanInput
          label="مسح باركود الصنف"
          hint="وصّل قارئ USB وامسح — يُضاف الصنف تلقائياً"
          onScan={onScan}
        />
      )}
      <Field label={t('common.product')}>
        <select
          className={inputClass}
          value={state.product_id}
          onChange={(e) => {
            const productId = e.target.value
            const product = (products.data || []).find((p) => String(p.id) === productId)
            setState((prev) => ({
              ...prev,
              product_id: productId,
              unit_price: product ? String(product.sale_price) : prev.unit_price,
            }))
            if (autoFillStock && productId && state.warehouse_id && !skipStockAutofill.current) {
              void applyStockToForm(setState, productId, state.warehouse_id)
            } else if (!productId) {
              setAvailableStock(null)
            }
          }}
          required
        >
          <option value="">—</option>
          {(products.data || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      </Field>
      <div className="form-grid-2">
        <Field label={t('common.quantity')} hint={t('common.quantityUnit')}>
          <NumericInput value={state.quantity} onChange={(v) => setState((prev) => ({ ...prev, quantity: v }))} />
          {autoFillStock && availableStock !== null && state.product_id && state.warehouse_id && (
            <p className="mt-1 text-xs text-black/55">{t('sales.stockRemaining', { qty: formatQuantity(availableStock) })}</p>
          )}
        </Field>
        <Field label={t('common.price')}><NumericInput value={state.unit_price} onChange={(v) => setState((prev) => ({ ...prev, unit_price: v }))} /></Field>
      </div>
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_batch && (
        <Field label={t('common.batch')}><input className={inputClass} value={state.batch_no} onChange={(e) => setState({ ...state, batch_no: e.target.value })} required /></Field>
      )}
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_serial && (
        <Field label={t('common.serial')}><input className={inputClass} value={state.serial_no} onChange={(e) => setState({ ...state, serial_no: e.target.value })} required /></Field>
      )}
    </>
  )

  const customerField = <T extends { customer_id: string; warehouse_id?: string; product_id?: string }>(
    state: T,
    setState: Dispatch<SetStateAction<T>>,
    warehouse = true,
    onWarehouseChange?: (warehouseId: string, productId?: string) => void,
  ) => (
    <>
      <Field label={t('common.customer')}><select className={inputClass} value={state.customer_id} onChange={(e) => setState({ ...state, customer_id: e.target.value })} required><option value="">—</option>{(customers.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
      {warehouse && (
        <Field label={t('common.warehouse')}>
          <select
            className={inputClass}
            value={state.warehouse_id}
            onChange={(e) => {
              const warehouseId = e.target.value
              setState({ ...state, warehouse_id: warehouseId })
              onWarehouseChange?.(warehouseId, state.product_id)
            }}
          >
            <option value="">—</option>
            {(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}
          </select>
        </Field>
      )}
    </>
  )

  const onSalesWarehouseChange = <T extends { product_id: string; warehouse_id?: string; quantity: string }>(
    setState: Dispatch<SetStateAction<T>>,
  ) => (warehouseId: string, productId?: string) => {
    if (productId && warehouseId && !skipStockAutofill.current) {
      void applyStockToForm(setState, productId, warehouseId)
    } else if (!warehouseId) {
      setAvailableStock(null)
    }
  }

  const summary = (data: Record<string, unknown>) => (
    <div className="space-y-3 text-sm">
      <div className="grid gap-3 sm:grid-cols-2">
        <p><b>رقم:</b> {String(data.quote_number || data.order_number || data.invoice_number || data.return_number || data.receipt_number || '—')}</p>
        <p><b>{t('common.status')}:</b> {String(data.status || '—')}</p>
        <p><b>{t('common.customer')}:</b> {(data.customer as { name?: string } | undefined)?.name || '—'}</p>
        <p><b>{t('common.total')}:</b> {String(data.total || data.amount || '—')}</p>
      </div>
      {!!((data.items || data.lines) as unknown[] | undefined)?.length && <div className="table-wrap"><table className="data-table"><thead><tr><th>{t('common.product')}</th><th title={t('common.quantityUnit')}>{t('common.quantity')}</th><th>{t('common.total')}</th></tr></thead><tbody>{(((data.items || data.lines) as { product?: { name?: string }; quantity?: number; line_total?: number }[]) || []).map((line, index) => <tr key={index}><td>{line.product?.name}</td><td className="tabular-nums">{formatQuantity(line.quantity)}</td><td>{line.line_total}</td></tr>)}</tbody></table></div>}
    </div>
  )

  return (
    <div className="space-y-6">
      <PageHeader title={t('sales.title')} subtitle={t('sales.subtitle')} actions={<Button variant="primary" onClick={openCreate}>{t('common.add')}</Button>} />
      <Tabs tabs={tabs} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'quotes' && (
        <Panel>
            <div className="table-wrap">
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(quotes.data || []).map((q: { id: number; quote_number: string; total: number; status: string; customer?: { name: string } }) => (
                  <tr key={q.id} className="cursor-pointer" onClick={() => openRow(q, q.status !== 'converted')}>
                    <td className="font-mono text-xs">{q.quote_number}</td>
                    <td>{q.customer?.name}</td>
                    <td>{q.total}</td>
                    <td>{q.status}</td>
                    <td>{q.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={(e) => { e.stopPropagation(); convertQuote.mutate(q.id) }}>{t('sales.convertToOrder')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
        </Panel>
      )}

      {tab === 'orders' && (
        <Panel>
            <div className="table-wrap">
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(orders.data || []).map((o: { id: number; order_number: string; total: number; status: string; customer?: { name: string } }) => (
                  <tr key={o.id} className="cursor-pointer" onClick={() => openRow(o)}>
                    <td className="font-mono text-xs">{o.order_number}</td>
                    <td>{o.customer?.name}</td>
                    <td>{o.total}</td>
                    <td>{o.status}</td>
                    <td>{o.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={(e) => { e.stopPropagation(); convertOrder.mutate(o.id) }}>{t('sales.convertToInvoice')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
        </Panel>
      )}

      {tab === 'invoices' && (
        <Panel>
            <div className="table-wrap">
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(invoices.data || []).map((i: { id: number; invoice_number: string; total: number; status: string; currency?: string; customer?: { name: string } }) => (
                  <tr key={i.id} className="cursor-pointer" onClick={() => openRow(i)}>
                    <td className="font-mono text-xs">{i.invoice_number}</td>
                    <td>{i.customer?.name}</td>
                    <td>{i.currency || 'SYP'}</td>
                    <td className="tabular-nums">{i.total}</td>
                    <td>{i.status}</td>
                    <td><button type="button" className="text-xs text-teal print-hide" onClick={(e) => { e.stopPropagation(); setPrintId(i.id) }}>{t('common.print')}</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
        </Panel>
      )}

      {printId && invoiceDetail.data && (
        <Panel className="print-area mt-4 p-6">
          <div className="print-hide mb-3 flex gap-2">
            <Button variant="secondary" onClick={() => window.print()}><Printer size={16} /> {t('common.print')}</Button>
            <Button variant="ghost" onClick={() => setPrintId(null)}>{t('common.close')}</Button>
          </div>
          <InvoicePrintView invoice={invoiceDetail.data} eInvoice={invoiceQr.data} />
        </Panel>
      )}

      {tab === 'returns' && (
        <Panel>
            <div className="table-wrap">
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th></tr></thead>
              <tbody>
                {(returns.data || []).map((r: { id: number; return_number: string; total: number; status: string; customer?: { name: string } }) => (
                  <tr key={r.id} className="cursor-pointer" onClick={() => openRow(r)}><td className="font-mono text-xs">{r.return_number}</td><td>{r.customer?.name}</td><td>{r.total}</td><td>{r.status}</td></tr>
                ))}
              </tbody>
            </table>
            </div>
        </Panel>
      )}

      {tab === 'receipts' && (
        <Panel>
            <div className="table-wrap">
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>مبلغ</th><th>{t('common.status')}</th></tr></thead>
              <tbody>
                {(receipts.data || []).map((r: { id: number; receipt_number: string; amount: number; status: string; customer?: { name: string } }) => (
                  <tr key={r.id} className="cursor-pointer" onClick={() => openRow(r)}><td className="font-mono text-xs">{r.receipt_number}</td><td>{r.customer?.name}</td><td>{r.amount}</td><td>{r.status}</td></tr>
                ))}
              </tbody>
            </table>
            </div>
        </Panel>
      )}
      <Modal open={modal !== null} onClose={closeModal} title={modal === 'create' ? t('common.add') : modal === 'edit' ? t('common.edit') : t('common.view')} size={tab === 'invoices' && modal === 'view' ? 'xl' : 'md'} footer={modal !== 'view' ? <><Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button><Button variant="primary" type="submit" form="sales-form">{t('common.save')}</Button></> : <Button variant="secondary" onClick={closeModal}>{t('common.close')}</Button>}>
        {modal === 'view' ? (detail.isLoading ? <p>جاري التحميل...</p> : summary(detail.data || selectedRow || {})) : (
          <form id="sales-form" className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (tab === 'quotes') modal === 'edit' && selectedId ? updateQuote.mutate(selectedId) : saveQuote.mutate(); else if (tab === 'orders') saveOrder.mutate(); else if (tab === 'invoices') saveInv.mutate(); else if (tab === 'returns') saveRet.mutate(); else saveRc.mutate() }}>
            {tab === 'quotes' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={quote.quote_date} onChange={(e) => setQuote({ ...quote, quote_date: e.target.value })} /></Field><Field label="صالح حتى"><input type="date" className={inputClass} value={quote.valid_until} onChange={(e) => setQuote({ ...quote, valid_until: e.target.value })} /></Field>{customerField(quote, setQuote, true, onSalesWarehouseChange(setQuote))}{productFields(quote, setQuote, (code) => void handleBarcodeScan(code, 'quote'), true)}</>}
            {tab === 'orders' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={order.order_date} onChange={(e) => setOrder({ ...order, order_date: e.target.value })} /></Field>{customerField(order, setOrder, true, onSalesWarehouseChange(setOrder))}{productFields(order, setOrder, (code) => void handleBarcodeScan(code, 'order'), true)}</>}
            {tab === 'invoices' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={inv.invoice_date} onChange={(e) => setInv({ ...inv, invoice_date: e.target.value })} /></Field>{customerField(inv, setInv, true, onSalesWarehouseChange(setInv))}{productFields(inv, setInv, (code) => void handleBarcodeScan(code, 'inv'), true)}{selectedProduct?.track_batch && <p className="text-xs text-amber">* {t('warehouse.trackBatch')}</p>}{selectedProduct?.track_serial && <p className="text-xs text-amber">* {t('warehouse.trackSerial')}</p>}</>}
            {tab === 'returns' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={ret.return_date} onChange={(e) => setRet({ ...ret, return_date: e.target.value })} /></Field>{customerField(ret, setRet)}<Field label="فاتورة"><select className={inputClass} value={ret.sales_invoice_id} onChange={(e) => setRet({ ...ret, sales_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field>{productFields(ret, setRet)}</>}
            {tab === 'receipts' && <>{customerField(rc, setRc, false)}<Field label="فاتورة"><select className={inputClass} value={rc.sales_invoice_id} onChange={(e) => setRc({ ...rc, sales_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field><Field label="صندوق"><select className={inputClass} value={rc.cash_box_id} onChange={(e) => setRc({ ...rc, cash_box_id: e.target.value })}><option value="">—</option>{(cashBoxes.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field><Field label="المبلغ"><NumericInput value={rc.amount} onChange={(v) => setRc((prev) => ({ ...prev, amount: v }))} required /></Field></>}
          </form>
        )}
      </Modal>
    </div>
  )
}

function InvoicePrintView({
  invoice,
  eInvoice,
}: {
  invoice: {
    invoice_number: string
    invoice_date: string
    e_invoice_uuid?: string
    total: number
    tax_amount: number
    subtotal: number
    currency?: string
    customer?: { name: string; tax_number?: string }
    lines?: { product?: { name: string; sku?: string }; quantity: number; unit_price: number; line_total: number; batch_no?: string; serial_no?: string }[]
  }
  eInvoice?: { qr_payload?: string; e_invoice?: Record<string, unknown>; e_invoice_uuid?: string }
}) {
  const { t } = useTranslation()
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const payload = eInvoice?.qr_payload
  const structured = eInvoice?.e_invoice as {
    uuid?: string
    seller?: { name?: string; tax_number?: string }
    tax_breakdown?: { rate: number; taxable: number; tax: number }[]
  } | undefined

  useEffect(() => {
    if (canvasRef.current && payload) {
      void QRCode.toCanvas(canvasRef.current, payload, { width: 140, margin: 1 })
    }
  }, [payload])

  return (
    <div className="space-y-4 text-sm">
      <div className="rounded-lg border-2 border-teal/30 bg-teal/5 p-4">
        <p className="text-xs font-semibold uppercase tracking-wide text-teal">{t('sales.eInvoice')} — future-account-einvoice/1.0</p>
        <p className="mt-1 font-mono text-xs text-black/60">UUID: {structured?.uuid || eInvoice?.e_invoice_uuid || invoice.e_invoice_uuid || '—'}</p>
      </div>
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-lg font-bold">{structured?.seller?.name || 'Syna Co'}</p>
          {structured?.seller?.tax_number && <p className="text-xs text-black/55">Tax: {structured.seller.tax_number}</p>}
          <p className="mt-2 font-mono">{invoice.invoice_number}</p>
          <p>{String(invoice.invoice_date).slice(0, 10)}</p>
          <p>{t('common.customer')}: {invoice.customer?.name}</p>
        </div>
        {payload && <canvas ref={canvasRef} className="rounded border border-black/10" />}
      </div>
      <table className="data-table">
        <thead><tr><th>{t('common.product')}</th><th title={t('common.quantityUnit')}>{t('common.quantity')}</th><th>{t('common.price')}</th><th>{t('common.batch')}</th><th>{t('common.total')}</th></tr></thead>
        <tbody>
          {(invoice.lines || []).map((l, i) => (
            <tr key={i}>
              <td>{l.product?.name}</td>
              <td className="tabular-nums">{formatQuantity(l.quantity)}</td>
              <td>{l.unit_price}</td>
              <td className="font-mono text-xs">{l.batch_no || l.serial_no || '—'}</td>
              <td>{l.line_total}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {(structured?.tax_breakdown || []).length > 0 && (
        <div className="rounded border border-black/10 p-3">
          <p className="mb-2 text-xs font-semibold">Tax Breakdown</p>
          {(structured?.tax_breakdown || []).map((tb, i) => (
            <p key={i} className="text-xs">{tb.rate}% — taxable {tb.taxable}, tax {tb.tax}</p>
          ))}
        </div>
      )}
      <div className="space-y-1 text-left" dir="ltr">
        <p>Subtotal: {invoice.subtotal}</p>
        <p>Tax: {invoice.tax_amount}</p>
        <p className="font-bold">Total ({invoice.currency || 'SYP'}): {invoice.total}</p>
      </div>
      <p className="text-[10px] text-black/45">Phase 2: government API submission requires country-specific credentials (ZATCA/GIB).</p>
    </div>
  )
}
