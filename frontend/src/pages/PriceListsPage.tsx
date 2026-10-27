import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import api from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { useListSearch } from '@/lib/useListSearch'
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
  formatMoney,
  inputClass,
  useFormMessage,
} from '@/components/ui'

type PriceListMeta = {
  id: number
  name: string
  currency: string
  is_active: boolean
  notes?: string | null
  items_count?: number
  updated_by?: { id: number; name: string } | null
  updated_at?: string
}

type PriceListItem = {
  id: number
  sort_order: number
  category?: string | null
  row_no?: string | null
  model_no?: string | null
  mp?: string | null
  specs?: string | null
  lens?: string | null
  price_super_wholesale?: number | string | null
  price_retail?: number | string | null
  price_wholesale?: number | string | null
  is_category_header: boolean
  is_active: boolean
}

type ItemForm = {
  category: string
  row_no: string
  model_no: string
  mp: string
  specs: string
  lens: string
  price_super_wholesale: string
  price_retail: string
  price_wholesale: string
  is_category_header: boolean
}

const emptyForm = (): ItemForm => ({
  category: '',
  row_no: '',
  model_no: '',
  mp: '',
  specs: '',
  lens: '',
  price_super_wholesale: '',
  price_retail: '',
  price_wholesale: '',
  is_category_header: false,
})

function moneyStr(v: number | string | null | undefined): string {
  if (v === null || v === undefined || v === '') return ''
  const n = Number(v)
  return Number.isFinite(n) ? String(n) : ''
}

export default function PriceListsPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const canView = hasPermission('price_lists.view') || hasPermission('price_lists.manage')
  const canManage = hasPermission('price_lists.manage')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const search = useListSearch()
  const [category, setCategory] = useState('')
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<ItemForm>(emptyForm())

  const lists = useQuery({
    queryKey: ['price-lists'],
    queryFn: async () => (await api.get('/price-lists')).data.data as PriceListMeta[],
    enabled: canView,
  })

  const listId = lists.data?.[0]?.id ?? null

  const detail = useQuery({
    queryKey: ['price-lists', listId, search.debouncedQ, category],
    queryFn: async () => {
      const params: Record<string, string> = {}
      if (search.debouncedQ) params.q = search.debouncedQ
      if (category) params.category = category
      return (await api.get(`/price-lists/${listId}`, { params })).data.data as {
        list: PriceListMeta
        items: PriceListItem[]
        categories: string[]
        can_manage: boolean
      }
    },
    enabled: canView && !!listId,
  })

  const manage = canManage && (detail.data?.can_manage ?? canManage)
  const items = detail.data?.items ?? []
  const meta = detail.data?.list ?? lists.data?.[0]

  const categories = useMemo(() => {
    const fromApi = detail.data?.categories ?? []
    if (fromApi.length > 0) return fromApi
    const seen = new Set<string>()
    for (const item of items) {
      const c = item.category?.trim()
      if (c) seen.add(c)
    }
    return [...seen]
  }, [detail.data?.categories, items])

  const saveItem = useMutation({
    mutationFn: async () => {
      if (!listId) throw new Error('no list')
      const payload = {
        category: form.category || null,
        row_no: form.row_no || null,
        model_no: form.model_no || null,
        mp: form.mp || null,
        specs: form.specs || null,
        lens: form.lens || null,
        price_super_wholesale: form.price_super_wholesale === '' ? null : Number(form.price_super_wholesale),
        price_retail: form.price_retail === '' ? null : Number(form.price_retail),
        price_wholesale: form.price_wholesale === '' ? null : Number(form.price_wholesale),
        is_category_header: form.is_category_header,
      }
      if (editingId) {
        return api.put(`/price-lists/${listId}/items/${editingId}`, payload)
      }
      return api.post(`/price-lists/${listId}/items`, payload)
    },
    onSuccess: () => {
      msg.setMessage(t('priceLists.saved'))
      setModalOpen(false)
      setEditingId(null)
      setForm(emptyForm())
      void qc.invalidateQueries({ queryKey: ['price-lists'] })
    },
    onError: msg.fromErr,
  })

  const deleteItem = useMutation({
    mutationFn: async (id: number) => {
      if (!listId) throw new Error('no list')
      return api.delete(`/price-lists/${listId}/items/${id}`)
    },
    onSuccess: () => {
      msg.setMessage(t('priceLists.deleted'))
      void qc.invalidateQueries({ queryKey: ['price-lists'] })
    },
    onError: msg.fromErr,
  })

  function openCreate() {
    setEditingId(null)
    setForm({ ...emptyForm(), category })
    setModalOpen(true)
  }

  function openEdit(item: PriceListItem) {
    setEditingId(item.id)
    setForm({
      category: item.category ?? '',
      row_no: item.row_no ?? '',
      model_no: item.model_no ?? '',
      mp: item.mp ?? '',
      specs: item.specs ?? '',
      lens: item.lens ?? '',
      price_super_wholesale: moneyStr(item.price_super_wholesale),
      price_retail: moneyStr(item.price_retail),
      price_wholesale: moneyStr(item.price_wholesale),
      is_category_header: !!item.is_category_header,
    })
    setModalOpen(true)
  }

  const currency = meta?.currency || 'USD'

  const subtitle = useMemo(() => {
    if (!manage) return t('priceLists.viewOnly')
    if (meta?.updated_by?.name) return `${t('priceLists.subtitle')} · ${t('priceLists.updatedBy')}: ${meta.updated_by.name}`
    return t('priceLists.subtitle')
  }, [manage, meta, t])

  if (!canView) {
    return (
      <div className="p-6">
        <EmptyState title={t('priceLists.noPermission')} />
      </div>
    )
  }

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title={meta?.name || t('priceLists.title')}
        subtitle={subtitle}
        actions={
          manage ? (
            <Button type="button" onClick={openCreate}>
              <Plus className="h-4 w-4" />
              {t('priceLists.addItem')}
            </Button>
          ) : undefined
        }
      />

      <Msg message={msg.message} error={msg.error} />

      <Panel>
        <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
          <div className="min-w-0 flex-1">
            <ListSearchInput value={search.q} onChange={search.setQ} placeholder={t('priceLists.search')} />
          </div>
          <div className="sm:w-64">
            <Field label={t('priceLists.category')}>
              <select
                className={`${inputClass} font-medium text-ink`}
                value={category}
                onChange={(e) => setCategory(e.target.value)}
              >
                <option value="">{t('priceLists.allCategories')}</option>
                {categories.map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </select>
            </Field>
          </div>
        </div>

        {lists.isLoading || detail.isLoading ? (
          <div className="skeleton h-40 w-full rounded-xl" />
        ) : items.length === 0 ? (
          <EmptyState title={t('priceLists.empty')} />
        ) : (
          <div className="table-wrap">
            <table className="data-table text-sm">
              <thead>
                <tr className="bg-mist text-right text-black/70">
                  <th className="whitespace-nowrap">{t('priceLists.rowNo')}</th>
                  <th>{t('priceLists.model')}</th>
                  <th className="whitespace-nowrap">{t('priceLists.mp')}</th>
                  <th>{t('priceLists.specs')}</th>
                  <th className="whitespace-nowrap">{t('priceLists.lens')}</th>
                  <th className="whitespace-nowrap text-left">{t('priceLists.superWholesale')}</th>
                  <th className="whitespace-nowrap text-left">{t('priceLists.retail')}</th>
                  <th className="whitespace-nowrap text-left">{t('priceLists.wholesale')}</th>
                  {manage ? <th>{t('priceLists.actions')}</th> : null}
                </tr>
              </thead>
              <tbody>
                {items.map((item) =>
                  item.is_category_header ? (
                    <tr key={item.id} className="bg-teal-soft">
                      <td
                        colSpan={manage ? 9 : 8}
                        className="!py-2.5 text-base font-bold text-teal-dark"
                      >
                        {item.model_no || item.category}
                      </td>
                    </tr>
                  ) : (
                    <tr key={item.id} className="hover:bg-teal-soft/40">
                      <td className="tabular-nums text-black/55">{item.row_no}</td>
                      <td className="font-semibold text-ink">{item.model_no}</td>
                      <td className="whitespace-nowrap text-ink">{item.mp}</td>
                      <td className="max-w-[280px] text-black/75">
                        <span className="line-clamp-2" title={item.specs ?? undefined}>
                          {item.specs}
                        </span>
                      </td>
                      <td className="whitespace-nowrap text-ink">{item.lens || '—'}</td>
                      <td className="text-left font-semibold tabular-nums text-teal-dark">
                        {item.price_super_wholesale != null
                          ? formatMoney(Number(item.price_super_wholesale), currency)
                          : '—'}
                      </td>
                      <td className="text-left font-semibold tabular-nums text-amber">
                        {item.price_retail != null ? formatMoney(Number(item.price_retail), currency) : '—'}
                      </td>
                      <td className="text-left font-semibold tabular-nums text-ink">
                        {item.price_wholesale != null
                          ? formatMoney(Number(item.price_wholesale), currency)
                          : '—'}
                      </td>
                      {manage ? (
                        <td>
                          <div className="flex gap-1">
                            <button
                              type="button"
                              className="rounded-lg p-1.5 text-teal-dark hover:bg-teal-soft"
                              onClick={() => openEdit(item)}
                              title={t('priceLists.editItem')}
                            >
                              <Pencil className="h-4 w-4" />
                            </button>
                            <button
                              type="button"
                              className="rounded-lg p-1.5 text-danger hover:bg-red-50"
                              onClick={() => {
                                if (window.confirm(t('priceLists.confirmDelete'))) {
                                  deleteItem.mutate(item.id)
                                }
                              }}
                              title={t('priceLists.deleteItem')}
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
                          </div>
                        </td>
                      ) : null}
                    </tr>
                  ),
                )}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Modal
        open={modalOpen}
        onClose={() => {
          setModalOpen(false)
          setEditingId(null)
        }}
        title={editingId ? t('priceLists.editItem') : t('priceLists.addItem')}
      >
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            saveItem.mutate()
          }}
        >
          <label className="flex items-center gap-2 text-sm text-ink">
            <input
              type="checkbox"
              checked={form.is_category_header}
              onChange={(e) => setForm((f) => ({ ...f, is_category_header: e.target.checked }))}
            />
            {t('priceLists.isCategoryHeader')}
          </label>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('priceLists.category')}>
              <input className={inputClass} value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} />
            </Field>
            <Field label={t('priceLists.rowNo')}>
              <input className={inputClass} value={form.row_no} onChange={(e) => setForm((f) => ({ ...f, row_no: e.target.value }))} />
            </Field>
            <div className="sm:col-span-2">
              <Field label={t('priceLists.model')}>
                <input className={inputClass} value={form.model_no} onChange={(e) => setForm((f) => ({ ...f, model_no: e.target.value }))} required />
              </Field>
            </div>
            <Field label={t('priceLists.mp')}>
              <input className={inputClass} value={form.mp} onChange={(e) => setForm((f) => ({ ...f, mp: e.target.value }))} />
            </Field>
            <Field label={t('priceLists.lens')}>
              <input className={inputClass} value={form.lens} onChange={(e) => setForm((f) => ({ ...f, lens: e.target.value }))} />
            </Field>
            <div className="sm:col-span-2">
              <Field label={t('priceLists.specs')}>
                <textarea
                  className={inputClass}
                  rows={3}
                  value={form.specs}
                  onChange={(e) => setForm((f) => ({ ...f, specs: e.target.value }))}
                />
              </Field>
            </div>
            {!form.is_category_header ? (
              <>
                <Field label={t('priceLists.superWholesale')}>
                  <NumericInput
                    className={inputClass}
                    value={form.price_super_wholesale}
                    onChange={(v) => setForm((f) => ({ ...f, price_super_wholesale: v }))}
                  />
                </Field>
                <Field label={t('priceLists.retail')}>
                  <NumericInput
                    className={inputClass}
                    value={form.price_retail}
                    onChange={(v) => setForm((f) => ({ ...f, price_retail: v }))}
                  />
                </Field>
                <Field label={t('priceLists.wholesale')}>
                  <NumericInput
                    className={inputClass}
                    value={form.price_wholesale}
                    onChange={(v) => setForm((f) => ({ ...f, price_wholesale: v }))}
                  />
                </Field>
              </>
            ) : null}
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="ghost" onClick={() => setModalOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" disabled={saveItem.isPending}>
              {t('priceLists.saveItem')}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
