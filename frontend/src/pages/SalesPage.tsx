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
import PaymentTypeFields, { paymentTypeLabel } from '@/components/PaymentTypeFields'
import AttachmentPanel, { AttachmentIcon, PendingAttachmentField, uploadAttachment } from '@/components/AttachmentPanel'
import WhatsAppSendButton from '@/components/WhatsAppSendButton'
import ExcelExportButton from '@/components/ExcelExportButton'
import PdfExportButton from '@/components/PdfExportButton'
import { excelModuleForSalesTab } from '@/lib/excelExport'
import { Button, EmptyState, Field, ListSearchInput, Modal, Msg, NumericInput, PageHeader, Panel, Tabs, formatQuantity, inputClass, useFormMessage } from '@/components/ui'
import { useListSearch } from '@/lib/useListSearch'
import { formatProductUnit, unitFromProduct } from '@/lib/productUnit'

const SALES_TABS = ['quotes', 'orders', 'invoices', 'returns', 'receipts'] as const

type ProductRow = { id: number; name: string; sale_price: number; track_batch?: boolean; track_serial?: boolean; unit?: { name?: string; symbol?: string } }

type StockLocation = { warehouse_id: number; warehouse_name: string; batch_no: string; quantity: number }

type StockInfo = {
  available_qty: number
  warehouse_id: number
  warehouse_name?: string
  breakdown: StockLocation[]
  track_batch?: boolean
}

type InvoiceLineDraft = {
  product_id: string
  quantity: string
  unit_price: string
  batch_no: string
  serial_no: string
}

function emptyInvoiceLine(): InvoiceLineDraft {
  return { product_id: '', quantity: '1', unit_price: '', batch_no: '', serial_no: '' }
}

function linePayload(productId: string, qty: string, price: string, _batch: string, serial: string, taxRate: number) {
  return {
    product_id: Number(productId),
    quantity: Number(qty),
    unit_price: price ? Number(price) : undefined,
    tax_rate: taxRate,
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
  const search = useListSearch()
  const [modal, setModal] = useState<'create' | 'view' | 'edit' | 'collect' | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [selectedRow, setSelectedRow] = useState<Record<string, unknown> | null>(null)
  const [collectForm, setCollectForm] = useState({ amount: '', cash_box_id: '', receipt_date: todayYmd() })

  const quotes = useQuery({
    queryKey: ['sales-quotes', search.debouncedQ],
    queryFn: async () => (await api.get('/sales-quotes', { params: search.params })).data.data,
    enabled: tab === 'quotes',
  })
  const orders = useQuery({
    queryKey: ['sales-orders', search.debouncedQ],
    queryFn: async () => (await api.get('/sales-orders', { params: search.params })).data.data,
    enabled: tab === 'orders',
  })
  const invoices = useQuery({
    queryKey: ['sales-invoices', search.debouncedQ],
    queryFn: async () => (await api.get('/sales-invoices', { params: search.params })).data.data,
  })
  const returns = useQuery({
    queryKey: ['sales-returns', search.debouncedQ],
    queryFn: async () => (await api.get('/sales-returns', { params: search.params })).data.data,
    enabled: tab === 'returns',
  })
  const receipts = useQuery({
    queryKey: ['receipts', search.debouncedQ],
    queryFn: async () => (await api.get('/receipts', { params: search.params })).data.data,
    enabled: tab === 'receipts',
  })
  const customers = useQuery({ queryKey: ['customers'], queryFn: async () => (await api.get('/customers')).data.data })
  const products = useQuery({ queryKey: ['products'], queryFn: async () => (await api.get('/products')).data.data as ProductRow[] })
  const warehouses = useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.get('/warehouses')).data.data as { id: number; name: string; branch_id?: number | null }[],
  })
  const branches = useQuery({
    queryKey: ['branches'],
    queryFn: async () => (await api.get('/branches')).data.data as { id: number; name: string; code?: string; is_main?: boolean }[],
  })
  const settings = useQuery({ queryKey: ['settings'], queryFn: async () => (await api.get('/settings')).data.data as { key: string; value: string }[] })
  const defaultWarehouseId = settings.data?.find((s) => s.key === 'default_warehouse_id')?.value || ''
  const defaultBranchId = settings.data?.find((s) => s.key === 'default_branch_id')?.value
    || String((branches.data || []).find((b) => b.is_main)?.id || (branches.data || [])[0]?.id || '')
  const taxEnabled = !['0', 'false', 'no', 'off'].includes(String(settings.data?.find((s) => s.key === 'tax_enabled')?.value ?? '0').toLowerCase())
  const defaultTaxRate = taxEnabled ? Number(settings.data?.find((s) => s.key === 'tax_rate')?.value ?? 15) || 0 : 0
  const cashBoxes = useQuery({
    queryKey: ['cash-boxes'],
    queryFn: async () => (await api.get('/cash-boxes')).data.data as { id: number; name: string; currency?: string; is_default?: boolean; code?: string }[],
    enabled: tab === 'receipts' || tab === 'invoices',
  })
  const [pendingAttachment, setPendingAttachment] = useState<File | null>(null)
  const currencies = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api.get('/currencies')).data.data as { base_currency: string; currencies: CurrencyOption[] },
  })
  const currencyList = currencies.data?.currencies || []
  const baseCurrency = currencies.data?.base_currency || 'USD'

  const emptyLine = { customer_id: '', warehouse_id: '', product_id: '', quantity: '1', unit_price: '', batch_no: '', serial_no: '', currency: 'USD', exchange_rate: '1' }

  const [quote, setQuote] = useState({ quote_date: todayYmd(), valid_until: '', ...emptyLine })
  const [order, setOrder] = useState({ order_date: todayYmd(), ...emptyLine })
  const [inv, setInv] = useState({
    invoice_date: todayYmd(),
    status: 'posted',
    payment_type: 'credit',
    paid_amount: '',
    cash_box_id: '',
    discount_amount: '',
    notes: '',
    customer_id: '',
    warehouse_id: '',
    branch_id: '',
    currency: 'USD',
    exchange_rate: '1',
    lines: [emptyInvoiceLine()] as InvoiceLineDraft[],
  })
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
    currency: 'USD',
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
    currency: 'USD',
    exchange_rate: '1',
    method: 'cash',
    status: 'posted',
  })

  useEffect(() => {
    if (!rc.cash_box_id && (cashBoxes.data || []).length > 0) {
      const boxes = cashBoxes.data || []
      const main = boxes.find((c) => c.is_default) || boxes.find((c) => c.code === 'CASH-01') || boxes[0]
      if (main) setRc((prev) => prev.cash_box_id ? prev : { ...prev, cash_box_id: String(main.id) })
    }
  }, [cashBoxes.data, rc.cash_box_id])
  const [stockInfo, setStockInfo] = useState<StockInfo | null>(null)
  const skipStockAutofill = useRef(false)

  const invHasSerialProduct = inv.lines.some((line) =>
    (products.data || []).find((p) => String(p.id) === line.product_id)?.track_serial,
  )

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

  const applyStockToInvoiceLine = useCallback(async (index: number, productId: string, warehouseId: string) => {
    const info = await fetchStockInfo(productId, warehouseId)
    if (info === null) return
    setInv((prev) => ({
      ...prev,
      lines: prev.lines.map((line, i) => (i === index ? { ...line, quantity: String(info.available_qty) } : line)),
    }))
  }, [])

  const updateInvLine = (index: number, patch: Partial<InvoiceLineDraft>) => {
    setInv((prev) => ({
      ...prev,
      lines: prev.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
    }))
  }

  const addInvLine = () => setInv((prev) => ({ ...prev, lines: [...prev.lines, emptyInvoiceLine()] }))

  const removeInvLine = (index: number) => {
    setInv((prev) => ({
      ...prev,
      lines: prev.lines.length <= 1 ? prev.lines : prev.lines.filter((_, i) => i !== index),
    }))
  }

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
        setInv((prev) => {
          const emptyIdx = prev.lines.findIndex((l) => !l.product_id)
          const lines = emptyIdx >= 0
            ? prev.lines.map((line, i) => (i === emptyIdx ? { ...line, ...patch } : line))
            : [...prev.lines, { ...emptyInvoiceLine(), ...patch }]
          const targetIdx = emptyIdx >= 0 ? emptyIdx : lines.length - 1
          if (prev.warehouse_id && !skipStockAutofill.current) {
            void applyStockToInvoiceLine(targetIdx, String(found.id), prev.warehouse_id)
          }
          return { ...prev, lines }
        })
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

  const invalidateSales = () => {
    for (const key of ['sales-quotes', 'sales-orders', 'sales-invoices', 'sales-returns', 'receipts', 'stock-levels', 'cash-boxes', 'customers'] as const) {
      void qc.invalidateQueries({ queryKey: [key] })
    }
  }
  const closeModal = () => {
    setModal(null)
    setSelectedId(null)
    setSelectedRow(null)
    setStockInfo(null)
    setPendingAttachment(null)
    setCollectForm({ amount: '', cash_box_id: '', receipt_date: todayYmd() })
  }

  const invoiceRemaining = (invRow: { total?: number; paid_amount?: number }) =>
    Math.max(0, Math.round((Number(invRow.total || 0) - Number(invRow.paid_amount || 0)) * 100) / 100)

  const canCollectInvoice = (invRow: { status?: string; total?: number; paid_amount?: number }) =>
    invRow.status === 'posted' && invoiceRemaining(invRow) > 0

  const openCollect = (invRow: {
    id: number
    total?: number
    paid_amount?: number
    status?: string
    invoice_number?: string
    currency?: string
    customer?: { name?: string; phone?: string }
    cash_box_id?: number | null
  }) => {
    if (!canCollectInvoice(invRow)) return
    const boxes = cashBoxes.data || []
    const main = boxes.find((c) => c.is_default) || boxes.find((c) => c.code === 'CASH-01') || boxes[0]
    const defaultBox = invRow.cash_box_id ? String(invRow.cash_box_id) : (main ? String(main.id) : '')
    setSelectedId(invRow.id)
    setSelectedRow(invRow as unknown as Record<string, unknown>)
    setCollectForm({
      amount: String(invoiceRemaining(invRow)),
      cash_box_id: defaultBox,
      receipt_date: todayYmd(),
    })
    setModal('collect')
  }

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
    setInv({
      invoice_date: todayYmd(),
      status: 'posted',
      payment_type: 'credit',
      paid_amount: '',
      cash_box_id: '',
      discount_amount: '',
      notes: '',
      customer_id: '',
      warehouse_id: defaultWarehouseId || '',
      branch_id: defaultBranchId || '',
      currency: 'USD',
      exchange_rate: '1',
      lines: [emptyInvoiceLine()],
    })
    if (defaultWarehouseId) {
      setQuote((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
      setOrder((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
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
    mutationFn: async () => {
      const filledLines = inv.lines.filter((l) => l.product_id)
      if (filledLines.length === 0) {
        throw { response: { data: { message: t('common.linesRequired') } } }
      }
      const lineSub = filledLines.reduce(
        (sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0),
        0,
      )
      const discountAmt = Math.max(0, Number(inv.discount_amount) || 0)
      const taxAmt = taxEnabled ? round2(lineSub * defaultTaxRate / 100) : 0
      const estTotal = round2(lineSub - discountAmt + taxAmt)
      const res = await api.post('/sales-invoices', {
        invoice_date: inv.invoice_date,
        customer_id: Number(inv.customer_id),
        warehouse_id: Number(inv.warehouse_id),
        branch_id: inv.branch_id ? Number(inv.branch_id) : null,
        cash_box_id: inv.cash_box_id ? Number(inv.cash_box_id) : null,
        currency: inv.currency,
        exchange_rate: inv.exchange_rate ? Number(inv.exchange_rate) : undefined,
        payment_type: inv.payment_type || 'credit',
        paid_amount: inv.payment_type === 'partial' ? Number(inv.paid_amount) : undefined,
        discount_amount: discountAmt > 0 ? discountAmt : 0,
        status: inv.status,
        notes: inv.notes || null,
        lines: filledLines.map((l) => linePayload(l.product_id, l.quantity, l.unit_price, l.batch_no, l.serial_no, defaultTaxRate)),
      })
      const id = res.data?.data?.id as number | undefined
      if (id && pendingAttachment) await uploadAttachment('sales_invoice', id, pendingAttachment)
      return { res, estTotal }
    },
    onSuccess: () => { msg.setMessage(t('sales.invoicePosted')); setPendingAttachment(null); invalidateSales(); closeModal() },
    onError: msg.fromErr,
  })

  function round2(n: number) {
    return Math.round(n * 100) / 100
  }

  const invLineSub = round2(
    inv.lines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0),
  )
  const invDiscount = Math.max(0, Number(inv.discount_amount) || 0)
  const invTax = taxEnabled ? round2(invLineSub * defaultTaxRate / 100) : 0
  const invEstimatedTotal = round2(invLineSub - invDiscount + invTax)

  const saveRet = useMutation({
    mutationFn: () => api.post('/sales-returns', {
      return_date: ret.return_date,
      customer_id: Number(ret.customer_id),
      warehouse_id: Number(ret.warehouse_id) || undefined,
      sales_invoice_id: ret.sales_invoice_id ? Number(ret.sales_invoice_id) : null,
      currency: ret.currency,
      exchange_rate: ret.exchange_rate ? Number(ret.exchange_rate) : undefined,
      status: ret.status,
      lines: [{ product_id: Number(ret.product_id), quantity: Number(ret.quantity), unit_price: Number(ret.unit_price), serial_no: ret.serial_no || undefined }],
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

  const collectRemaining = useMutation({
    mutationFn: () => api.post(`/sales-invoices/${selectedId}/collect`, {
      receipt_date: collectForm.receipt_date,
      amount: Number(collectForm.amount),
      cash_box_id: collectForm.cash_box_id ? Number(collectForm.cash_box_id) : undefined,
      method: 'cash',
      status: 'posted',
    }),
    onSuccess: () => {
      msg.setMessage(t('sales.collectSaved'))
      invalidateSales()
      closeModal()
    },
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
    onSuccess: () => {
      msg.setMessage(t('sales.receiptSaved'))
      for (const key of ['receipts', 'sales-invoices', 'cash-boxes', 'customers'] as const) {
        void qc.invalidateQueries({ queryKey: [key] })
      }
      closeModal()
    },
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
      currency: d.currency || 'USD',
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
              void applyStockToForm(setState, productId, state.warehouse_id)
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
      {state.product_id && (
        <Field label={t('common.unit')}>
          <input className={`${inputClass} bg-black/5`} readOnly value={formatProductUnit((products.data || []).find((p) => String(p.id) === state.product_id)?.unit)} />
        </Field>
      )}
      <div className="form-grid-2">
        <Field label={t('common.quantity')} hint={t('common.quantityUnit')}>
          <NumericInput value={state.quantity} onChange={(v) => setState((prev) => ({ ...prev, quantity: v }))} />
          {autoFillStock && stockInfo !== null && state.product_id && state.warehouse_id && (
            <StockAvailabilityHint stockInfo={stockInfo} />
          )}
        </Field>
        <Field label={t('common.price')}><NumericInput value={state.unit_price} onChange={(v) => setState((prev) => ({ ...prev, unit_price: v }))} /></Field>
      </div>
      {(products.data || []).find((p) => String(p.id) === state.product_id)?.track_serial && (
        <Field label={t('common.serial')}><input className={inputClass} value={state.serial_no} onChange={(e) => setState({ ...state, serial_no: e.target.value })} required /></Field>
      )}
    </>
  )

  const invoiceLinesEditor = (
    <div className="space-y-3">
      <BarcodeScanInput
        label={t('sales.scanBarcode')}
        hint={t('sales.scanBarcodeHint')}
        onScan={(code) => void handleBarcodeScan(code, 'inv')}
      />
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs font-medium text-black/55">{t('common.lines')}</p>
        <Button type="button" variant="secondary" onClick={addInvLine}>{t('common.addLine')}</Button>
      </div>
      {inv.lines.map((line, index) => {
        const product = (products.data || []).find((p) => String(p.id) === line.product_id)
        return (
          <div key={index} className="rounded-lg border border-black/10 bg-black/[0.02] p-3 space-y-2">
            <div className="flex items-center justify-between gap-2">
              <span className="text-xs font-medium text-black/50">{t('common.lineN', { n: index + 1 })}</span>
              {inv.lines.length > 1 && (
                <button type="button" className="text-xs text-rose-600" onClick={() => removeInvLine(index)}>
                  {t('common.removeLine')}
                </button>
              )}
            </div>
            <Field label={t('common.product')}>
              <select
                className={inputClass}
                value={line.product_id}
                onChange={(e) => {
                  const productId = e.target.value
                  const selected = (products.data || []).find((p) => String(p.id) === productId)
                  updateInvLine(index, {
                    product_id: productId,
                    unit_price: selected ? String(selected.sale_price) : line.unit_price,
                    serial_no: selected?.track_serial ? line.serial_no : '',
                    batch_no: selected?.track_batch ? line.batch_no : '',
                  })
                  if (productId && inv.warehouse_id && !skipStockAutofill.current) {
                    void applyStockToInvoiceLine(index, productId, inv.warehouse_id)
                  }
                }}
                required
              >
                <option value="">—</option>
                {(products.data || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </Field>
            {line.product_id && (
              <Field label={t('common.unit')}>
                <input className={`${inputClass} bg-black/5`} readOnly value={formatProductUnit(product?.unit)} />
              </Field>
            )}
            <div className="form-grid-2">
              <Field label={t('common.quantity')} hint={t('common.quantityUnit')}>
                <NumericInput value={line.quantity} onChange={(v) => updateInvLine(index, { quantity: v })} />
                {line.product_id && inv.warehouse_id && (
                  <div className="mt-1 text-xs text-black/55">
                    <LineStockHint productId={Number(line.product_id)} warehouseId={Number(inv.warehouse_id)} />
                  </div>
                )}
              </Field>
              <Field label={t('common.price')}>
                <NumericInput value={line.unit_price} onChange={(v) => updateInvLine(index, { unit_price: v })} />
              </Field>
            </div>
            {product?.track_serial && (
              <Field label={t('common.serial')}>
                <input className={inputClass} value={line.serial_no} onChange={(e) => updateInvLine(index, { serial_no: e.target.value })} required />
              </Field>
            )}
          </div>
        )
      })}
    </div>
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
            required
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
    const branchName = (data.branch as { name?: string } | undefined)?.name
    const lines = ((data.items || data.lines) as {
      product?: { id?: number; name?: string; unit?: { name?: string; symbol?: string } }
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
          {tab === 'invoices' && (
            <p><b>{t('common.branch')}:</b> {branchName || '—'}</p>
          )}
          <p><b>{t('common.currency')}:</b> {String(data.currency || baseCurrency)}</p>
          {data.exchange_rate != null && String(data.currency || baseCurrency) !== baseCurrency && (
            <p><b>{t('common.exchangeRate')}:</b> {String(data.exchange_rate)}</p>
          )}
          <p><b>{t('common.total')}:</b> {String(data.total || data.amount || '—')} {String(data.currency || baseCurrency)}</p>
          {data.discount_amount != null && Number(data.discount_amount) > 0 && (
            <p><b>{t('common.discount')}:</b> {String(data.discount_amount)}</p>
          )}
          {data.payment_type ? (
            <p><b>{t('common.paymentType')}:</b> {paymentTypeLabel(String(data.payment_type), (k) => String(t(k)))}</p>
          ) : null}
          {data.paid_amount != null && data.invoice_number ? (
            <p><b>{t('common.paidAmount')}:</b> {String(data.paid_amount)} / {String(data.total)} — {String(t('common.remainingAmount'))}: {String(invoiceRemaining({ total: Number(data.total || 0), paid_amount: Number(data.paid_amount || 0) }))}</p>
          ) : null}
          {tab === 'invoices' && canCollectInvoice({
            status: String(data.status || ''),
            total: Number(data.total || 0),
            paid_amount: Number(data.paid_amount || 0),
          }) ? (
            <p className="sm:col-span-2 text-xs text-teal">{t('sales.collectHint')}</p>
          ) : null}
          {data.tax_amount != null && Number(data.tax_amount) > 0 && (
            <p><b>{t('common.tax')}:</b> {String(data.tax_amount)}</p>
          )}
          {data.notes ? (
            <p className="sm:col-span-2"><b>ملاحظات:</b> {String(data.notes)}</p>
          ) : null}
        </div>
        {tab === 'invoices' && selectedId && (
          <AttachmentPanel attachableType="sales_invoice" attachableId={selectedId} />
        )}
        {tab === 'receipts' && selectedId && (
          <AttachmentPanel attachableType="receipt" attachableId={selectedId} />
        )}
        {lines.length > 0 && (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('common.product')}</th>
                  <th>{t('common.unit')}</th>
                  <th title={t('common.quantityUnit')}>{t('common.quantity')}</th>
                  {lines.some((l) => l.serial_no) && <th>{t('common.serial')}</th>}
                  <th>{t('sales.stockLocation')}</th>
                  <th>{t('common.total')}</th>
                </tr>
              </thead>
              <tbody>
                {lines.map((line, index) => (
                  <tr key={index}>
                    <td>{line.product?.name}</td>
                    <td>{unitFromProduct(line.product)}</td>
                    <td className="tabular-nums">{formatQuantity(line.quantity)}</td>
                    {lines.some((l) => l.serial_no) && (
                      <td className="font-mono text-xs">{line.serial_no || '—'}</td>
                    )}
                    <td>
                      <LineStockHint
                        productId={line.product?.id || line.product_id}
                        warehouseId={data.warehouse_id as number | undefined}
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
      <PageHeader
        title={t('sales.title')}
        subtitle={t('sales.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ListSearchInput value={search.q} onChange={search.setQ} />
            <ExcelExportButton path={`/exports/${excelModuleForSalesTab(tab)}`} />
            {tab === 'invoices' && (
              <PdfExportButton
                label={t('common.exportPdfAll')}
                fileName="sales-invoices"
                printPaths={(invoices.data || []).map((i: { id: number }) => `/print/sales-invoices/${i.id}`)}
                disabled={!(invoices.data || []).length}
              />
            )}
            <Button variant="primary" onClick={openCreate}>{t('common.add')}</Button>
          </div>
        }
      />
      <Tabs tabs={tabs} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {search.debouncedQ && !(
        (tab === 'quotes' && (quotes.data || []).length) ||
        (tab === 'orders' && (orders.data || []).length) ||
        (tab === 'invoices' && (invoices.data || []).length) ||
        (tab === 'returns' && (returns.data || []).length) ||
        (tab === 'receipts' && (receipts.data || []).length)
      ) && !quotes.isLoading && !orders.isLoading && !invoices.isLoading && !returns.isLoading && !receipts.isLoading ? (
        <EmptyState title={t('common.noSearchResults')} />
      ) : null}
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
                    <td>{q.currency || 'USD'}</td>
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
                    <td>{o.currency || 'USD'}</td>
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
              <thead><tr><th>{t('common.number')}</th><th>{t('common.customer')}</th><th>{t('common.branch')}</th><th>ملاحظات</th><th>{t('common.paymentType')}</th><th>{t('common.currency')}</th><th>{t('common.discount')}</th><th>{t('common.total')}</th><th>{t('common.paidAmount')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(invoices.data || []).map((i: { id: number; invoice_number: string; total: number; paid_amount?: number; discount_amount?: number; payment_type?: string; status: string; currency?: string; notes?: string | null; attachments_count?: number; customer?: { name: string; phone?: string }; branch?: { name?: string } | null }) => (
                  <tr key={i.id} className="cursor-pointer" onClick={() => openRow(i)}>
                    <td className="font-mono text-xs">
                      <span className="inline-flex items-center gap-1">
                        {i.invoice_number}
                        <AttachmentIcon count={i.attachments_count} />
                      </span>
                    </td>
                    <td>{i.customer?.name}</td>
                    <td>{i.branch?.name || '—'}</td>
                    <td className="max-w-[10rem] truncate text-black/70" title={i.notes || undefined}>{i.notes || '—'}</td>
                    <td>{paymentTypeLabel(i.payment_type, t)}</td>
                    <td>{i.currency || 'USD'}</td>
                    <td className="tabular-nums">{Number(i.discount_amount || 0) > 0 ? i.discount_amount : '—'}</td>
                    <td className="tabular-nums">{i.total}</td>
                    <td className="tabular-nums">{i.paid_amount ?? 0}</td>
                    <td>{documentStatusLabel(i.status)}</td>
                    <td className="space-x-2 space-x-reverse">
                      <button type="button" className="text-xs text-teal print-hide" onClick={(e) => { e.stopPropagation(); printInvoice(i.id) }}>{t('common.print')}</button>
                      <span className="print-hide inline-block">
                        <PdfExportButton
                          compact
                          printPath={`/print/sales-invoices/${i.id}`}
                          fileName={i.invoice_number}
                        />
                      </span>
                      <span className="print-hide inline-block">
                        <WhatsAppSendButton
                          compact
                          defaultPhone={i.customer?.phone}
                          printPath={`/print/sales-invoices/${i.id}`}
                          fileName={i.invoice_number}
                          documentLabel={`فاتورة مبيعات ${i.invoice_number}`}
                        />
                      </span>
                      {canCollectInvoice(i) && (
                        <button
                          type="button"
                          className="text-xs text-teal"
                          onClick={(e) => { e.stopPropagation(); openCollect(i) }}
                        >
                          {t('sales.collectRemaining')}
                        </button>
                      )}
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
                    <td>{r.currency || 'USD'}</td>
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
                    <td>{r.currency || 'USD'}</td>
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
      <Modal
        open={modal !== null}
        onClose={closeModal}
        title={
          modal === 'create' ? t('common.add')
            : modal === 'edit' ? t('common.edit')
              : modal === 'collect' ? t('sales.collectRemaining')
                : t('common.view')
        }
        size={tab === 'invoices' && (modal === 'view' || modal === 'create') ? 'xl' : 'md'}
        footer={
          modal === 'collect' ? (
            <>
              <Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button>
              <Button variant="primary" type="submit" form="sales-collect-form" disabled={collectRemaining.isPending}>
                {t('sales.collectAndClose')}
              </Button>
            </>
          ) : modal !== 'view' ? (
            <><Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button><Button variant="primary" type="submit" form="sales-form">{t('common.save')}</Button></>
          ) : (
            <>
              {tab === 'invoices' && selectedId && (
                <>
                  {canCollectInvoice({
                    status: String((detail.data as { status?: string } | undefined)?.status || selectedRow?.status || ''),
                    total: Number((detail.data as { total?: number } | undefined)?.total ?? selectedRow?.total ?? 0),
                    paid_amount: Number((detail.data as { paid_amount?: number } | undefined)?.paid_amount ?? selectedRow?.paid_amount ?? 0),
                  }) && (
                    <Button
                      variant="primary"
                      onClick={() => {
                        const src = (detail.data || selectedRow || {}) as Record<string, unknown>
                        openCollect({
                          id: selectedId,
                          total: Number(src.total ?? 0),
                          paid_amount: Number(src.paid_amount ?? 0),
                          status: String(src.status || ''),
                          invoice_number: src.invoice_number as string | undefined,
                          currency: src.currency as string | undefined,
                          customer: src.customer as { name?: string; phone?: string } | undefined,
                          cash_box_id: src.cash_box_id as number | null | undefined,
                        })
                      }}
                    >
                      {t('sales.collectRemaining')}
                    </Button>
                  )}
                  <Button variant="secondary" onClick={() => printInvoice(selectedId)}><Printer size={16} /> {t('common.print')}</Button>
                  <PdfExportButton
                    printPath={`/print/sales-invoices/${selectedId}`}
                    fileName={String((detail.data as { invoice_number?: string } | undefined)?.invoice_number
                      || (selectedRow as { invoice_number?: string } | null)?.invoice_number
                      || `sales-${selectedId}`)}
                  />
                  <WhatsAppSendButton
                    defaultPhone={(detail.data as { customer?: { phone?: string } } | undefined)?.customer?.phone
                      || (selectedRow as { customer?: { phone?: string } } | null)?.customer?.phone}
                    printPath={`/print/sales-invoices/${selectedId}`}
                    fileName={String((detail.data as { invoice_number?: string } | undefined)?.invoice_number
                      || (selectedRow as { invoice_number?: string } | null)?.invoice_number
                      || `sales-${selectedId}`)}
                    documentLabel={`فاتورة مبيعات ${String((detail.data as { invoice_number?: string } | undefined)?.invoice_number || selectedId)}`}
                  />
                </>
              )}
              {selectedId && selectedRow && canDeleteSales(tab, String(selectedRow.status || '')) && (
                <Button variant="danger" disabled={deleteDoc.isPending} onClick={() => askDelete(tab, selectedId, String(selectedRow.status || ''))}>{t('common.delete')}</Button>
              )}
              <Button variant="secondary" onClick={closeModal}>{t('common.close')}</Button>
            </>
          )
        }
      >
        {modal === 'collect' ? (
          <form
            id="sales-collect-form"
            className="space-y-3"
            onSubmit={(e) => { e.preventDefault(); collectRemaining.mutate() }}
          >
            <p className="text-sm text-black/70">
              {(selectedRow as { invoice_number?: string } | null)?.invoice_number
                ? `${t('common.invoice')}: ${(selectedRow as { invoice_number?: string }).invoice_number}`
                : null}
              {(selectedRow as { customer?: { name?: string } } | null)?.customer?.name
                ? ` — ${(selectedRow as { customer?: { name?: string } }).customer?.name}`
                : null}
            </p>
            <p className="text-xs leading-relaxed text-black/55">{t('sales.collectHint')}</p>
            <Field label={t('common.remainingAmount')}>
              <input
                className={inputClass}
                readOnly
                value={String(invoiceRemaining({
                  total: Number(selectedRow?.total || 0),
                  paid_amount: Number(selectedRow?.paid_amount || 0),
                }))}
              />
            </Field>
            <Field label={t('common.date')}>
              <input
                type="date"
                className={inputClass}
                value={collectForm.receipt_date}
                onChange={(e) => setCollectForm({ ...collectForm, receipt_date: e.target.value })}
              />
            </Field>
            <Field label={t('common.amount')}>
              <NumericInput
                className={inputClass}
                value={collectForm.amount}
                onChange={(v) => setCollectForm({ ...collectForm, amount: v })}
              />
            </Field>
            <Field label={t('common.cashBox')}>
              <select
                className={inputClass}
                value={collectForm.cash_box_id}
                onChange={(e) => setCollectForm({ ...collectForm, cash_box_id: e.target.value })}
              >
                <option value="">—</option>
                {(cashBoxes.data || []).map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}{c.currency ? ` (${c.currency})` : ''}{c.is_default ? ` — ${t('common.mainCashBox')}` : ''}
                  </option>
                ))}
              </select>
            </Field>
          </form>
        ) : modal === 'view' ? (detail.isLoading ? <p>{t('common.loading')}</p> : summary(detail.data || selectedRow || {})) : (
          <form id="sales-form" className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (tab === 'quotes') modal === 'edit' && selectedId ? updateQuote.mutate(selectedId) : saveQuote.mutate(); else if (tab === 'orders') saveOrder.mutate(); else if (tab === 'invoices') saveInv.mutate(); else if (tab === 'returns') saveRet.mutate(); else saveRc.mutate() }}>
            {tab === 'quotes' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={quote.quote_date} onChange={(e) => setQuote({ ...quote, quote_date: e.target.value })} /></Field><Field label={t('common.validUntil')}><input type="date" className={inputClass} value={quote.valid_until} onChange={(e) => setQuote({ ...quote, valid_until: e.target.value })} /></Field>{customerField(quote, setQuote, true, onSalesWarehouseChange(setQuote))}<DocumentCurrencyFields state={quote} setState={setQuote} currencies={currencyList} baseCurrency={baseCurrency} />{productFields(quote, setQuote, (code) => void handleBarcodeScan(code, 'quote'), true)}</>}
            {tab === 'orders' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={order.order_date} onChange={(e) => setOrder({ ...order, order_date: e.target.value })} /></Field>{customerField(order, setOrder, true, onSalesWarehouseChange(setOrder))}<DocumentCurrencyFields state={order} setState={setOrder} currencies={currencyList} baseCurrency={baseCurrency} />{productFields(order, setOrder, (code) => void handleBarcodeScan(code, 'order'), true)}</>}
            {tab === 'invoices' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={inv.invoice_date} onChange={(e) => setInv({ ...inv, invoice_date: e.target.value })} /></Field>{customerField(inv, setInv, true, (warehouseId) => {
              const wh = (warehouses.data || []).find((w) => String(w.id) === warehouseId)
              if (wh?.branch_id) {
                setInv((prev) => ({ ...prev, branch_id: String(wh.branch_id) }))
              }
            })}<Field label={t('common.branch')}><select className={inputClass} value={inv.branch_id} onChange={(e) => setInv({ ...inv, branch_id: e.target.value })} required={(branches.data || []).length > 0}><option value="">—</option>{(branches.data || []).map((b) => <option key={b.id} value={b.id}>{b.name}{b.code ? ` (${b.code})` : ''}</option>)}</select></Field><DocumentCurrencyFields state={inv} setState={setInv} currencies={currencyList} baseCurrency={baseCurrency} showBasePreview documentTotal={invEstimatedTotal} />{invoiceLinesEditor}{invHasSerialProduct && <p className="text-xs text-amber">* {t('warehouse.trackSerial')}</p>}<Field label={t('common.discount')}><input type="number" min={0} step="0.01" className={inputClass} value={inv.discount_amount} onChange={(e) => setInv({ ...inv, discount_amount: e.target.value })} placeholder="0" /></Field><PaymentTypeFields state={inv} setState={setInv} cashBoxes={cashBoxes.data || []} estimatedTotal={invEstimatedTotal} partner="customer" /><Field label="ملاحظات"><textarea className={inputClass} rows={2} value={inv.notes} onChange={(e) => setInv({ ...inv, notes: e.target.value })} placeholder="ملاحظات اختيارية على الفاتورة" /></Field><PendingAttachmentField file={pendingAttachment} onChange={setPendingAttachment} /></>}
            {tab === 'returns' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={ret.return_date} onChange={(e) => setRet({ ...ret, return_date: e.target.value })} /></Field>{customerField(ret, setRet)}<Field label={t('common.invoice')}><select className={inputClass} value={ret.sales_invoice_id} onChange={(e) => { const id = e.target.value; setRet({ ...ret, sales_invoice_id: id }); applyInvoiceCurrency(id, setRet) }}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field><DocumentCurrencyFields state={ret} setState={setRet} currencies={currencyList} baseCurrency={baseCurrency} />{productFields(ret, setRet)}</>}
            {tab === 'receipts' && <><p className="text-xs leading-relaxed text-black/55">{t('common.receiptHint')}</p>{customerField(rc, setRc, false)}<Field label={t('common.invoice')}><select className={inputClass} value={rc.sales_invoice_id} onChange={(e) => { const id = e.target.value; setRc({ ...rc, sales_invoice_id: id }); applyInvoiceCurrency(id, setRc) }}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field><Field label={t('common.cashBox')}><select className={inputClass} value={rc.cash_box_id} onChange={(e) => setRc({ ...rc, cash_box_id: e.target.value })}><option value="">—</option>{(cashBoxes.data || []).map((c: { id: number; name: string; currency?: string; is_default?: boolean }) => <option key={c.id} value={c.id}>{c.name}{c.currency ? ` (${c.currency})` : ''}{c.is_default ? ` — ${t('common.mainCashBox')}` : ''}</option>)}</select></Field><PaymentCurrencyFields state={rc} setState={setRc} currencies={currencyList} baseCurrency={baseCurrency} /></>}
          </form>
        )}
      </Modal>
    </div>
  )
}

function StockAvailabilityHint({ stockInfo }: { stockInfo: StockInfo }) {
  const { t } = useTranslation()

  return (
    <div className="mt-1 space-y-1 text-xs text-black/55">
      <p>
        {t('sales.stockRemainingIn', {
          qty: formatQuantity(stockInfo.available_qty),
          warehouse: stockInfo.warehouse_name || t('common.warehouse'),
        })}
      </p>
      {stockInfo.breakdown.length === 0 && (
        <p className="text-amber">{t('sales.noStockInWarehouse')}</p>
      )}
    </div>
  )
}

function LineStockHint({
  productId,
  warehouseId,
}: {
  productId?: number
  warehouseId?: number
}) {
  const { t } = useTranslation()
  const [info, setInfo] = useState<StockInfo | null>(null)

  useEffect(() => {
    if (!productId || !warehouseId) {
      setInfo(null)
      return
    }
    let active = true
    void fetchStockInfo(String(productId), String(warehouseId)).then((data) => {
      if (active) setInfo(data)
    })
    return () => { active = false }
  }, [productId, warehouseId])

  if (!productId || !warehouseId) return <span className="text-black/40">—</span>
  if (!info) return <span className="text-black/40">{t('common.loading')}</span>

  if (info.breakdown.length === 0) {
    return <span className="text-danger">{t('sales.noStockInWarehouse')}</span>
  }

  return (
    <span className="text-xs text-black/60">
      {formatQuantity(info.available_qty)}
    </span>
  )
}

