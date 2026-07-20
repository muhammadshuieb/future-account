import { useEffect, useRef, useState, type Dispatch, type SetStateAction } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import QRCode from 'qrcode'
import { Printer } from 'lucide-react'
import api from '@/lib/api'
import { Button, Field, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

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

export default function SalesPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState('invoices')
  const qc = useQueryClient()
  const msg = useFormMessage()

  const quotes = useQuery({ queryKey: ['sales-quotes'], queryFn: async () => (await api.get('/sales-quotes')).data.data, enabled: tab === 'quotes' })
  const orders = useQuery({ queryKey: ['sales-orders'], queryFn: async () => (await api.get('/sales-orders')).data.data, enabled: tab === 'orders' })
  const invoices = useQuery({ queryKey: ['sales-invoices'], queryFn: async () => (await api.get('/sales-invoices')).data.data })
  const returns = useQuery({ queryKey: ['sales-returns'], queryFn: async () => (await api.get('/sales-returns')).data.data, enabled: tab === 'returns' })
  const receipts = useQuery({ queryKey: ['receipts'], queryFn: async () => (await api.get('/receipts')).data.data, enabled: tab === 'receipts' })
  const customers = useQuery({ queryKey: ['customers'], queryFn: async () => (await api.get('/customers')).data.data })
  const products = useQuery({ queryKey: ['products'], queryFn: async () => (await api.get('/products')).data.data as ProductRow[] })
  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data.data })
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

  const selectedProduct = (products.data || []).find((p) => String(p.id) === inv.product_id)

  const invalidateSales = () => void qc.invalidateQueries({ queryKey: ['sales-quotes', 'sales-orders', 'sales-invoices', 'sales-returns', 'stock-levels'] })

  const saveQuote = useMutation({
    mutationFn: () => api.post('/sales-quotes', {
      quote_date: quote.quote_date,
      valid_until: quote.valid_until || undefined,
      customer_id: Number(quote.customer_id),
      warehouse_id: Number(quote.warehouse_id) || undefined,
      currency: quote.currency,
      lines: [linePayload(quote.product_id, quote.quantity, quote.unit_price, quote.batch_no, quote.serial_no)],
    }),
    onSuccess: () => { msg.setMessage('تم حفظ عرض السعر'); invalidateSales() },
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
    onSuccess: () => { msg.setMessage('تم حفظ أمر البيع'); invalidateSales() },
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
    onSuccess: () => { msg.setMessage('تم إنشاء/ترحيل فاتورة المبيعات'); invalidateSales() },
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
    onSuccess: () => { msg.setMessage('تم ترحيل مرتجع المبيعات'); invalidateSales() },
    onError: msg.fromErr,
  })

  const convertQuote = useMutation({
    mutationFn: (id: number) => api.post(`/sales-quotes/${id}/convert-to-order`),
    onSuccess: () => { msg.setMessage('تم التحويل لأمر بيع'); invalidateSales() },
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
    onSuccess: () => { msg.setMessage('تم تسجيل سند القبض'); void qc.invalidateQueries({ queryKey: ['receipts', 'sales-invoices'] }) },
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

  const tabs = [
    { id: 'quotes', label: t('sales.quotes') },
    { id: 'orders', label: t('sales.orders') },
    { id: 'invoices', label: t('sales.invoices') },
    { id: 'returns', label: t('sales.returns') },
    { id: 'receipts', label: t('sales.receipts') },
  ]

  const productFields = <T extends { product_id: string; quantity: string; unit_price: string; batch_no: string; serial_no: string }>(
    state: T,
    setState: Dispatch<SetStateAction<T>>,
  ) => (
    <>
      <Field label={t('common.product')}>
        <select className={inputClass} value={state.product_id} onChange={(e) => setState({ ...state, product_id: e.target.value })} required>
          <option value="">—</option>
          {(products.data || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      </Field>
      <div className="grid grid-cols-2 gap-2">
        <Field label={t('common.quantity')}><input className={inputClass} value={state.quantity} onChange={(e) => setState({ ...state, quantity: e.target.value })} /></Field>
        <Field label={t('common.price')}><input className={inputClass} value={state.unit_price} onChange={(e) => setState({ ...state, unit_price: e.target.value })} /></Field>
      </div>
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_batch && (
        <Field label={t('common.batch')}><input className={inputClass} value={state.batch_no} onChange={(e) => setState({ ...state, batch_no: e.target.value })} required /></Field>
      )}
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_serial && (
        <Field label={t('common.serial')}><input className={inputClass} value={state.serial_no} onChange={(e) => setState({ ...state, serial_no: e.target.value })} required /></Field>
      )}
    </>
  )

  return (
    <div className="space-y-6">
      <PageHeader title={t('sales.title')} subtitle={t('sales.subtitle')} />
      <Tabs tabs={tabs} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'quotes' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(quotes.data || []).map((q: { id: number; quote_number: string; total: number; status: string; customer?: { name: string } }) => (
                  <tr key={q.id}>
                    <td className="font-mono text-xs">{q.quote_number}</td>
                    <td>{q.customer?.name}</td>
                    <td>{q.total}</td>
                    <td>{q.status}</td>
                    <td>{q.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={() => convertQuote.mutate(q.id)}>{t('sales.convertToOrder')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveQuote.mutate() }}>
            <h2 className="font-semibold">{t('sales.newQuote')}</h2>
            <Field label={t('common.date')}><input type="date" className={inputClass} value={quote.quote_date} onChange={(e) => setQuote({ ...quote, quote_date: e.target.value })} /></Field>
            <Field label="صالح حتى"><input type="date" className={inputClass} value={quote.valid_until} onChange={(e) => setQuote({ ...quote, valid_until: e.target.value })} /></Field>
            <Field label={t('common.customer')}><select className={inputClass} value={quote.customer_id} onChange={(e) => setQuote({ ...quote, customer_id: e.target.value })} required><option value="">—</option>{(customers.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label={t('common.warehouse')}><select className={inputClass} value={quote.warehouse_id} onChange={(e) => setQuote({ ...quote, warehouse_id: e.target.value })}><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            {productFields(quote, setQuote)}
            <button type="submit" className="btn btn-primary w-full">{t('common.save')}</button>
          </form>
        </div>
      )}

      {tab === 'orders' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(orders.data || []).map((o: { id: number; order_number: string; total: number; status: string; customer?: { name: string } }) => (
                  <tr key={o.id}>
                    <td className="font-mono text-xs">{o.order_number}</td>
                    <td>{o.customer?.name}</td>
                    <td>{o.total}</td>
                    <td>{o.status}</td>
                    <td>{o.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={() => convertOrder.mutate(o.id)}>{t('sales.convertToInvoice')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveOrder.mutate() }}>
            <h2 className="font-semibold">{t('sales.newOrder')}</h2>
            <Field label={t('common.date')}><input type="date" className={inputClass} value={order.order_date} onChange={(e) => setOrder({ ...order, order_date: e.target.value })} /></Field>
            <Field label={t('common.customer')}><select className={inputClass} value={order.customer_id} onChange={(e) => setOrder({ ...order, customer_id: e.target.value })} required><option value="">—</option>{(customers.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label={t('common.warehouse')}><select className={inputClass} value={order.warehouse_id} onChange={(e) => setOrder({ ...order, warehouse_id: e.target.value })}><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            {productFields(order, setOrder)}
            <button type="submit" className="btn btn-primary w-full">{t('common.save')}</button>
          </form>
        </div>
      )}

      {tab === 'invoices' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(invoices.data || []).map((i: { id: number; invoice_number: string; total: number; status: string; currency?: string; customer?: { name: string } }) => (
                  <tr key={i.id}>
                    <td className="font-mono text-xs">{i.invoice_number}</td>
                    <td>{i.customer?.name}</td>
                    <td>{i.currency || 'SYP'}</td>
                    <td className="tabular-nums">{i.total}</td>
                    <td>{i.status}</td>
                    <td><button type="button" className="text-xs text-teal print-hide" onClick={() => setPrintId(i.id)}>{t('common.print')}</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm print-hide" onSubmit={(e) => { e.preventDefault(); saveInv.mutate() }}>
            <h2 className="font-semibold">{t('sales.newInvoice')}</h2>
            <Field label={t('common.date')}><input type="date" className={inputClass} value={inv.invoice_date} onChange={(e) => setInv({ ...inv, invoice_date: e.target.value })} /></Field>
            <Field label={t('common.customer')}><select className={inputClass} value={inv.customer_id} onChange={(e) => setInv({ ...inv, customer_id: e.target.value })} required><option value="">—</option>{(customers.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label={t('common.warehouse')}><select className={inputClass} value={inv.warehouse_id} onChange={(e) => setInv({ ...inv, warehouse_id: e.target.value })} required><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            {productFields(inv, setInv)}
            {selectedProduct?.track_batch && <p className="text-xs text-amber">* {t('warehouse.trackBatch')}</p>}
            {selectedProduct?.track_serial && <p className="text-xs text-amber">* {t('warehouse.trackSerial')}</p>}
            <button type="submit" className="btn btn-primary w-full">{t('common.post')}</button>
          </form>
        </div>
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
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th></tr></thead>
              <tbody>
                {(returns.data || []).map((r: { id: number; return_number: string; total: number; status: string; customer?: { name: string } }) => (
                  <tr key={r.id}><td className="font-mono text-xs">{r.return_number}</td><td>{r.customer?.name}</td><td>{r.total}</td><td>{r.status}</td></tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveRet.mutate() }}>
            <h2 className="font-semibold">{t('sales.newReturn')}</h2>
            <Field label={t('common.date')}><input type="date" className={inputClass} value={ret.return_date} onChange={(e) => setRet({ ...ret, return_date: e.target.value })} /></Field>
            <Field label={t('common.customer')}><select className={inputClass} value={ret.customer_id} onChange={(e) => setRet({ ...ret, customer_id: e.target.value })} required><option value="">—</option>{(customers.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label={t('common.warehouse')}><select className={inputClass} value={ret.warehouse_id} onChange={(e) => setRet({ ...ret, warehouse_id: e.target.value })}><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            <Field label="فاتورة"><select className={inputClass} value={ret.sales_invoice_id} onChange={(e) => setRet({ ...ret, sales_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field>
            <Field label={t('common.product')}><select className={inputClass} value={ret.product_id} onChange={(e) => setRet({ ...ret, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label={t('common.quantity')}><input className={inputClass} value={ret.quantity} onChange={(e) => setRet({ ...ret, quantity: e.target.value })} /></Field>
              <Field label={t('common.price')}><input className={inputClass} value={ret.unit_price} onChange={(e) => setRet({ ...ret, unit_price: e.target.value })} required /></Field>
            </div>
            <button type="submit" className="btn btn-primary w-full">{t('common.post')}</button>
          </form>
        </div>
      )}

      {tab === 'receipts' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>{t('common.customer')}</th><th>مبلغ</th><th>{t('common.status')}</th></tr></thead>
              <tbody>
                {(receipts.data || []).map((r: { id: number; receipt_number: string; amount: number; status: string; customer?: { name: string } }) => (
                  <tr key={r.id}><td className="font-mono text-xs">{r.receipt_number}</td><td>{r.customer?.name}</td><td>{r.amount}</td><td>{r.status}</td></tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveRc.mutate() }}>
            <h2 className="font-semibold">سند قبض</h2>
            <Field label={t('common.customer')}><select className={inputClass} value={rc.customer_id} onChange={(e) => setRc({ ...rc, customer_id: e.target.value })} required><option value="">—</option>{(customers.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="فاتورة"><select className={inputClass} value={rc.sales_invoice_id} onChange={(e) => setRc({ ...rc, sales_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field>
            <Field label="صندوق"><select className={inputClass} value={rc.cash_box_id} onChange={(e) => setRc({ ...rc, cash_box_id: e.target.value })}><option value="">—</option>{(cashBoxes.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="المبلغ"><input className={inputClass} value={rc.amount} onChange={(e) => setRc({ ...rc, amount: e.target.value })} required /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">{t('common.post')}</button>
          </form>
        </div>
      )}
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
          <p className="text-lg font-bold">{structured?.seller?.name || 'Future Account'}</p>
          {structured?.seller?.tax_number && <p className="text-xs text-black/55">Tax: {structured.seller.tax_number}</p>}
          <p className="mt-2 font-mono">{invoice.invoice_number}</p>
          <p>{String(invoice.invoice_date).slice(0, 10)}</p>
          <p>{t('common.customer')}: {invoice.customer?.name}</p>
        </div>
        {payload && <canvas ref={canvasRef} className="rounded border border-black/10" />}
      </div>
      <table className="data-table">
        <thead><tr><th>{t('common.product')}</th><th>{t('common.quantity')}</th><th>{t('common.price')}</th><th>{t('common.batch')}</th><th>{t('common.total')}</th></tr></thead>
        <tbody>
          {(invoice.lines || []).map((l, i) => (
            <tr key={i}>
              <td>{l.product?.name}</td>
              <td>{l.quantity}</td>
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
