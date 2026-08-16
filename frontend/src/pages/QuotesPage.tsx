import { useCallback, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { FileText, Printer } from 'lucide-react'
import api from '@/lib/api'
import { todayYmd } from '@/lib/dates'
import { openPrintPopup } from '@/lib/printPopup'
import { documentStatusLabel } from '@/lib/statusLabels'
import { formatProductUnit } from '@/lib/productUnit'
import { productLabel } from '@/lib/productLabel'
import { useListSearch } from '@/lib/useListSearch'
import { useAuth } from '@/context/AuthContext'
import { DocumentCurrencyFields, type CurrencyOption } from '@/components/CurrencyFields'
import PdfExportButton from '@/components/PdfExportButton'
import WhatsAppSendButton from '@/components/WhatsAppSendButton'
import ProductVariantSelect from '@/components/ProductVariantSelect'
import {
  Button,
  EmptyState,
  Field,
  ListSearchInput,
  Modal,
  Msg,
  NumericInput,
  PageHeader,
  Panel,
  TableActions,
  formatMoney,
  formatQuantity,
  inputClass,
  useFormMessage,
} from '@/components/ui'

type ProductRow = {
  id: number
  name: string
  sku?: string
  brand?: string
  model?: string
  sale_price?: number
  track_serial?: boolean
  unit?: { name?: string; symbol?: string } | null
}

type QuoteLineDraft = {
  product_id: string
  quantity: string
  unit_price: string
}

type StockWarning = {
  product_id: number
  product_name?: string | null
  quantity: number
  available_qty: number
  code: string
  message: string
}

type QuoteRow = {
  id: number
  quote_number: string
  quote_date: string
  valid_until?: string | null
  status: string
  currency?: string
  total: number
  subtotal?: number
  tax_amount?: number
  notes?: string | null
  customer_id?: number | null
  warehouse_id?: number | null
  branch_id?: number | null
  customer?: { id: number; name: string; phone?: string } | null
  warehouse?: { id: number; name: string } | null
  branch?: { id: number; name: string } | null
  items?: {
    product_id: number
    quantity: number
    unit_price: number
    line_total: number
    product?: ProductRow
  }[]
  stock_warnings?: StockWarning[]
}

const emptyLine = (): QuoteLineDraft => ({ product_id: '', quantity: '1', unit_price: '' })

function round2(n: number) {
  return Math.round(n * 100) / 100
}

export default function QuotesPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const canManage = hasPermission('quotes.manage') || hasPermission('sales.manage')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const search = useListSearch()

  const [modal, setModal] = useState<'create' | 'edit' | 'view' | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [validityDays, setValidityDays] = useState('')
  const [form, setForm] = useState({
    quote_date: todayYmd(),
    valid_until: '',
    customer_id: '',
    warehouse_id: '',
    branch_id: '',
    currency: 'USD',
    exchange_rate: '1',
    notes: '',
    lines: [emptyLine()] as QuoteLineDraft[],
  })
  const [stockWarnings, setStockWarnings] = useState<StockWarning[]>([])

  const quotes = useQuery({
    queryKey: ['sales-quotes', search.debouncedQ],
    queryFn: async () => (await api.get('/sales-quotes', { params: search.params })).data.data as QuoteRow[],
  })
  const customers = useQuery({
    queryKey: ['customers'],
    queryFn: async () => (await api.get('/customers')).data.data as { id: number; name: string; phone?: string }[],
  })
  const products = useQuery({
    queryKey: ['products'],
    queryFn: async () => (await api.get('/products')).data.data as ProductRow[],
  })
  const warehouses = useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.get('/warehouses')).data.data as { id: number; name: string }[],
  })
  const branches = useQuery({
    queryKey: ['branches'],
    queryFn: async () => (await api.get('/branches')).data.data as { id: number; name: string; code?: string; is_main?: boolean; is_active?: boolean }[],
  })
  const activeBranches = (branches.data || []).filter((b) => b.is_active !== false)
  const settings = useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await api.get('/settings')).data.data as { key: string; value: string }[],
  })
  const currencies = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api.get('/currencies')).data.data as { base_currency: string; currencies: CurrencyOption[] },
  })
  const currencyList = currencies.data?.currencies || []
  const baseCurrency = currencies.data?.base_currency || 'USD'
  const taxEnabled = !['0', 'false', 'no', 'off'].includes(
    String(settings.data?.find((s) => s.key === 'tax_enabled')?.value ?? '0').toLowerCase(),
  )
  const defaultTaxRate = taxEnabled
    ? Number(settings.data?.find((s) => s.key === 'tax_rate')?.value ?? 15) || 0
    : 0
  const defaultBranchId =
    settings.data?.find((s) => s.key === 'default_branch_id')?.value
    || String(activeBranches.find((b) => b.is_main)?.id || activeBranches[0]?.id || '')

  const estimatedTotal = useMemo(() => {
    return round2(
      form.lines.reduce((sum, line) => {
        const qty = Number(line.quantity) || 0
        const price = Number(line.unit_price) || 0
        const base = qty * price
        const tax = taxEnabled ? base * (defaultTaxRate / 100) : 0
        return sum + base + tax
      }, 0),
    )
  }, [form.lines, taxEnabled, defaultTaxRate])

  const refreshWarnings = useCallback(async (next = form) => {
    const lines = next.lines
      .filter((l) => l.product_id && Number(l.quantity) > 0)
      .map((l) => {
        const product = (products.data || []).find((p) => String(p.id) === l.product_id)
        return {
          product_id: Number(l.product_id),
          quantity: Number(l.quantity),
          product_name: product ? productLabel(product) : undefined,
        }
      })
    if (lines.length === 0) {
      setStockWarnings([])
      return
    }
    try {
      const res = await api.post('/sales-quotes/stock-warnings', {
        warehouse_id: next.warehouse_id ? Number(next.warehouse_id) : undefined,
        lines,
      })
      setStockWarnings((res.data.data || []) as StockWarning[])
    } catch {
      setStockWarnings([])
    }
  }, [form, products.data])

  useEffect(() => {
    if (modal === 'create' || modal === 'edit') {
      const timer = window.setTimeout(() => {
        void refreshWarnings()
      }, 250)
      return () => window.clearTimeout(timer)
    }
    return undefined
  }, [modal, form.lines, form.warehouse_id, refreshWarnings])

  const resetForm = () => {
    setForm({
      quote_date: todayYmd(),
      valid_until: '',
      customer_id: '',
      warehouse_id: '',
      branch_id: defaultBranchId,
      currency: baseCurrency,
      exchange_rate: '1',
      notes: '',
      lines: [emptyLine()],
    })
    setValidityDays('')
    setStockWarnings([])
    setSelectedId(null)
  }

  const closeModal = () => {
    setModal(null)
    resetForm()
  }

  const openCreate = () => {
    resetForm()
    setForm((prev) => ({
      ...prev,
      branch_id: defaultBranchId,
      currency: baseCurrency,
      exchange_rate: '1',
    }))
    setModal('create')
  }

  const openEdit = async (id: number) => {
    const res = await api.get(`/sales-quotes/${id}`)
    const d = res.data.data as QuoteRow
    if (d.status === 'converted' || d.status === 'cancelled') {
      msg.setError(t('quotes.cannotEditFinal'))
      return
    }
    setSelectedId(id)
    setForm({
      quote_date: String(d.quote_date || '').slice(0, 10),
      valid_until: d.valid_until ? String(d.valid_until).slice(0, 10) : '',
      customer_id: d.customer_id ? String(d.customer_id) : '',
      warehouse_id: d.warehouse_id ? String(d.warehouse_id) : '',
      branch_id: d.branch_id ? String(d.branch_id) : '',
      currency: d.currency || baseCurrency,
      exchange_rate: String((d as { exchange_rate?: number }).exchange_rate ?? 1),
      notes: d.notes || '',
      lines: (d.items || []).map((item) => ({
        product_id: String(item.product_id),
        quantity: String(item.quantity),
        unit_price: String(item.unit_price),
      })) || [emptyLine()],
    })
    setValidityDays('')
    setStockWarnings(d.stock_warnings || [])
    setModal('edit')
  }

  const openView = async (id: number) => {
    const res = await api.get(`/sales-quotes/${id}`)
    const d = res.data.data as QuoteRow
    setSelectedId(id)
    setForm({
      quote_date: String(d.quote_date || '').slice(0, 10),
      valid_until: d.valid_until ? String(d.valid_until).slice(0, 10) : '',
      customer_id: d.customer_id ? String(d.customer_id) : '',
      warehouse_id: d.warehouse_id ? String(d.warehouse_id) : '',
      branch_id: d.branch_id ? String(d.branch_id) : '',
      currency: d.currency || baseCurrency,
      exchange_rate: String((d as { exchange_rate?: number }).exchange_rate ?? 1),
      notes: d.notes || '',
      lines: (d.items || []).map((item) => ({
        product_id: String(item.product_id),
        quantity: String(item.quantity),
        unit_price: String(item.unit_price),
      })) || [emptyLine()],
    })
    setStockWarnings(d.stock_warnings || [])
    setModal('view')
  }

  const payload = () => ({
    quote_date: form.quote_date,
    valid_until: form.valid_until || undefined,
    customer_id: form.customer_id ? Number(form.customer_id) : null,
    warehouse_id: form.warehouse_id ? Number(form.warehouse_id) : undefined,
    branch_id: form.branch_id ? Number(form.branch_id) : undefined,
    currency: form.currency,
    exchange_rate: form.exchange_rate ? Number(form.exchange_rate) : undefined,
    notes: form.notes || undefined,
    lines: form.lines
      .filter((l) => l.product_id)
      .map((l) => ({
        product_id: Number(l.product_id),
        quantity: Number(l.quantity),
        unit_price: Number(l.unit_price) || 0,
        tax_rate: defaultTaxRate,
      })),
  })

  const save = useMutation({
    mutationFn: async () => {
      const body = payload()
      if (body.lines.length === 0) throw new Error(t('common.linesRequired'))
      if (modal === 'edit' && selectedId) {
        return (await api.put(`/sales-quotes/${selectedId}`, body)).data.data as QuoteRow
      }
      return (await api.post('/sales-quotes', body)).data.data as QuoteRow
    },
    onSuccess: (row) => {
      msg.setMessage(modal === 'edit' ? t('quotes.updated') : t('quotes.saved'))
      void qc.invalidateQueries({ queryKey: ['sales-quotes'] })
      closeModal()
      if (row?.id) {
        // keep list fresh; no stock side effects
      }
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } }; message?: string }
      msg.setError(e.response?.data?.message || e.message || t('quotes.saveFailed'))
    },
  })

  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/sales-quotes/${id}`),
    onSuccess: () => {
      msg.setMessage(t('common.deleted'))
      void qc.invalidateQueries({ queryKey: ['sales-quotes'] })
      closeModal()
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      msg.setError(e.response?.data?.message || t('quotes.deleteFailed'))
    },
  })

  const applyValidityDays = (daysStr: string) => {
    setValidityDays(daysStr)
    const days = Number(daysStr)
    if (!Number.isFinite(days) || days <= 0 || !form.quote_date) {
      return
    }
    const base = new Date(`${form.quote_date}T12:00:00`)
    base.setDate(base.getDate() + days)
    const yyyy = base.getFullYear()
    const mm = String(base.getMonth() + 1).padStart(2, '0')
    const dd = String(base.getDate()).padStart(2, '0')
    setForm((prev) => ({ ...prev, valid_until: `${yyyy}-${mm}-${dd}` }))
  }

  const updateLine = (index: number, patch: Partial<QuoteLineDraft>) => {
    setForm((prev) => ({
      ...prev,
      lines: prev.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
    }))
  }

  const onProductChange = (index: number, productId: string) => {
    const product = (products.data || []).find((p) => String(p.id) === productId)
    updateLine(index, {
      product_id: productId,
      unit_price: product ? String(product.sale_price ?? '') : '',
    })
  }

  const addLine = () => setForm((prev) => ({ ...prev, lines: [...prev.lines, emptyLine()] }))
  const removeLine = (index: number) => {
    setForm((prev) => ({
      ...prev,
      lines: prev.lines.length <= 1 ? prev.lines : prev.lines.filter((_, i) => i !== index),
    }))
  }

  const selectedCustomerPhone = (customers.data || []).find((c) => String(c.id) === form.customer_id)?.phone
  const list = quotes.data || []
  const readOnly = modal === 'view'

  return (
    <div className="space-y-4">
      <PageHeader
        title={t('quotes.title')}
        subtitle={t('quotes.subtitle')}
        actions={
          canManage ? (
            <Button variant="primary" onClick={openCreate}>
              <FileText size={16} /> {t('quotes.new')}
            </Button>
          ) : undefined
        }
      />

      <Msg message={msg.message} error={msg.error} />

      <Panel>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <ListSearchInput value={search.q} onChange={search.setQ} placeholder={t('common.searchPlaceholder')} />
          {list.length > 0 && (
            <PdfExportButton
              fileName="price-quotes"
              printPaths={list.map((q) => `/print/sales-quotes/${q.id}`)}
              compact
            />
          )}
        </div>

        {quotes.isLoading ? (
          <p className="text-sm text-black/55">{t('common.loading')}</p>
        ) : list.length === 0 ? (
          <EmptyState title={t('common.emptyList')} description={t('quotes.emptyHint')} />
        ) : (
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('common.number')}</th>
                  <th>{t('common.date')}</th>
                  <th>{t('common.customer')}</th>
                  <th>{t('common.currency')}</th>
                  <th>{t('common.total')}</th>
                  <th>{t('common.status')}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {list.map((q) => (
                  <tr key={q.id} className="cursor-pointer" onClick={() => void openView(q.id)}>
                    <td className="font-mono text-xs">{q.quote_number}</td>
                    <td>{String(q.quote_date || '').slice(0, 10)}</td>
                    <td>{q.customer?.name || t('quotes.noCustomer')}</td>
                    <td>{q.currency || baseCurrency}</td>
                    <td className="tabular-nums">{formatMoney(q.total, q.currency || baseCurrency)}</td>
                    <td>{documentStatusLabel(q.status)}</td>
                    <td onClick={(e) => e.stopPropagation()}>
                      <TableActions>
                        <button
                          type="button"
                          className="text-xs text-teal"
                          onClick={() => openPrintPopup(`/print/sales-quotes/${q.id}`)}
                        >
                          <Printer size={14} className="inline" /> {t('common.print')}
                        </button>
                        <PdfExportButton
                          fileName={q.quote_number}
                          printPath={`/print/sales-quotes/${q.id}`}
                          compact
                        />
                        <WhatsAppSendButton
                          defaultPhone={q.customer?.phone}
                          fileName={q.quote_number}
                          documentLabel={`${t('quotes.documentTitle')} ${q.quote_number}`}
                          printPath={`/print/sales-quotes/${q.id}`}
                          compact
                        />
                        {canManage && q.status !== 'converted' && q.status !== 'cancelled' && (
                          <button type="button" className="text-xs text-teal" onClick={() => void openEdit(q.id)}>
                            {t('common.edit')}
                          </button>
                        )}
                        {canManage && q.status !== 'converted' && (
                          <button
                            type="button"
                            className="text-xs text-rose-600"
                            onClick={() => {
                              if (window.confirm(t('common.confirmDelete'))) remove.mutate(q.id)
                            }}
                          >
                            {t('common.delete')}
                          </button>
                        )}
                      </TableActions>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Modal
        open={modal !== null}
        onClose={closeModal}
        title={
          modal === 'create'
            ? t('quotes.new')
            : modal === 'edit'
              ? t('quotes.edit')
              : t('quotes.view')
        }
        size="xl"
        footer={
          readOnly ? (
            <div className="flex flex-wrap gap-2">
              {selectedId && (
                <>
                  <Button variant="primary" onClick={() => openPrintPopup(`/print/sales-quotes/${selectedId}`)}>
                    <Printer size={16} /> {t('common.print')}
                  </Button>
                  <PdfExportButton
                    fileName={`quote-${selectedId}`}
                    printPath={`/print/sales-quotes/${selectedId}`}
                  />
                  <WhatsAppSendButton
                    defaultPhone={selectedCustomerPhone}
                    fileName={`quote-${selectedId}`}
                    documentLabel={t('quotes.documentTitle')}
                    printPath={`/print/sales-quotes/${selectedId}`}
                  />
                </>
              )}
              {canManage && selectedId && form && (
                <Button variant="secondary" onClick={() => void openEdit(selectedId)}>{t('common.edit')}</Button>
              )}
              <Button variant="secondary" onClick={closeModal}>{t('common.close')}</Button>
            </div>
          ) : (
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" disabled={save.isPending} onClick={() => save.mutate()}>
                {save.isPending ? t('common.loading') : t('common.save')}
              </Button>
              <Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button>
            </div>
          )
        }
      >
        <div className="space-y-3">
          <p className="print-hide rounded-lg border border-teal/20 bg-teal/5 px-3 py-2 text-xs text-teal">
            {t('quotes.nonBindingNotice')}
          </p>

          {stockWarnings.length > 0 && (
            <div className="print-hide rounded-lg border border-amber/40 bg-amber/10 px-3 py-2 text-xs text-amber">
              <p className="mb-1 font-semibold">{t('quotes.stockWarningsTitle')}</p>
              <ul className="list-disc space-y-1 ps-4">
                {stockWarnings.map((w, i) => (
                  <li key={`${w.product_id}-${w.code}-${i}`}>
                    <span className="font-medium">{w.product_name || `#${w.product_id}`}: </span>
                    {w.message}
                  </li>
                ))}
              </ul>
              <p className="mt-1 text-black/55">{t('quotes.stockWarningsHint')}</p>
            </div>
          )}

          <div className="form-grid-2">
            <Field label={t('common.date')}>
              <input
                type="date"
                className={inputClass}
                value={form.quote_date}
                disabled={readOnly}
                onChange={(e) => setForm({ ...form, quote_date: e.target.value })}
              />
            </Field>
            <Field label={t('quotes.validityDays')}>
              <NumericInput
                value={validityDays}
                disabled={readOnly}
                onChange={applyValidityDays}
                placeholder="7"
              />
            </Field>
            <Field label={t('common.validUntil')}>
              <input
                type="date"
                className={inputClass}
                value={form.valid_until}
                disabled={readOnly}
                onChange={(e) => {
                  setValidityDays('')
                  setForm({ ...form, valid_until: e.target.value })
                }}
              />
            </Field>
            <Field label={t('common.customer')}>
              <select
                className={inputClass}
                value={form.customer_id}
                disabled={readOnly}
                onChange={(e) => setForm({ ...form, customer_id: e.target.value })}
              >
                <option value="">{t('quotes.noCustomer')}</option>
                {(customers.data || []).map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <Field label={t('common.warehouse')}>
              <select
                className={inputClass}
                value={form.warehouse_id}
                disabled={readOnly}
                onChange={(e) => setForm({ ...form, warehouse_id: e.target.value })}
              >
                <option value="">{t('quotes.anyWarehouse')}</option>
                {(warehouses.data || []).map((w) => (
                  <option key={w.id} value={w.id}>{w.name}</option>
                ))}
              </select>
            </Field>
            <Field label={t('common.branch')}>
              <select
                className={inputClass}
                value={form.branch_id}
                disabled={readOnly}
                onChange={(e) => setForm({ ...form, branch_id: e.target.value })}
              >
                <option value="">—</option>
                {activeBranches.map((b) => (
                  <option key={b.id} value={b.id}>{b.name}{b.code ? ` (${b.code})` : ''}</option>
                ))}
              </select>
            </Field>
          </div>

          {readOnly ? (
            <div className="form-grid-2 text-sm">
              <p><span className="text-black/55">{t('common.currency')}: </span>{form.currency}</p>
              {form.currency !== baseCurrency && (
                <p><span className="text-black/55">{t('common.exchangeRate')}: </span>{form.exchange_rate}</p>
              )}
            </div>
          ) : (
            <DocumentCurrencyFields
              state={form}
              setState={setForm}
              currencies={currencyList}
              baseCurrency={baseCurrency}
              showBasePreview
              documentTotal={estimatedTotal}
            />
          )}

          <Field label={t('quotes.notes')}>
            <textarea
              className={inputClass}
              rows={2}
              value={form.notes}
              disabled={readOnly}
              onChange={(e) => setForm({ ...form, notes: e.target.value })}
            />
          </Field>

          <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
              <p className="text-xs font-medium text-black/55">{t('common.lines')}</p>
              {!readOnly && (
                <Button type="button" variant="secondary" onClick={addLine}>{t('common.addLine')}</Button>
              )}
            </div>
            {form.lines.map((line, index) => {
              const product = (products.data || []).find((p) => String(p.id) === line.product_id)
              const lineTotal = round2((Number(line.quantity) || 0) * (Number(line.unit_price) || 0))
              return (
                <div key={index} className="rounded-lg border border-black/10 bg-black/[0.02] p-3 space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-xs font-medium text-black/50">{t('common.lineN', { n: index + 1 })}</span>
                    {!readOnly && form.lines.length > 1 && (
                      <button type="button" className="text-xs text-rose-600" onClick={() => removeLine(index)}>
                        {t('common.removeLine')}
                      </button>
                    )}
                  </div>
                  <ProductVariantSelect
                    products={products.data || []}
                    value={line.product_id}
                    disabled={readOnly}
                    onChange={(productId) => onProductChange(index, productId)}
                  />
                  {line.product_id && (
                    <Field label={t('common.unit')}>
                      <input className={`${inputClass} bg-black/5`} readOnly value={formatProductUnit(product?.unit)} />
                    </Field>
                  )}
                  <div className="form-grid-2">
                    <Field label={t('common.quantity')} hint={t('common.quantityUnit')}>
                      <NumericInput
                        value={line.quantity}
                        disabled={readOnly}
                        onChange={(v) => updateLine(index, { quantity: v })}
                      />
                    </Field>
                    <Field label={t('common.price')}>
                      <NumericInput
                        value={line.unit_price}
                        disabled={readOnly}
                        onChange={(v) => updateLine(index, { unit_price: v })}
                      />
                    </Field>
                  </div>
                  <p className="text-xs text-black/55">
                    {t('common.total')}: <span className="tabular-nums font-medium">{formatQuantity(lineTotal)}</span>
                  </p>
                </div>
              )
            })}
          </div>

          <div className="rounded-lg border border-black/10 bg-white px-3 py-2 text-sm">
            <p>
              <span className="text-black/55">{t('common.subtotal')}: </span>
              <span className="tabular-nums">{formatMoney(estimatedTotal, form.currency)}</span>
            </p>
            <p className="font-bold">
              {t('common.total')}: <span className="tabular-nums">{formatMoney(estimatedTotal, form.currency)}</span>
            </p>
          </div>
        </div>
      </Modal>
    </div>
  )
}
