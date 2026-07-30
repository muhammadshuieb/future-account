import { useEffect, useState, type Dispatch, type SetStateAction } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import api from '@/lib/api'
import { formatInvoiceDateTime, todayYmd } from '@/lib/dates'
import { openPrintPopup } from '@/lib/printPopup'
import { documentStatusLabel } from '@/lib/statusLabels'
import { PurchaseInvoicePrintView, type PurchaseInvoicePrintData } from '@/components/InvoicePrintView'
import { DocumentCurrencyFields, PaymentCurrencyFields, type CurrencyOption } from '@/components/CurrencyFields'
import PaymentTypeFields, { paymentTypeLabel } from '@/components/PaymentTypeFields'
import AttachmentPanel, { AttachmentIcon, PendingAttachmentField, uploadAttachment } from '@/components/AttachmentPanel'
import WhatsAppSendButton from '@/components/WhatsAppSendButton'
import ExcelExportButton from '@/components/ExcelExportButton'
import PdfExportButton from '@/components/PdfExportButton'
import { excelModuleForPurchasesTab } from '@/lib/excelExport'
import { Button, Field, ListSearchInput, Modal, Msg, NumericInput, PageHeader, Panel, Tabs, formatQuantity, inputClass, useFormMessage } from '@/components/ui'
import { useListSearch } from '@/lib/useListSearch'
import { formatProductUnit, unitFromProduct } from '@/lib/productUnit'

type ProductRow = { id: number; name: string; cost_price: number; track_batch?: boolean; track_serial?: boolean; unit?: { name?: string; symbol?: string } }

type InvoiceLineDraft = {
  product_id: string
  quantity: string
  unit_cost: string
  batch_no: string
  serial_no: string
}

function emptyInvoiceLine(): InvoiceLineDraft {
  return { product_id: '', quantity: '10', unit_cost: '', batch_no: '', serial_no: '' }
}

function purchaseLine(productId: string, qty: string, cost: string, batch: string, serial: string, taxRate: number) {
  return {
    product_id: Number(productId),
    quantity: Number(qty),
    unit_cost: cost ? Number(cost) : undefined,
    tax_rate: taxRate,
    batch_no: batch || undefined,
    serial_no: serial || undefined,
  }
}

export default function PurchasesPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState('invoices')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const search = useListSearch()
  const [modal, setModal] = useState<'create' | 'view' | 'edit' | 'pay' | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [selectedRow, setSelectedRow] = useState<Record<string, unknown> | null>(null)
  const [payForm, setPayForm] = useState({ amount: '', cash_box_id: '', payment_date: todayYmd() })

  const requests = useQuery({
    queryKey: ['purchase-requests', search.debouncedQ],
    queryFn: async () => (await api.get('/purchase-requests', { params: search.params })).data.data,
    enabled: tab === 'requests',
  })
  const orders = useQuery({
    queryKey: ['purchase-orders', search.debouncedQ],
    queryFn: async () => (await api.get('/purchase-orders', { params: search.params })).data.data,
    enabled: tab === 'orders',
  })
  const invoices = useQuery({
    queryKey: ['purchase-invoices', search.debouncedQ],
    queryFn: async () => (await api.get('/purchase-invoices', { params: search.params })).data.data,
  })
  const returns = useQuery({
    queryKey: ['purchase-returns', search.debouncedQ],
    queryFn: async () => (await api.get('/purchase-returns', { params: search.params })).data.data,
    enabled: tab === 'returns',
  })
  const payments = useQuery({
    queryKey: ['supplier-payments', search.debouncedQ],
    queryFn: async () => (await api.get('/supplier-payments', { params: search.params })).data.data,
    enabled: tab === 'payments',
  })
  const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: async () => (await api.get('/suppliers')).data.data })
  const products = useQuery({ queryKey: ['products'], queryFn: async () => (await api.get('/products')).data.data as ProductRow[] })
  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data.data })
  const settings = useQuery({ queryKey: ['settings'], queryFn: async () => (await api.get('/settings')).data.data as { key: string; value: string }[] })
  const defaultWarehouseId = settings.data?.find((s) => s.key === 'default_warehouse_id')?.value || ''
  const taxEnabled = !['0', 'false', 'no', 'off'].includes(String(settings.data?.find((s) => s.key === 'tax_enabled')?.value ?? '0').toLowerCase())
  const defaultTaxRate = taxEnabled ? Number(settings.data?.find((s) => s.key === 'tax_rate')?.value ?? 15) || 0 : 0
  const [applyPurchaseTax, setApplyPurchaseTax] = useState(false)
  const [purchaseTaxRate, setPurchaseTaxRate] = useState('')
  const [pendingAttachment, setPendingAttachment] = useState<File | null>(null)
  const cashBoxes = useQuery({
    queryKey: ['cash-boxes'],
    queryFn: async () => (await api.get('/cash-boxes')).data.data as { id: number; name: string; currency?: string; is_default?: boolean; code?: string }[],
    enabled: tab === 'payments' || tab === 'invoices',
  })
  const currencies = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api.get('/currencies')).data.data as { base_currency: string; currencies: CurrencyOption[] },
  })
  const currencyList = currencies.data?.currencies || []
  const baseCurrency = currencies.data?.base_currency || 'USD'

  const base = { supplier_id: '', warehouse_id: '', product_id: '', quantity: '10', unit_cost: '', batch_no: '', serial_no: '', currency: 'USD', exchange_rate: '1' }

  const [req, setReq] = useState({ request_date: todayYmd(), required_date: '', ...base })
  const [po, setPo] = useState({ order_date: todayYmd(), ...base })
  const [inv, setInv] = useState({
    invoice_date: todayYmd(),
    status: 'posted',
    payment_type: 'credit',
    paid_amount: '',
    cash_box_id: '',
    notes: '',
    customs_amount: '',
    transport_fees: '',
    fines_amount: '',
    other_fees: '',
    supplier_id: '',
    warehouse_id: '',
    currency: 'USD',
    exchange_rate: '1',
    lines: [emptyInvoiceLine()] as InvoiceLineDraft[],
  })
  const [ret, setRet] = useState({
    return_date: todayYmd(),
    supplier_id: '',
    warehouse_id: '',
    purchase_invoice_id: '',
    product_id: '',
    quantity: '1',
    unit_cost: '',
    batch_no: '',
    serial_no: '',
    currency: 'USD',
    exchange_rate: '1',
    status: 'posted',
  })
  const [pay, setPay] = useState({
    payment_date: todayYmd(),
    supplier_id: '',
    purchase_invoice_id: '',
    cash_box_id: '',
    amount: '',
    base_amount: '',
    currency: 'USD',
    exchange_rate: '1',
    method: 'cash',
    status: 'posted',
  })

  const invalidate = () => {
    for (const key of ['purchase-requests', 'purchase-orders', 'purchase-invoices', 'purchase-returns', 'supplier-payments', 'stock-levels', 'cash-boxes', 'suppliers'] as const) {
      void qc.invalidateQueries({ queryKey: [key] })
    }
  }
  const closeModal = () => {
    setModal(null)
    setSelectedId(null)
    setSelectedRow(null)
    setPendingAttachment(null)
    setPayForm({ amount: '', cash_box_id: '', payment_date: todayYmd() })
  }

  const invoiceRemaining = (invRow: { total?: number; paid_amount?: number }) =>
    Math.max(0, Math.round((Number(invRow.total || 0) - Number(invRow.paid_amount || 0)) * 100) / 100)

  const canPayInvoice = (invRow: { status?: string; total?: number; paid_amount?: number }) =>
    invRow.status === 'posted' && invoiceRemaining(invRow) > 0

  const openPayRemaining = (invRow: {
    id: number
    total?: number
    paid_amount?: number
    status?: string
    invoice_number?: string
    cash_box_id?: number | null
    supplier?: { name?: string; phone?: string }
  }) => {
    if (!canPayInvoice(invRow)) return
    const boxes = cashBoxes.data || []
    const main = boxes.find((c) => c.is_default) || boxes.find((c) => c.code === 'CASH-01') || boxes[0]
    const defaultBox = invRow.cash_box_id ? String(invRow.cash_box_id) : (main ? String(main.id) : '')
    setSelectedId(invRow.id)
    setSelectedRow(invRow as unknown as Record<string, unknown>)
    setPayForm({
      amount: String(invoiceRemaining(invRow)),
      cash_box_id: defaultBox,
      payment_date: todayYmd(),
    })
    setModal('pay')
  }

  useEffect(() => {
    if (taxEnabled && !purchaseTaxRate) setPurchaseTaxRate(String(defaultTaxRate))
  }, [taxEnabled, defaultTaxRate, purchaseTaxRate])

  useEffect(() => {
    if (!pay.cash_box_id && (cashBoxes.data || []).length > 0) {
      const boxes = cashBoxes.data || []
      const main = boxes.find((c) => c.is_default) || boxes.find((c) => c.code === 'CASH-01') || boxes[0]
      if (main) setPay((prev) => prev.cash_box_id ? prev : { ...prev, cash_box_id: String(main.id) })
    }
  }, [cashBoxes.data, pay.cash_box_id])

  const effectiveTaxRate = taxEnabled && applyPurchaseTax ? (Number(purchaseTaxRate) || defaultTaxRate) : 0

  function round2(n: number) {
    return Math.round(n * 100) / 100
  }

  const invoiceExtrasSum = round2(
    (Number(inv.customs_amount) || 0)
    + (Number(inv.transport_fees) || 0)
    + (Number(inv.fines_amount) || 0)
    + (Number(inv.other_fees) || 0),
  )
  const invoiceLineSub = round2(
    inv.lines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0), 0),
  )
  const invoiceEstTotal = round2(invoiceLineSub * (1 + effectiveTaxRate / 100) + invoiceExtrasSum)

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
    setInv({
      invoice_date: todayYmd(),
      status: 'posted',
      payment_type: 'credit',
      paid_amount: '',
      cash_box_id: '',
      notes: '',
      customs_amount: '',
      transport_fees: '',
      fines_amount: '',
      other_fees: '',
      supplier_id: '',
      warehouse_id: defaultWarehouseId || '',
      currency: 'USD',
      exchange_rate: '1',
      lines: [emptyInvoiceLine()],
    })
    if (defaultWarehouseId) {
      setReq((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
      setPo((prev) => (prev.warehouse_id ? prev : { ...prev, warehouse_id: defaultWarehouseId }))
    }
    setModal('create')
  }
  const openRow = (row: Record<string, unknown> & { id: number }, editable = false) => { setSelectedId(row.id); setSelectedRow(row); setModal(editable ? 'edit' : 'view') }
  const printInvoice = (id: number) => openPrintPopup(`/print/purchase-invoices/${id}`)

  const saveReq = useMutation({
    mutationFn: () => api.post('/purchase-requests', {
      request_date: req.request_date,
      required_date: req.required_date || undefined,
      supplier_id: Number(req.supplier_id) || undefined,
      warehouse_id: Number(req.warehouse_id) || undefined,
      currency: req.currency,
      exchange_rate: req.exchange_rate ? Number(req.exchange_rate) : undefined,
      lines: [purchaseLine(req.product_id, req.quantity, req.unit_cost, req.batch_no, req.serial_no, defaultTaxRate)],
    }),
    onSuccess: () => { msg.setMessage(t('purchases.requestSaved')); invalidate(); closeModal() },
    onError: msg.fromErr,
  })

  const savePo = useMutation({
    mutationFn: () => api.post('/purchase-orders', {
      order_date: po.order_date,
      supplier_id: Number(po.supplier_id),
      warehouse_id: Number(po.warehouse_id) || undefined,
      currency: po.currency,
      exchange_rate: po.exchange_rate ? Number(po.exchange_rate) : undefined,
      lines: [purchaseLine(po.product_id, po.quantity, po.unit_cost, po.batch_no, po.serial_no, defaultTaxRate)],
    }),
    onSuccess: () => { msg.setMessage(t('purchases.orderSaved')); invalidate(); closeModal() },
    onError: msg.fromErr,
  })

  const saveInv = useMutation({
    mutationFn: async () => {
      const filledLines = inv.lines.filter((l) => l.product_id)
      if (filledLines.length === 0) {
        throw { response: { data: { message: t('common.linesRequired') } } }
      }
      const res = await api.post('/purchase-invoices', {
        invoice_date: inv.invoice_date,
        supplier_id: Number(inv.supplier_id),
        warehouse_id: Number(inv.warehouse_id),
        cash_box_id: inv.cash_box_id ? Number(inv.cash_box_id) : null,
        currency: inv.currency,
        exchange_rate: inv.exchange_rate ? Number(inv.exchange_rate) : undefined,
        payment_type: inv.payment_type || 'credit',
        paid_amount: inv.payment_type === 'partial' ? Number(inv.paid_amount) : undefined,
        status: inv.status,
        notes: inv.notes || null,
        customs_amount: inv.customs_amount ? Number(inv.customs_amount) : 0,
        transport_fees: inv.transport_fees ? Number(inv.transport_fees) : 0,
        fines_amount: inv.fines_amount ? Number(inv.fines_amount) : 0,
        other_fees: inv.other_fees ? Number(inv.other_fees) : 0,
        lines: filledLines.map((l) => purchaseLine(l.product_id, l.quantity, l.unit_cost, l.batch_no, l.serial_no, effectiveTaxRate)),
      })
      const id = res.data?.data?.id as number | undefined
      if (id && pendingAttachment) await uploadAttachment('purchase_invoice', id, pendingAttachment)
      return res
    },
    onSuccess: () => { msg.setMessage(t('purchases.invoicePosted')); setPendingAttachment(null); invalidate(); closeModal() },
    onError: msg.fromErr,
  })

  const saveRet = useMutation({
    mutationFn: () => api.post('/purchase-returns', {
      return_date: ret.return_date,
      supplier_id: Number(ret.supplier_id),
      warehouse_id: Number(ret.warehouse_id) || undefined,
      purchase_invoice_id: ret.purchase_invoice_id ? Number(ret.purchase_invoice_id) : null,
      currency: ret.currency,
      exchange_rate: ret.exchange_rate ? Number(ret.exchange_rate) : undefined,
      status: ret.status,
      lines: [{ product_id: Number(ret.product_id), quantity: Number(ret.quantity), unit_cost: Number(ret.unit_cost), batch_no: ret.batch_no || undefined, serial_no: ret.serial_no || undefined }],
    }),
    onSuccess: () => { msg.setMessage(t('purchases.returnPosted')); invalidate(); closeModal() },
    onError: msg.fromErr,
  })

  const convertReq = useMutation({
    mutationFn: (id: number) => api.post(`/purchase-requests/${id}/convert-to-order`),
    onSuccess: () => { msg.setMessage(t('purchases.convertedToOrder')); invalidate() },
    onError: msg.fromErr,
  })

  const updateReq = useMutation({
    mutationFn: (id: number) => api.put(`/purchase-requests/${id}`, {
      request_date: req.request_date,
      required_date: req.required_date || undefined,
      supplier_id: Number(req.supplier_id) || undefined,
      warehouse_id: Number(req.warehouse_id) || undefined,
      currency: req.currency,
      exchange_rate: req.exchange_rate ? Number(req.exchange_rate) : undefined,
      lines: [purchaseLine(req.product_id, req.quantity, req.unit_cost, req.batch_no, req.serial_no, defaultTaxRate)],
    }),
    onSuccess: () => { msg.setMessage(t('purchases.requestUpdated')); invalidate(); closeModal() },
    onError: msg.fromErr,
  })

  const convertPo = useMutation({
    mutationFn: (id: number) => api.post(`/purchase-orders/${id}/convert-to-invoice`, { status: 'posted' }),
    onSuccess: () => { msg.setMessage(t('purchases.convertedToInvoice')); invalidate() },
    onError: msg.fromErr,
  })

  const payRemaining = useMutation({
    mutationFn: () => api.post(`/purchase-invoices/${selectedId}/pay-remaining`, {
      payment_date: payForm.payment_date,
      amount: Number(payForm.amount),
      cash_box_id: payForm.cash_box_id ? Number(payForm.cash_box_id) : undefined,
      method: 'cash',
    }),
    onSuccess: () => {
      msg.setMessage(t('purchases.payRemainingSaved'))
      invalidate()
      closeModal()
    },
    onError: msg.fromErr,
  })

  const savePay = useMutation({
    mutationFn: () => api.post('/supplier-payments', {
      payment_date: pay.payment_date,
      supplier_id: Number(pay.supplier_id),
      purchase_invoice_id: pay.purchase_invoice_id ? Number(pay.purchase_invoice_id) : null,
      cash_box_id: pay.cash_box_id ? Number(pay.cash_box_id) : null,
      method: pay.method,
      status: pay.status,
      amount: Number(pay.amount),
      currency: pay.currency,
      exchange_rate: pay.exchange_rate ? Number(pay.exchange_rate) : undefined,
      base_amount: pay.base_amount ? Number(pay.base_amount) : undefined,
    }),
    onSuccess: () => {
      msg.setMessage(t('purchases.paymentPosted'))
      for (const key of ['supplier-payments', 'purchase-invoices', 'cash-boxes', 'suppliers'] as const) {
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

  const productFields = <T extends { product_id: string; quantity: string; unit_cost: string; batch_no: string; serial_no: string }>(
    state: T,
    setState: Dispatch<SetStateAction<T>>,
  ) => (
    <>
      <Field label={t('common.product')}><select className={inputClass} value={state.product_id} onChange={(e) => setState({ ...state, product_id: e.target.value })} required><option value="">—</option>{(products.data || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
      {state.product_id && (
        <Field label={t('common.unit')}>
          <input className={`${inputClass} bg-black/5`} readOnly value={formatProductUnit((products.data || []).find((p) => String(p.id) === state.product_id)?.unit)} />
        </Field>
      )}
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

  const invoiceLinesEditor = (
    <div className="space-y-3">
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
                    unit_cost: selected ? String(selected.cost_price) : line.unit_cost,
                    serial_no: selected?.track_serial ? line.serial_no : '',
                    batch_no: selected?.track_batch ? line.batch_no : '',
                  })
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
            <div className="grid grid-cols-2 gap-2">
              <Field label={t('common.quantity')} hint={t('common.quantityUnit')}>
                <NumericInput value={line.quantity} onChange={(v) => updateInvLine(index, { quantity: v })} />
              </Field>
              <Field label={t('common.cost')}>
                <NumericInput value={line.unit_cost} onChange={(v) => updateInvLine(index, { unit_cost: v })} />
              </Field>
            </div>
            {product?.track_batch && (
              <Field label={t('common.batch')}>
                <input className={inputClass} value={line.batch_no} onChange={(e) => updateInvLine(index, { batch_no: e.target.value })} required />
              </Field>
            )}
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
      currency: data.currency || 'USD',
      exchange_rate: String(data.exchange_rate || ''),
    })
  }, [detail.data, modal])

  const supplierFields = <T extends { supplier_id: string; warehouse_id?: string }>(state: T, setState: Dispatch<SetStateAction<T>>, warehouse = true) => (
    <>
      <Field label={t('common.supplier')}><select className={inputClass} value={state.supplier_id} onChange={(e) => setState({ ...state, supplier_id: e.target.value })} required><option value="">—</option>{(suppliers.data || []).map((s: { id: number; name: string }) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
      {warehouse && <Field label={t('common.warehouse')}><select className={inputClass} value={state.warehouse_id} onChange={(e) => setState({ ...state, warehouse_id: e.target.value })} required><option value="">—</option>{(warehouses.data || []).map((w: { id: number; name: string }) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></Field>}
    </>
  )

  const summary = (data: Record<string, any>) => {
    if (tab === 'invoices' && data.invoice_number) {
      return (
        <div className="space-y-3">
          <div className="grid gap-2 text-sm sm:grid-cols-2">
            {data.payment_type && (
              <p><b>{t('common.paymentType')}:</b> {paymentTypeLabel(String(data.payment_type), t)}</p>
            )}
            {data.paid_amount != null && (
              <p><b>{t('common.paidAmount')}:</b> {String(data.paid_amount)} / {String(data.total)} — {t('common.remainingAmount')}: {String(Number(data.total || 0) - Number(data.paid_amount || 0))}</p>
            )}
            {data.tax_amount != null && Number(data.tax_amount) > 0 && (
              <p><b>{t('common.tax')}:</b> {String(data.tax_amount)}</p>
            )}
            {Number(data.customs_amount) > 0 && (
              <p><b>{t('purchases.customs')}:</b> {String(data.customs_amount)}</p>
            )}
            {Number(data.transport_fees) > 0 && (
              <p><b>{t('purchases.transportFees')}:</b> {String(data.transport_fees)}</p>
            )}
            {Number(data.fines_amount) > 0 && (
              <p><b>{t('purchases.fines')}:</b> {String(data.fines_amount)}</p>
            )}
            {Number(data.other_fees) > 0 && (
              <p><b>{t('purchases.otherFees')}:</b> {String(data.other_fees)}</p>
            )}
          </div>
          <div className="rounded-lg border border-black/10 bg-white p-4">
            <PurchaseInvoicePrintView invoice={data as PurchaseInvoicePrintData} />
          </div>
          {selectedId && <AttachmentPanel attachableType="purchase_invoice" attachableId={selectedId} />}
        </div>
      )
    }
    return (
      <div className="space-y-3 text-sm">
        <div className="grid gap-3 sm:grid-cols-2">
          <p><b>{t('common.number')}:</b> {data.request_number || data.order_number || data.invoice_number || data.return_number || data.payment_number || '—'}</p>
          <p><b>{t('common.status')}:</b> {documentStatusLabel(data.status)}</p>
          <p><b>{t('common.supplier')}:</b> {data.supplier?.name || '—'}</p>
          <p><b>{t('common.currency')}:</b> {data.currency || baseCurrency}</p>
          {data.exchange_rate != null && (data.currency || baseCurrency) !== baseCurrency && (
            <p><b>{t('common.exchangeRate')}:</b> {data.exchange_rate}</p>
          )}
          <p><b>{t('common.total')}:</b> {data.total || data.amount || '—'} {data.currency || baseCurrency}</p>
        </div>
        {tab === 'payments' && selectedId && (
          <AttachmentPanel attachableType="supplier_payment" attachableId={selectedId} />
        )}
        {(data.items || data.lines)?.length > 0 && (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('common.product')}</th>
                  <th>{t('common.unit')}</th>
                  <th title={t('common.quantityUnit')}>{t('common.quantity')}</th>
                  <th>{t('common.total')}</th>
                </tr>
              </thead>
              <tbody>
                {(data.items || data.lines).map((line: any, index: number) => (
                  <tr key={index}>
                    <td>{line.product?.name}</td>
                    <td>{unitFromProduct(line.product)}</td>
                    <td className="tabular-nums">{formatQuantity(line.quantity)}</td>
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

  const tabs = [
    { id: 'requests', label: t('purchases.requests') },
    { id: 'orders', label: t('purchases.orders') },
    { id: 'invoices', label: t('purchases.invoices') },
    { id: 'returns', label: t('purchases.returns') },
    { id: 'payments', label: t('purchases.payments') },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('purchases.title')}
        subtitle={t('purchases.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ListSearchInput value={search.q} onChange={search.setQ} />
            <ExcelExportButton path={`/exports/${excelModuleForPurchasesTab(tab)}`} />
            {tab === 'invoices' && (
              <PdfExportButton
                label={t('common.exportPdfAll')}
                fileName="purchase-invoices"
                printPaths={(invoices.data || []).map((i: { id: number }) => `/print/purchase-invoices/${i.id}`)}
                disabled={!(invoices.data || []).length}
              />
            )}
            <Button variant="primary" onClick={openCreate}>{t('common.add')}</Button>
          </div>
        }
      />
      <Tabs tabs={tabs} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'requests' && (
        <Panel>
            <table className="data-table text-sm">
              <thead><tr><th>{t('common.number')}</th><th>{t('common.supplier')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(requests.data || []).map((r: { id: number; request_number: string; total: number; status: string; currency?: string; supplier?: { name: string } }) => (
                  <tr key={r.id} className="cursor-pointer" onClick={() => openRow(r, r.status !== 'converted')}>
                    <td className="font-mono text-xs">{r.request_number}</td>
                    <td>{r.supplier?.name || '—'}</td>
                    <td>{r.currency || 'USD'}</td>
                    <td>{r.total}</td>
                    <td>{documentStatusLabel(r.status)}</td>
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
              <thead><tr><th>{t('common.number')}</th><th>{t('common.supplier')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(orders.data || []).map((o: { id: number; order_number: string; total: number; status: string; currency?: string; supplier?: { name: string } }) => (
                  <tr key={o.id} className="cursor-pointer" onClick={() => openRow(o)}>
                    <td className="font-mono text-xs">{o.order_number}</td>
                    <td>{o.supplier?.name}</td>
                    <td>{o.currency || 'USD'}</td>
                    <td>{o.total}</td>
                    <td>{documentStatusLabel(o.status)}</td>
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
              <thead><tr><th>{t('common.number')}</th><th>{t('common.dateTime')}</th><th>{t('common.supplier')}</th><th>ملاحظات</th><th>{t('common.paymentType')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th>{taxEnabled && <th>{t('common.tax')}</th>}<th>{t('common.paidAmount')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(invoices.data || []).map((i: { id: number; invoice_number: string; invoice_date?: string; created_at?: string; total: number; tax_amount?: number; paid_amount?: number; payment_type?: string; status: string; currency?: string; notes?: string | null; attachments_count?: number; supplier?: { name: string; phone?: string } }) => (
                  <tr key={i.id} className="cursor-pointer" onClick={() => openRow(i)}>
                    <td className="font-mono text-xs">
                      <span className="inline-flex items-center gap-1">
                        {i.invoice_number}
                        <AttachmentIcon count={i.attachments_count} />
                      </span>
                    </td>
                    <td className="whitespace-nowrap text-xs tabular-nums">{formatInvoiceDateTime(i.invoice_date, i.created_at)}</td>
                    <td>{i.supplier?.name}</td>
                    <td className="max-w-[10rem] truncate text-black/70" title={i.notes || undefined}>{i.notes || '—'}</td>
                    <td>{paymentTypeLabel(i.payment_type, t)}</td>
                    <td>{i.currency || 'USD'}</td>
                    <td>{i.total}</td>
                    {taxEnabled && <td>{i.tax_amount ?? 0}</td>}
                    <td>{i.paid_amount ?? 0}</td>
                    <td>{documentStatusLabel(i.status)}</td>
                    <td className="space-x-2 space-x-reverse">
                      <button type="button" className="text-xs text-teal print-hide" onClick={(e) => { e.stopPropagation(); printInvoice(i.id) }}>{t('common.print')}</button>
                      <span className="print-hide inline-block">
                        <PdfExportButton
                          compact
                          printPath={`/print/purchase-invoices/${i.id}`}
                          fileName={i.invoice_number}
                        />
                      </span>
                      <span className="print-hide inline-block">
                        <WhatsAppSendButton
                          compact
                          defaultPhone={i.supplier?.phone}
                          printPath={`/print/purchase-invoices/${i.id}`}
                          fileName={i.invoice_number}
                          documentLabel={`فاتورة مشتريات ${i.invoice_number}`}
                        />
                      </span>
                      {canPayInvoice(i) && (
                        <button
                          type="button"
                          className="text-xs text-teal"
                          onClick={(e) => { e.stopPropagation(); openPayRemaining(i) }}
                        >
                          {t('purchases.payRemaining')}
                        </button>
                      )}
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
              <thead><tr><th>{t('common.number')}</th><th>{t('common.supplier')}</th><th>{t('common.currency')}</th><th>{t('common.total')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(returns.data || []).map((r: { id: number; return_number: string; total: number; status: string; currency?: string; supplier?: { name: string } }) => (
                  <tr key={r.id} className="cursor-pointer" onClick={() => openRow(r)}>
                    <td className="font-mono text-xs">{r.return_number}</td>
                    <td>{r.supplier?.name}</td>
                    <td>{r.currency || 'USD'}</td>
                    <td>{r.total}</td>
                    <td>{documentStatusLabel(r.status)}</td>
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
              <thead><tr><th>{t('common.number')}</th><th>{t('common.supplier')}</th><th>{t('common.currency')}</th><th>{t('common.amount')}</th><th>{t('common.status')}</th><th></th></tr></thead>
              <tbody>
                {(payments.data || []).map((p: { id: number; payment_number: string; amount: number; status: string; currency?: string; supplier?: { name: string } }) => (
                  <tr key={p.id} className="cursor-pointer" onClick={() => openRow(p)}>
                    <td className="font-mono text-xs">{p.payment_number}</td>
                    <td>{p.supplier?.name}</td>
                    <td>{p.currency || 'USD'}</td>
                    <td>{p.amount}</td>
                    <td>{documentStatusLabel(p.status)}</td>
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
      <Modal
        open={modal !== null}
        onClose={closeModal}
        title={
          modal === 'create' ? t('common.add')
            : modal === 'edit' ? t('common.edit')
              : modal === 'pay' ? t('purchases.payRemaining')
                : t('common.view')
        }
        size={tab === 'invoices' && (modal === 'view' || modal === 'create') ? 'xl' : 'md'}
        footer={
          modal === 'pay' ? (
            <>
              <Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button>
              <Button variant="primary" type="submit" form="purchase-pay-form" disabled={payRemaining.isPending}>
                {t('purchases.payAndClose')}
              </Button>
            </>
          ) : modal !== 'view' ? (
            <><Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button><Button variant="primary" type="submit" form="purchase-form">{t('common.save')}</Button></>
          ) : (
            <>
              {tab === 'invoices' && selectedId && (
                <>
                  {canPayInvoice({
                    status: String((detail.data as { status?: string } | undefined)?.status || selectedRow?.status || ''),
                    total: Number((detail.data as { total?: number } | undefined)?.total ?? selectedRow?.total ?? 0),
                    paid_amount: Number((detail.data as { paid_amount?: number } | undefined)?.paid_amount ?? selectedRow?.paid_amount ?? 0),
                  }) && (
                    <Button
                      variant="primary"
                      onClick={() => {
                        const src = (detail.data || selectedRow || {}) as Record<string, unknown>
                        openPayRemaining({
                          id: selectedId,
                          total: Number(src.total ?? 0),
                          paid_amount: Number(src.paid_amount ?? 0),
                          status: String(src.status || ''),
                          invoice_number: src.invoice_number as string | undefined,
                          cash_box_id: src.cash_box_id as number | null | undefined,
                          supplier: src.supplier as { name?: string; phone?: string } | undefined,
                        })
                      }}
                    >
                      {t('purchases.payRemaining')}
                    </Button>
                  )}
                  <Button variant="secondary" onClick={() => printInvoice(selectedId)}><Printer size={16} /> {t('common.print')}</Button>
                  <PdfExportButton
                    printPath={`/print/purchase-invoices/${selectedId}`}
                    fileName={String((detail.data as { invoice_number?: string } | undefined)?.invoice_number
                      || (selectedRow as { invoice_number?: string } | null)?.invoice_number
                      || `purchase-${selectedId}`)}
                  />
                  <WhatsAppSendButton
                    defaultPhone={(detail.data as { supplier?: { phone?: string } } | undefined)?.supplier?.phone
                      || (selectedRow as { supplier?: { phone?: string } } | null)?.supplier?.phone}
                    printPath={`/print/purchase-invoices/${selectedId}`}
                    fileName={String((detail.data as { invoice_number?: string } | undefined)?.invoice_number
                      || (selectedRow as { invoice_number?: string } | null)?.invoice_number
                      || `purchase-${selectedId}`)}
                    documentLabel={`فاتورة مشتريات ${String((detail.data as { invoice_number?: string } | undefined)?.invoice_number || selectedId)}`}
                  />
                </>
              )}
              {selectedId && selectedRow && canDeletePurchase(tab, String(selectedRow.status || '')) && (
                <Button variant="danger" disabled={deleteDoc.isPending} onClick={() => askDelete(tab, selectedId, String(selectedRow.status || ''))}>{t('common.delete')}</Button>
              )}
              <Button variant="secondary" onClick={closeModal}>{t('common.close')}</Button>
            </>
          )
        }
      >
        {modal === 'pay' ? (
          <form
            id="purchase-pay-form"
            className="space-y-3"
            onSubmit={(e) => { e.preventDefault(); payRemaining.mutate() }}
          >
            <p className="text-sm text-black/70">
              {(selectedRow as { invoice_number?: string } | null)?.invoice_number
                ? `${t('common.invoice')}: ${(selectedRow as { invoice_number?: string }).invoice_number}`
                : null}
              {(selectedRow as { supplier?: { name?: string } } | null)?.supplier?.name
                ? ` — ${(selectedRow as { supplier?: { name?: string } }).supplier?.name}`
                : null}
            </p>
            <p className="text-xs leading-relaxed text-black/55">{t('purchases.payRemainingHint')}</p>
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
                value={payForm.payment_date}
                onChange={(e) => setPayForm({ ...payForm, payment_date: e.target.value })}
              />
            </Field>
            <Field label={t('common.amount')}>
              <NumericInput
                className={inputClass}
                value={payForm.amount}
                onChange={(v) => setPayForm({ ...payForm, amount: v })}
              />
            </Field>
            <Field label={t('common.cashBox')}>
              <select
                className={inputClass}
                value={payForm.cash_box_id}
                onChange={(e) => setPayForm({ ...payForm, cash_box_id: e.target.value })}
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
        ) : modal === 'view' ? (detail.isLoading ? <p>{t('common.loading')}</p> : summary(detail.data || selectedRow || {})) : <form id="purchase-form" className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (tab === 'requests') modal === 'edit' && selectedId ? updateReq.mutate(selectedId) : saveReq.mutate(); else if (tab === 'orders') savePo.mutate(); else if (tab === 'invoices') saveInv.mutate(); else if (tab === 'returns') saveRet.mutate(); else savePay.mutate() }}>
          {tab === 'requests' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={req.request_date} onChange={(e) => setReq({ ...req, request_date: e.target.value })} /></Field>{supplierFields(req, setReq)}<DocumentCurrencyFields state={req} setState={setReq} currencies={currencyList} baseCurrency={baseCurrency} />{productFields(req, setReq)}</>}
          {tab === 'orders' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={po.order_date} onChange={(e) => setPo({ ...po, order_date: e.target.value })} /></Field>{supplierFields(po, setPo)}<DocumentCurrencyFields state={po} setState={setPo} currencies={currencyList} baseCurrency={baseCurrency} />{productFields(po, setPo)}</>}
          {tab === 'invoices' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={inv.invoice_date} onChange={(e) => setInv({ ...inv, invoice_date: e.target.value })} /></Field>{supplierFields(inv, setInv)}<DocumentCurrencyFields state={inv} setState={setInv} currencies={currencyList} baseCurrency={baseCurrency} showBasePreview documentTotal={invoiceEstTotal} />{invoiceLinesEditor}
            <div className="rounded-lg border border-dashed border-black/15 bg-black/[0.02] p-3 space-y-2">
              <p className="text-xs font-medium text-black/55">{t('purchases.costExtrasHint')}</p>
              <div className="grid grid-cols-2 gap-2">
                <Field label={t('purchases.customs')}><NumericInput value={inv.customs_amount} onChange={(v) => setInv((prev) => ({ ...prev, customs_amount: v }))} /></Field>
                <Field label={t('purchases.transportFees')}><NumericInput value={inv.transport_fees} onChange={(v) => setInv((prev) => ({ ...prev, transport_fees: v }))} /></Field>
                <Field label={t('purchases.fines')}><NumericInput value={inv.fines_amount} onChange={(v) => setInv((prev) => ({ ...prev, fines_amount: v }))} /></Field>
                <Field label={t('purchases.otherFees')}><NumericInput value={inv.other_fees} onChange={(v) => setInv((prev) => ({ ...prev, other_fees: v }))} /></Field>
              </div>
              {invoiceExtrasSum > 0 && (
                <p className="text-xs text-black/55">{t('purchases.extrasTotal')}: <span className="tabular-nums font-medium text-black/80">{invoiceExtrasSum}</span></p>
              )}
            </div>
            <PaymentTypeFields state={inv} setState={setInv} cashBoxes={cashBoxes.data || []} estimatedTotal={invoiceEstTotal} showTaxToggle={taxEnabled} applyTax={applyPurchaseTax} onApplyTaxChange={setApplyPurchaseTax} taxRate={purchaseTaxRate} onTaxRateChange={setPurchaseTaxRate} partner="supplier" /><Field label="ملاحظات"><textarea className={inputClass} rows={2} value={inv.notes} onChange={(e) => setInv({ ...inv, notes: e.target.value })} placeholder="ملاحظات اختيارية على الفاتورة" /></Field><PendingAttachmentField file={pendingAttachment} onChange={setPendingAttachment} /></>}
          {tab === 'returns' && <><Field label={t('common.date')}><input type="date" className={inputClass} value={ret.return_date} onChange={(e) => setRet({ ...ret, return_date: e.target.value })} /></Field>{supplierFields(ret, setRet)}<Field label={t('common.invoice')}><select className={inputClass} value={ret.purchase_invoice_id} onChange={(e) => { const id = e.target.value; setRet({ ...ret, purchase_invoice_id: id }); applyInvoiceCurrency(id, setRet) }}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field><DocumentCurrencyFields state={ret} setState={setRet} currencies={currencyList} baseCurrency={baseCurrency} />{productFields(ret, setRet)}</>}
          {tab === 'payments' && <><p className="text-xs leading-relaxed text-black/55">{t('common.supplierPaymentHint')}</p>{supplierFields(pay, setPay, false)}<Field label={t('common.invoice')}><select className={inputClass} value={pay.purchase_invoice_id} onChange={(e) => { const id = e.target.value; setPay({ ...pay, purchase_invoice_id: id }); applyInvoiceCurrency(id, setPay) }}><option value="">—</option>{(invoices.data || []).map((i: { id: number; invoice_number: string }) => <option key={i.id} value={i.id}>{i.invoice_number}</option>)}</select></Field><Field label={t('common.cashBox')}><select className={inputClass} value={pay.cash_box_id} onChange={(e) => setPay({ ...pay, cash_box_id: e.target.value })}><option value="">—</option>{(cashBoxes.data || []).map((c: { id: number; name: string; currency?: string; is_default?: boolean }) => <option key={c.id} value={c.id}>{c.name}{c.currency ? ` (${c.currency})` : ''}{c.is_default ? ` — ${t('common.mainCashBox')}` : ''}</option>)}</select></Field><PaymentCurrencyFields state={pay} setState={setPay} currencies={currencyList} baseCurrency={baseCurrency} /></>}
        </form>}
      </Modal>
    </div>
  )
}
