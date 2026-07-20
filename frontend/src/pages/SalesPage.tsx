import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import QRCode from 'qrcode'
import { Printer } from 'lucide-react'
import api from '@/lib/api'
import { Button, Field, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

export default function SalesPage() {
  const [tab, setTab] = useState('invoices')
  const qc = useQueryClient()
  const msg = useFormMessage()

  const invoices = useQuery({ queryKey: ['sales-invoices'], queryFn: async () => (await api.get('/sales-invoices')).data.data })
  const returns = useQuery({ queryKey: ['sales-returns'], queryFn: async () => (await api.get('/sales-returns')).data.data, enabled: tab === 'returns' })
  const receipts = useQuery({ queryKey: ['receipts'], queryFn: async () => (await api.get('/receipts')).data.data, enabled: tab === 'receipts' })
  const customers = useQuery({ queryKey: ['customers'], queryFn: async () => (await api.get('/customers')).data.data })
  const products = useQuery({ queryKey: ['products'], queryFn: async () => (await api.get('/products')).data.data })
  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data.data })
  const cashBoxes = useQuery({ queryKey: ['cash-boxes'], queryFn: async () => (await api.get('/cash-boxes')).data.data, enabled: tab === 'receipts' })

  const [inv, setInv] = useState({
    invoice_date: new Date().toISOString().slice(0, 10),
    customer_id: '',
    warehouse_id: '',
    product_id: '',
    quantity: '1',
    unit_price: '',
    currency: 'SYP',
    exchange_rate: '',
    status: 'posted',
  })
  const [printId, setPrintId] = useState<number | null>(null)
  const [rc, setRc] = useState({
    receipt_date: new Date().toISOString().slice(0, 10),
    customer_id: '',
    sales_invoice_id: '',
    cash_box_id: '',
    amount: '',
    method: 'cash',
    status: 'posted',
  })

  const saveInv = useMutation({
    mutationFn: () =>
      api.post('/sales-invoices', {
        invoice_date: inv.invoice_date,
        customer_id: Number(inv.customer_id),
        warehouse_id: Number(inv.warehouse_id),
        currency: inv.currency,
        exchange_rate: inv.exchange_rate ? Number(inv.exchange_rate) : undefined,
        status: inv.status,
        lines: [{ product_id: Number(inv.product_id), quantity: Number(inv.quantity), unit_price: inv.unit_price ? Number(inv.unit_price) : undefined, tax_rate: 15 }],
      }),
    onSuccess: () => { msg.setMessage('تم إنشاء/ترحيل فاتورة المبيعات'); void qc.invalidateQueries({ queryKey: ['sales-invoices', 'stock-levels'] }) },
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
    queryFn: async () => (await api.get(`/sales-invoices/${printId}/qr`)).data.data as { qr_payload: string },
  })

  const saveRc = useMutation({
    mutationFn: () =>
      api.post('/receipts', {
        ...rc,
        customer_id: Number(rc.customer_id),
        sales_invoice_id: rc.sales_invoice_id ? Number(rc.sales_invoice_id) : null,
        cash_box_id: rc.cash_box_id ? Number(rc.cash_box_id) : null,
        amount: Number(rc.amount),
      }),
    onSuccess: () => { msg.setMessage('تم تسجيل سند القبض'); void qc.invalidateQueries({ queryKey: ['receipts', 'sales-invoices'] }) },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-6">
      <PageHeader title="المبيعات" subtitle="فواتير، مرتجعات، وتحصيلات مرتبطة بالمخزون والقيود" />
      <Tabs tabs={[{ id: 'invoices', label: 'الفواتير' }, { id: 'returns', label: 'المرتجعات' }, { id: 'receipts', label: 'التحصيلات' }]} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'invoices' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>رقم</th><th>عميل</th><th>عملة</th><th>الإجمالي</th><th>حالة</th><th></th></tr></thead>
              <tbody>
                {(invoices.data || []).map((i: {
                  id: number
                  invoice_number: string
                  total: number
                  status: string
                  currency?: string
                  customer?: { name: string }
                }) => (
                  <tr key={i.id}>
                    <td className="font-mono text-xs">{i.invoice_number}</td>
                    <td>{i.customer?.name}</td>
                    <td>{i.currency || 'SYP'}</td>
                    <td className="tabular-nums">{i.total}</td>
                    <td>{i.status}</td>
                    <td>
                      <button type="button" className="text-xs text-teal print-hide" onClick={() => setPrintId(i.id)}>طباعة</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm print-hide" onSubmit={(e) => { e.preventDefault(); saveInv.mutate() }}>
            <h2 className="font-semibold">فاتورة مبيعات</h2>
            <Field label="التاريخ"><input type="date" className={inputClass} value={inv.invoice_date} onChange={(e) => setInv({ ...inv, invoice_date: e.target.value })} /></Field>
            <Field label="عميل"><select className={inputClass} value={inv.customer_id} onChange={(e) => setInv({ ...inv, customer_id: e.target.value })} required><option value="">—</option>{(customers.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="مخزن"><select className={inputClass} value={inv.warehouse_id} onChange={(e) => setInv({ ...inv, warehouse_id: e.target.value })} required><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label="العملة">
                <select className={inputClass} value={inv.currency} onChange={(e) => setInv({ ...inv, currency: e.target.value })}>
                  <option value="SYP">SYP</option>
                  <option value="TRY">TRY</option>
                  <option value="USD">USD</option>
                </select>
              </Field>
              <Field label="سعر الصرف (اختياري)" hint="إلى العملة الأساسية">
                <input className={inputClass} value={inv.exchange_rate} onChange={(e) => setInv({ ...inv, exchange_rate: e.target.value })} placeholder="تلقائي" />
              </Field>
            </div>
            <Field label="صنف"><select className={inputClass} value={inv.product_id} onChange={(e) => setInv({ ...inv, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p: { id: number; name: string; sale_price: number }) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label="كمية"><input className={inputClass} value={inv.quantity} onChange={(e) => setInv({ ...inv, quantity: e.target.value })} /></Field>
              <Field label="سعر (اختياري)"><input className={inputClass} value={inv.unit_price} onChange={(e) => setInv({ ...inv, unit_price: e.target.value })} /></Field>
            </div>
            <button type="submit" className="btn btn-primary w-full">ترحيل الفاتورة</button>
          </form>
        </div>
      )}

      {printId && invoiceDetail.data && (
        <Panel className="print-area mt-4 p-6">
          <div className="print-hide mb-3 flex gap-2">
            <Button variant="secondary" onClick={() => window.print()}><Printer size={16} /> طباعة الفاتورة</Button>
            <Button variant="ghost" onClick={() => setPrintId(null)}>إغلاق</Button>
          </div>
          <InvoicePrintView invoice={invoiceDetail.data} qrPayload={invoiceQr.data?.qr_payload} />
        </Panel>
      )}

      {tab === 'returns' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">عميل</th><th className="px-4 py-3">إجمالي</th><th className="px-4 py-3">حالة</th></tr></thead>
            <tbody>
              {(returns.data || []).map((r: { id: number; return_number: string; total: number; status: string; customer?: { name: string } }) => (
                <tr key={r.id} className="border-t border-black/5"><td className="px-4 py-3 font-mono text-xs">{r.return_number}</td><td className="px-4 py-3">{r.customer?.name}</td><td className="px-4 py-3">{r.total}</td><td className="px-4 py-3">{r.status}</td></tr>
              ))}
              {(returns.data || []).length === 0 && <tr><td colSpan={4} className="px-4 py-6 text-black/50">لا توجد مرتجعات — أنشئ عبر API أو أضف شاشة لاحقاً</td></tr>}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'receipts' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">عميل</th><th className="px-4 py-3">مبلغ</th><th className="px-4 py-3">حالة</th></tr></thead>
              <tbody>
                {(receipts.data || []).map((r: { id: number; receipt_number: string; amount: number; status: string; customer?: { name: string } }) => (
                  <tr key={r.id} className="border-t border-black/5"><td className="px-4 py-3 font-mono text-xs">{r.receipt_number}</td><td className="px-4 py-3">{r.customer?.name}</td><td className="px-4 py-3">{r.amount}</td><td className="px-4 py-3">{r.status}</td></tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveRc.mutate() }}>
            <h2 className="font-semibold">سند قبض</h2>
            <Field label="عميل"><select className={inputClass} value={rc.customer_id} onChange={(e) => setRc({ ...rc, customer_id: e.target.value })} required><option value="">—</option>{(customers.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="فاتورة"><select className={inputClass} value={rc.sales_invoice_id} onChange={(e) => setRc({ ...rc, sales_invoice_id: e.target.value })}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field>
            <Field label="صندوق"><select className={inputClass} value={rc.cash_box_id} onChange={(e) => setRc({ ...rc, cash_box_id: e.target.value })}><option value="">—</option>{(cashBoxes.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="المبلغ"><input className={inputClass} value={rc.amount} onChange={(e) => setRc({ ...rc, amount: e.target.value })} required /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">ترحيل القبض</button>
          </form>
        </div>
      )}
    </div>
  )
}

function InvoicePrintView({
  invoice,
  qrPayload,
}: {
  invoice: {
    invoice_number: string
    invoice_date: string
    total: number
    tax_amount: number
    subtotal: number
    currency?: string
    customer?: { name: string }
    lines?: { product?: { name: string }; quantity: number; unit_price: number; line_total: number }[]
  }
  qrPayload?: string
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null)

  useEffect(() => {
    if (canvasRef.current && qrPayload) {
      void QRCode.toCanvas(canvasRef.current, qrPayload, { width: 120, margin: 1 })
    }
  }, [qrPayload])

  return (
    <div className="space-y-4 text-sm">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-lg font-bold">فيوتشر أكونت</p>
          <p className="text-black/55">فاتورة مبيعات إلكترونية (stub)</p>
          <p className="mt-2 font-mono">{invoice.invoice_number}</p>
          <p>{String(invoice.invoice_date).slice(0, 10)}</p>
          <p>العميل: {invoice.customer?.name}</p>
        </div>
        {qrPayload && <canvas ref={canvasRef} className="rounded border border-black/10" />}
      </div>
      <table className="data-table">
        <thead><tr><th>صنف</th><th>كمية</th><th>سعر</th><th>الإجمالي</th></tr></thead>
        <tbody>
          {(invoice.lines || []).map((l, i) => (
            <tr key={i}>
              <td>{l.product?.name}</td>
              <td>{l.quantity}</td>
              <td>{l.unit_price}</td>
              <td>{l.line_total}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="space-y-1 text-left" dir="ltr">
        <p>Subtotal: {invoice.subtotal}</p>
        <p>Tax: {invoice.tax_amount}</p>
        <p className="font-bold">Total ({invoice.currency || 'SYP'}): {invoice.total}</p>
      </div>
    </div>
  )
}
