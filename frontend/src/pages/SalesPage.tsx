import { useCallback, useEffect, useRef, useState, type Dispatch, type SetStateAction } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import api from '@/lib/api'
import { todayYmd } from '@/lib/dates'
import { openPrintPopup } from '@/lib/printPopup'
import { documentStatusLabel } from '@/lib/statusLabels'
import { useQueryTab } from '@/lib/useQueryTab'
import BarcodeScanInput from '@/components/BarcodeScanInput'
import { DocumentCurrencyFields, PaymentCurrencyFields, type CurrencyOption } from '@/components/CurrencyFields'
import { Button, Field, Modal, Msg, NumericInput, PageHeader, Panel, Tabs, formatQuantity, inputClass, useFormMessage } from '@/components/ui'

const SALES_TABS = ['quotes', 'orders', 'invoices', 'returns', 'receipts'] as const

type ProductRow = { id: number; name: string; sale_price: number; track_batch?: boolean; track_serial?: boolean }

type StockLocation = { warehouse_id: number; warehouse_name: string; batch_no: string; quantity: number }

type StockInfo = {
  available_qty: number
  warehouse_id: number
  warehouse_name?: string
  breakdown: StockLocation[]
  track_batch?: boolean
}

function linePayload(productId: string, qty: string, price: string, batch: string, serial: string, taxRate: number) {
  return {
    product_id: Number(productId),
    quantity: Number(qty),
    unit_price: price ? Number(price) : undefined,
    tax_rate: taxRate,
    batch_no: batch || undefined,
    serial_no: serial || undefined,
  }
}

async function fetchStockInfo(productId: string, warehouseId: string, batchNo?: string): Promise<StockInfo | null> {
  if (!productId || !warehouseId) return null
  try {
    const params: Record<string, string> = { warehouse_id: warehouseId }
    if (batchNo) params.batch_no = batchNo
    const res = await api.get(`/products/${productId}/stock`, { params })
    return res.data.data as StockInfo
  } catch {
    return null
  }
}

export default function SalesPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useQueryTab(SALES_TABS, 'invoices')
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
  const taxEnabled = !['0', 'false', 'no', 'off'].includes(String(settings.data?.find((s) => s.key === 'tax_enabled')?.value ?? '1').toLowerCase())
  const defaultTaxRate = taxEnabled ? Number(settings.data?.find((s) => s.key === 'tax_rate')?.value ?? 15) || 0 : 0
  const cashBoxes = useQuery({ queryKey: ['cash-boxes'], queryFn: async () => (await api.get('/cash-boxes')).data.data, enabled: tab === 'receipts' })
  const currencies = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api.get('/currencies')).data.data as { base_currency: string; currencies: CurrencyOption[] },
  })
  const currencyList = currencies.data?.currencies || []
  const baseCurrency = currencies.data?.base_currency || 'SYP'

  const emptyLine = { customer_id: '', warehouse_id: '', product_id: '', quantity: '1', unit_price: '', batch_no: '', serial_no: '', currency: 'SYP', exchange_rate: '1' }

  const [quote, setQuote] = useState({ quote_date: todayYmd(), valid_until: '', ...emptyLine })
  const [order, setOrder] = useState({ order_date: todayYmd(), ...emptyLine })
  const [inv, setInv] = useState({ invoice_date: todayYmd(), status: 'posted', ...emptyLine })
  const [ret, setRet] = useState({
    return_date: todayYmd(),
    customer_id: '',
    warehouse_id: '',
    sales_invoice_id: '',
    product_id: '',
    quantity: '1',
    unit_price: '',
    batch_no: '',
    serial_no: '',
    currency: 'SYP',
    exchange_rate: '1',
    status: 'posted',
  })
  const [rc, setRc] = useState({
    receipt_date: todayYmd(),
    customer_id: '',
    sales_invoice_id: '',
    cash_box_id: '',
    amount: '',
    base_amount: '',
    currency: 'SYP',
    exchange_rate: '1',
    method: 'cash',
    status: 'posted',
  })
  const [stockInfo, setStockInfo] = useState<StockInfo | null>(null)
  const skipStockAutofill = useRef(false)

  const selectedProduct = (products.data || []).find((p) => String(p.id) === inv.product_id)

  const applyStockToForm = useCallback(async <T extends { product_id: string; warehouse_id?: string; quantity: string; batch_no?: string }>(
    setState: Dispatch<SetStateAction<T>>,
    productId: string,
    warehouseId: string,
    batchNo?: string,
  ) => {
    const info = await fetchStockInfo(productId, warehouseId, batchNo)
    if (info === null) {
      setStockInfo(null)
      return
    }
    setStockInfo(info)
    setState((prev) => ({ ...prev, quantity: String(info.available_qty) }))
  }, [])

  async function handleBarcodeScan(code: string, target: 'inv' | 'order' | 'quote' = 'inv') {
    try {
      const res = await api.get(`/products?barcode=${encodeURIComponent(code)}`)
      const found = (res.data.data as ProductRow[])[0]
      if (!found) {
        msg.setError(t('sales.barcodeNotFound'))
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
      msg.setMessage(t('sales.barcodeFound', { name: found.name }))
    } catch {
      msg.setError(t('sales.barcodeSearchFailed'))
    }
  }

  const invalidateSales = () => void qc.invalidateQueries({ queryKey: ['sales-quotes', 'sales-orders', 'sales-invoices', 'sales-returns', 'receipts', 'stock-levels'] })
  const closeModal = () => { setModal(null); setSelectedId(null); setSelectedRow(null); setStockInfo(null) }

  const salesDeletePath = (rowTab: string, id: number) => {
    if (rowTab === 'quotes') return `/sales-quotes/${id}`
    if (rowTab === 'orders') return `/sales-orders/${id}`
    if (rowTab === 'invoices') return `/sales-invoices/${id}`
    if (rowTab === 'returns') return `/sales-returns/${id}`
    return `/receipts/${id}`
  }

  const canDeleteSales = (rowTab: string, status: string) => {
    if (rowTab === 'quotes') return status !== 'converted'
    if (rowTab === 'orders') return status === 'draft'
    return status === 'draft'
  }

  const deleteDoc = useMutation({
    mutationFn: ({ path }: { path: string }) => api.delete(path),
    onSuccess: () => {
      msg.setMessage(t('common.deleted'))
      invalidateSales()
      closeModal()
    },
    onError: msg.fromErr,
  })

  const askDelete = (rowTab: string, id: number, status: string) => {
    if (!canDeleteSales(rowTab, status)) return
    if (!window.confirm(t('common.confirmDelete'))) return
    deleteDoc.mutate({ path: salesDeletePath(rowTab, id) })
  }
  const openCreate = () => {
    setSelectedId(null)
    setSelectedRow(null)
    setStockInfo(null)
    skipStockAutofill.current = false
    if (defaultWarehouseId) {
      setQuote((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
      setOrder((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
      setInv((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
    }
    setModal('create')
  }
  const openRow = (row: Record<string, unknown> & { id: number }, editable = false) => {
    setSelectedId(row.id)
    setSelectedRow(row)
    setModal(editable ? 'edit' : 'view')
  }
  const printInvoice = (id: number) => openPrintPopup(`/print/sales-invoices/${id}`)

  const saveQuote = useMutation({
    mutationFn: () => api.post('/sales-quotes', {
      quote_date: quote.quote_date,
      valid_until: quote.valid_until || undefined,
      customer_id: Number(quote.customer_id),
      warehouse_id: Number(quote.warehouse_id) || undefined,
      currency: quote.currency,
      exchange_rate: quote.exchange_rate ? Number(quote.exchange_rate) : undefined,
      lines: [linePayload(quote.product_id, quote.quantity, quote.unit_price, quote.batch_no, quote.serial_no, defaultTaxRate)],
    }),
    onSuccess: () => { msg.setMessage(t('sales.quoteSaved')); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  const saveOrder = useMutation({
    mutationFn: () => api.post('/sales-orders', {
      order_date: order.order_date,
      customer_id: Number(order.customer_id),
      warehouse_id: Number(order.warehouse_id) || undefined,
      currency: order.currency,
      exchange_rate: order.exchange_rate ? Number(order.exchange_rate) : undefined,
      lines: [linePayload(order.product_id, order.quantity, order.unit_price, order.batch_no, order.serial_no, defaultTaxRate)],
    }),
    onSuccess: () => { msg.setMessage(t('sales.orderSaved')); invalidateSales(); closeModal() },
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
      lines: [linePayload(inv.product_id, inv.quantity, inv.unit_price, inv.batch_no, inv.serial_no, defaultTaxRate)],
    }),
    onSuccess: () => { msg.setMessage(t('sales.invoicePosted')); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  const saveRet = useMutation({
    mutationFn: () => api.post('/sales-returns', {
      return_date: ret.return_date,
      customer_id: Number(ret.customer_id),
      warehouse_id: Number(ret.warehouse_id) || undefined,
      sales_invoice_id: ret.sales_invoice_id ? Number(ret.sales_invoice_id) : null,
      currency: ret.currency,
      exchange_rate: ret.exchange_rate ? Number(ret.exchange_rate) : undefined,
      status: ret.status,
      lines: [{ product_id: Number(ret.product_id), quantity: Number(ret.quantity), unit_price: Number(ret.unit_price), batch_no: ret.batch_no || undefined, serial_no: ret.serial_no || undefined }],
    }),
    onSuccess: () => { msg.setMessage(t('sales.returnPosted')); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  const convertQuote = useMutation({
    mutationFn: (id: number) => api.post(`/sales-quotes/${id}/convert-to-order`),
    onSuccess: () => { msg.setMessage(t('sales.convertedToOrder')); invalidateSales() },
    onError: msg.fromErr,
  })

  const updateQuote = useMutation({
    mutationFn: (id: number) => api.put(`/sales-quotes/${id}`, {
      quote_date: quote.quote_date,
      valid_until: quote.valid_until || undefined,
      customer_id: Number(quote.customer_id),
      warehouse_id: Number(quote.warehouse_id) || undefined,
      currency: quote.currency,
      exchange_rate: quote.exchange_rate ? Number(quote.exchange_rate) : undefined,
      lines: [linePayload(quote.product_id, quote.quantity, quote.unit_price, quote.batch_no, quote.serial_no, defaultTaxRate)],
    }),
    onSuccess: () => { msg.setMessage(t('sales.quoteUpdated')); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  const convertOrder = useMutation({
    mutationFn: (id: number) => api.post(`/sales-orders/${id}/convert-to-invoice`, { status: 'posted' }),
    onSuccess: () => { msg.setMessage(t('sales.convertedToInvoice')); invalidateSales() },
    onError: msg.fromErr,
  })

  const saveRc = useMutation({
    mutationFn: () => api.post('/receipts', {
      receipt_date: rc.receipt_date,
      customer_id: Number(rc.customer_id),
      sales_invoice_id: rc.sales_invoice_id ? Number(rc.sales_invoice_id) : null,
      cash_box_id: rc.cash_box_id ? Number(rc.cash_box_id) : null,
      method: rc.method,
      status: rc.status,
      amount: Number(rc.amount),
      currency: rc.currency,
      exchange_rate: rc.exchange_rate ? Number(rc.exchange_rate) : undefined,
      base_amount: rc.base_amount ? Number(rc.base_amount) : undefined,
    }),
    onSuccess: () => { msg.setMessage(t('sales.receiptSaved')); void qc.invalidateQueries({ queryKey: ['receipts', 'sales-invoices'] }); closeModal() },
    onError: msg.fromErr,
  })

  const applyInvoiceCurrency = <T extends { currency: string; exchange_rate: string }>(
    invoiceId: string,
    setState: Dispatch<SetStateAction<T>>,
  ) => {
    const row = (invoices.data || []).find((i: { id: number }) => String(i.id) === invoiceId) as
      | { currency?: string; exchange_rate?: number | string }
      | undefined
    if (!row) return
    setState((prev) => ({
      ...prev,
      currency: row.currency || prev.currency || baseCurrency,
      exchange_rate: row.exchange_rate != null && row.exchange_rate !== ''
        ? String(row.exchange_rate)
        : prev.exchange_rate,
    }))
  }

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
    setStockInfo(null)
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
          label={t('sales.scanBarcode')}
          hint={t('sales.scanBarcodeHint')}
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
              void applyStockToForm(setState, productId, state.warehouse_id, state.batch_no || undefined)
            } else if (!productId) {
              setStockInfo(null)
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
          {autoFillStock && stockInfo !== null && state.product_id && state.warehouse_id && (
            <StockAvailabilityHint
              stockInfo={stockInfo}
              batchNo={state.batch_no}
              onSelectBatch={(batch) => {
                setState((prev) => ({ ...prev, batch_no: batch }))
                if (state.warehouse_id) {
                  void applyStockToForm(setState, state.product_id, state.warehouse_id, batch)
                }
              }}
            />
          )}
        </Field>
        <Field label={t('common.price')}><NumericInput value={state.unit_price} onChange={(v) => setState((prev) => ({ ...prev, unit_price: v }))} /></Field>
      </div>
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_batch && (
        <Field label={t('common.batch')}>
          <input
            className={inputClass}
            value={state.batch_no}
            onChange={(e) => {
              const batch = e.target.value
              setState({ ...state, batch_no: batch })
              if (autoFillStock && state.product_id && state.warehouse_id) {
                void applyStockToForm(setState, state.product_id, state.warehouse_id, batch || undefined)
              }
            }}
            required
          />
        </Field>
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
      setStockInfo(null)
    }
  }

  const summary = (data: Record<string, unknown>) => {
    const warehouseName = (data.warehouse as { name?: string } | undefined)?.name
    const lines = ((data.items || data.lines) as {
      product?: { id?: number; name?: string }
      product_id?: number
      quantity?: number
      line_total?: number
      batch_no?: string
      serial_no?: string
    }[] | undefined) || []

    return (
      <div className="space-y-3 text-sm">
        <div className="grid gap-3 sm:grid-cols-2">
          <p><b>{t('common.number')}:</b> {String(data.quote_number || data.order_number || data.invoice_number || data.return_number || data.receipt_number || '—')}</p>
          <p><b>{t('common.status')}:</b> {documentStatusLabel(String(data.status || ''))}</p>
          <p><b>{t('common.customer')}:</b> {(data.customer as { name?: string } | undefined)?.name || '—'}</p>
          <p><b>{t('common.warehouse')}:</b> {warehouseName || '—'}</p>
          <p><b>{t('common.currency')}:</b> {String(data.currency || baseCurrency)}</p>
          {data.exchange_rate != null && String(data.currency || baseCurrency) !== baseCurrency && (
            <p><b>{t('common.exchangeRate')}:</b> {String(data.exchange_rate)}</p>
          )}
          <p><b>{t('common.total')}:</b> {String(data.total || data.amount || '—')} {String(data.currency || baseCurrency)}</p>
        </div>
        {lines.length > 0 && (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('common.product')}</th>
                  <th title={t('common.quantityUnit')}>{t('common.quantity')}</th>
                  <th>{t('common.batch')}</th>
                  <th>{t('sales.stockLocation')}</th>
                  <th>{t('common.total')}</th>
                </tr>
              </thead>
              <tbody>
                {lines.map((line, index) => (
                  <tr key={index}>
                    <td>{line.product?.name}</td>
                    <td className="tabular-nums">{formatQuantity(line.quantity)}</td>
                    <td className="font-mono text-xs">{line.batch_no || line.serial_no || '—'}</td>
                    <td>
                      <LineStockHint
                        productId={line.product?.id || line.product_id}
                        warehouseId={data.warehouse_id as number | undefined}
                        batchNo={line.batch_no}
                      />
                    </td>
                    <td>{line.line_total}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('sales.title')} subtitle={t('sales.subtitle')} actions={<Button variant="primary" onClick={openCreate}>{t('common.add')}</Button>} />
      <Tabs tabs={tabs} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'quotes' && (
        <Panel>
            <div className="table-wrap">
            <table className="data-table text-sm">
              <thead><tr><th>{t('common.number')}</th><th>{t('common.customer')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(quotes.data || []).map((q: { id: number; quote_number: string; total: number; status: string; currency?: string; customer?: { name: string } }) => (
                  <tr key={q.id} className="cursor-pointer" onClick={() => openRow(q, q.status !== 'converted')}>
                    <td className="font-mono text-xs">{q.quote_number}</td>
                    <td>{q.customer?.name}</td>
                    <td>{q.currency || 'SYP'}</td>
                    <td>{q.total}</td>
                    <td>{documentStatusLabel(q.status)}</td>
                    <td className="space-x-2 space-x-reverse">
                      {q.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={(e) => { e.stopPropagation(); convertQuote.mutate(q.id) }}>{t('sales.convertToOrder')}</button>}
                      {canDeleteSales('quotes', q.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('quotes', q.id, q.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={t('common.cannotDeleteConverted')}>{t('common.delete')}</span>}
                    </td>
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
              <thead><tr><th>{t('common.number')}</th><th>{t('common.customer')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(orders.data || []).map((o: { id: number; order_number: string; total: number; status: string; currency?: string; customer?: { name: string } }) => (
                  <tr key={o.id} className="cursor-pointer" onClick={() => openRow(o)}>
                    <td className="font-mono text-xs">{o.order_number}</td>
                    <td>{o.customer?.name}</td>
                    <td>{o.currency || 'SYP'}</td>
                    <td>{o.total}</td>
                    <td>{documentStatusLabel(o.status)}</td>
                    <td className="space-x-2 space-x-reverse">
                      {o.status !== 'converted' && <button type="button" className="text-xs text-teal" onClick={(e) => { e.stopPropagation(); convertOrder.mutate(o.id) }}>{t('sales.convertToInvoice')}</button>}
                      {canDeleteSales('orders', o.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('orders', o.id, o.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={o.status === 'converted' ? t('common.cannotDeleteConverted') : t('common.cannotDeletePosted')}>{t('common.delete')}</span>}
                    </td>
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
              <thead><tr><th>{t('common.number')}</th><th>{t('common.customer')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(invoices.data || []).map((i: { id: number; invoice_number: string; total: number; status: string; currency?: string; customer?: { name: string } }) => (
                  <tr key={i.id} className="cursor-pointer" onClick={() => openRow(i)}>
                    <td className="font-mono text-xs">{i.invoice_number}</td>
                    <td>{i.customer?.name}</td>
                    <td>{i.currency || 'SYP'}</td>
                    <td className="tabular-nums">{i.total}</td>
                    <td>{documentStatusLabel(i.status)}</td>
                    <td className="space-x-2 space-x-reverse">
                      <button type="button" className="text-xs text-teal print-hide" onClick={(e) => { e.stopPropagation(); printInvoice(i.id) }}>{t('common.print')}</button>
                      {canDeleteSales('invoices', i.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('invoices', i.id, i.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={t('common.cannotDeletePosted')}>{t('common.delete')}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
        </Panel>
      )}

      {tab === 'returns' && (
        <Panel>
            <div className="table-wrap">
            <table className="data-table text-sm">
              <thead><tr><th>{t('common.number')}</th><th>{t('common.customer')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(returns.data || []).map((r: { id: number; return_number: string; total: number; status: string; currency?: string; customer?: { name: string } }) => (
                  <tr key={r.id} className="cursor-pointer" onClick={() => openRow(r)}>
                    <td className="font-mono text-xs">{r.return_number}</td>
                    <td>{r.customer?.name}</td>
                    <td>{r.currency || 'SYP'}</td>
                    <td>{r.total}</td>
                    <td>{documentStatusLabel(r.status)}</td>
                    <td>
                      {canDeleteSales('returns', r.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('returns', r.id, r.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={t('common.cannotDeletePosted')}>{t('common.delete')}</span>}
                    </td>
                  </tr>
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
              <thead><tr><th>{t('common.number')}</th><th>{t('common.customer')}</th><th>{t('common.currency')}</th><th>{t('common.amount')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(receipts.data || []).map((r: { id: number; receipt_number: string; amount: number; status: string; currency?: string; customer?: { name: string } }) => (
                  <tr key={r.id} className="cursor-pointer" onClick={() => openRow(r)}>
                    <td className="font-mono text-xs">{r.receipt_number}</td>
                    <td>{r.customer?.name}</td>
                    <td>{r.currency || 'SYP'}</td>
                    <td>{r.amount}</td>
                    <td>{documentStatusLabel(r.status)}</td>
                    <td>
                      {canDeleteSales('receipts', r.status)
                        ? <button type="button" className="text-xs text-rose-600" onClick={(e) => { e.stopPropagation(); askDelete('receipts', r.id, r.status) }}>{t('common.delete')}</button>
                        : <span className="text-xs text-black/40" title={t('common.cannotDeletePosted')}>{t('common.delete')}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
        </Panel>
      )}
      <Modal open={modal !== null} onClose={closeModal} title={modal === 'create' ? t('common.add') : modal === 'edit' ? t('common.edit') : t('common.view')} size={tab === 'invoices' && modal === 'view' ? 'xl' : 'md'} footer={modal !== 'view' ? <><Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button><Button variant="primary" type="submit" form="sales-form">{t('common.save')}</Button></> : <>
          {tab === 'invoices' && selectedId && (
            <Button variant="secondary" onClick={() => printInvoice(selectedId)}><Printer size={16} /> {t('common.print')}</Button>
          )}
          {selectedId && selectedRow && canDeleteSales(tab, String(selectedRow.status || '')) && (
            <Button variant="danger" disabled={deleteDoc.isPending} onClick={() => askDelete(tab, selectedId, String(selectedRow.status || ''))}>{t('common.delete')}</Button>
          )}
          <Button variant="secondary" onClick={closeModal}>{t('common.close')}</Button>
        </>}>
        {modal === 'view' ? (detail.isLoading ? <p>{t('common.loading')}</p> : summary(detail.data || selectedRow || {})) : (
          <form id="sales-form" className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (tab === 'quotes') modal === 'edit' && selectedId ? updateQuote.mutate(selectedId) : saveQuote.mutate(); else if (tab === 'orders') saveOrder.mutate(); else if (tab === 'invoices') saveInv.mutate(); else if (tab === 'returns') saveRet.mutate(); else saveRc.mutate() }}>
            {tab === 'quotes' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={quote.quote_date} onChange={(e) => setQuote({ ...quote, quote_date: e.target.value })} /></Field><Field label={t('common.validUntil')}><input type="date" className={inputClass} value={quote.valid_until} onChange={(e) => setQuote({ ...quote, valid_until: e.target.value })} /></Field>{customerField(quote, setQuote, true, onSalesWarehouseChange(setQuote))}<DocumentCurrencyFields state={quote} setState={setQuote} currencies={currencyList} baseCurrency={baseCurrency} />{productFields(quote, setQuote, (code) => void handleBarcodeScan(code, 'quote'), true)}</>}
            {tab === 'orders' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={order.order_date} onChange={(e) => setOrder({ ...order, order_date: e.target.value })} /></Field>{customerField(order, setOrder, true, onSalesWarehouseChange(setOrder))}<DocumentCurrencyFields state={order} setState={setOrder} currencies={currencyList} baseCurrency={baseCurrency} />{productFields(order, setOrder, (code) => void handleBarcodeScan(code, 'order'), true)}</>}
            {tab === 'invoices' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={inv.invoice_date} onChange={(e) => setInv({ ...inv, invoice_date: e.target.value })} /></Field>{customerField(inv, setInv, true, onSalesWarehouseChange(setInv))}<DocumentCurrencyFields state={inv} setState={setInv} currencies={currencyList} baseCurrency={baseCurrency} showBasePreview documentTotal={(Number(inv.quantity) || 0) * (Number(inv.unit_price) || 0)} />{productFields(inv, setInv, (code) => void handleBarcodeScan(code, 'inv'), true)}{selectedProduct?.track_batch && <p className="text-xs text-amber">* {t('warehouse.trackBatch')}</p>}{selectedProduct?.track_serial && <p className="text-xs text-amber">* {t('warehouse.trackSerial')}</p>}</>}
            {tab === 'returns' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={ret.return_date} onChange={(e) => setRet({ ...ret, return_date: e.target.value })} /></Field>{customerField(ret, setRet)}<Field label={t('common.invoice')}><select className={inputClass} value={ret.sales_invoice_id} onChange={(e) => { const id = e.target.value; setRet({ ...ret, sales_invoice_id: id }); applyInvoiceCurrency(id, setRet) }}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field><DocumentCurrencyFields state={ret} setState={setRet} currencies={currencyList} baseCurrency={baseCurrency} />{productFields(ret, setRet)}</>}
            {tab === 'receipts' && <>{customerField(rc, setRc, false)}<Field label={t('common.invoice')}><select className={inputClass} value={rc.sales_invoice_id} onChange={(e) => { const id = e.target.value; setRc({ ...rc, sales_invoice_id: id }); applyInvoiceCurrency(id, setRc) }}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field><Field label={t('common.cashBox')}><select className={inputClass} value={rc.cash_box_id} onChange={(e) => setRc({ ...rc, cash_box_id: e.target.value })}><option value="">—</option>{(cashBoxes.data || []).map((c: { id: number; name: string }) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field><PaymentCurrencyFields state={rc} setState={setRc} currencies={currencyList} baseCurrency={baseCurrency} /></>}
          </form>
        )}
      </Modal>
    </div>
  )
}

function StockAvailabilityHint({
  stockInfo,
  batchNo,
  onSelectBatch,
}: {
  stockInfo: StockInfo
  batchNo?: string
  onSelectBatch?: (batch: string) => void
}) {
  const { t } = useTranslation()
  const warehouseLabel = stockInfo.warehouse_name || t('common.warehouse')

  return (
    <div className="mt-1 space-y-1 text-xs text-black/55">
      <p>
        {batchNo && stockInfo.track_batch
          ? t('sales.stockRemainingBatch', {
              qty: formatQuantity(stockInfo.available_qty),
              warehouse: warehouseLabel,
              batch: batchNo,
            })
          : t('sales.stockRemainingIn', {
              qty: formatQuantity(stockInfo.available_qty),
              warehouse: warehouseLabel,
            })}
      </p>
      {stockInfo.breakdown.length > 0 && (
        <ul className="space-y-0.5">
          {stockInfo.breakdown.map((row) => (
            <li key={`${row.warehouse_id}-${row.batch_no}`} className="flex flex-wrap items-center gap-1">
              <span>
                {row.batch_no ? `${t('common.batch')} ${row.batch_no}: ` : ''}
                {formatQuantity(row.quantity)}
              </span>
              {stockInfo.track_batch && row.batch_no && onSelectBatch && batchNo !== row.batch_no && (
                <button type="button" className="text-teal underline" onClick={() => onSelectBatch(row.batch_no)}>
                  {t('sales.useBatch')}
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
      {stockInfo.breakdown.length === 0 && (
        <p className="text-amber">{t('sales.noStockInWarehouse')}</p>
      )}
    </div>
  )
}

function LineStockHint({
  productId,
  warehouseId,
  batchNo,
}: {
  productId?: number
  warehouseId?: number
  batchNo?: string
}) {
  const { t } = useTranslation()
  const [info, setInfo] = useState<StockInfo | null>(null)

  useEffect(() => {
    if (!productId || !warehouseId) {
      setInfo(null)
      return
    }
    let active = true
    void fetchStockInfo(String(productId), String(warehouseId), batchNo).then((data) => {
      if (active) setInfo(data)
    })
    return () => { active = false }
  }, [productId, warehouseId, batchNo])

  if (!productId || !warehouseId) return <span className="text-black/40">—</span>
  if (!info) return <span className="text-black/40">{t('common.loading')}</span>

  if (info.breakdown.length === 0) {
    return <span className="text-danger">{t('sales.noStockInWarehouse')}</span>
  }

  return (
    <span className="text-xs text-black/60">
      {info.breakdown.map((row, i) => (
        <span key={`${row.batch_no}-${i}`}>
          {i > 0 ? '؛ ' : ''}
          {row.batch_no ? `${t('common.batch')} ${row.batch_no}: ` : ''}
          {formatQuantity(row.quantity)}
        </span>
      ))}
    </span>
  )
}

