import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { FileText, Printer } from 'lucide-react'
import api from '@/lib/api'
import { todayYmd, yearStartYmd } from '@/lib/dates'
import { openPrintPopup } from '@/lib/printPopup'
import { useQueryTab } from '@/lib/useQueryTab'
import PartnerStatementPanel, { partnerBalanceLabel } from '@/components/PartnerStatementPanel'
import type { PartnerStatementData } from '@/components/StatementPrintView'
import WhatsAppSendButton from '@/components/WhatsAppSendButton'
import ExcelExportButton from '@/components/ExcelExportButton'
import PdfExportButton from '@/components/PdfExportButton'
import { excelModuleForPartnersTab } from '@/lib/excelExport'
import { Button, EmptyState, Field, ListSearchInput, Modal, Msg, PageHeader, Panel, TableActions, Tabs, inputClass, useFormMessage } from '@/components/ui'
import { useListSearch } from '@/lib/useListSearch'

type PartnerRow = { id: number; code: string; name: string; phone?: string; credit_limit?: number; is_active?: boolean; balance?: number }

const PARTNER_TABS = ['customers', 'suppliers'] as const
const emptyForm = { code: '', name: '', phone: '', credit_limit: '0' }

export default function PartnersPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useQueryTab(PARTNER_TABS, 'customers')
  const [statementId, setStatementId] = useState<number | null>(null)
  const [from, setFrom] = useState(yearStartYmd)
  const [to, setTo] = useState(todayYmd)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm)
  const statementPanelRef = useRef<HTMLDivElement>(null)
  const qc = useQueryClient()
  const msg = useFormMessage()
  const search = useListSearch()
  const kind = tab === 'customers' ? 'customer' : 'supplier'
  const balanceLabels = { owedByThem: t('common.owedByThem'), owedToThem: t('common.owedToThem') }

  useEffect(() => {
    if (!statementId) return
    const id = window.setTimeout(() => {
      statementPanelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }, 50)
    return () => window.clearTimeout(id)
  }, [statementId])

  function openStatement(id: number) {
    setModalOpen(false)
    setEditingId(null)
    setStatementId(id)
  }

  const customers = useQuery({
    queryKey: ['customers', search.debouncedQ],
    queryFn: async () => (await api.get('/customers', { params: search.params })).data.data as PartnerRow[],
  })
  const suppliers = useQuery({
    queryKey: ['suppliers', search.debouncedQ],
    queryFn: async () => (await api.get('/suppliers', { params: search.params })).data.data as PartnerRow[],
  })
  const currencies = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api.get('/currencies')).data.data as { base_currency: string },
  })
  const base = currencies.data?.base_currency || 'USD'

  const statement = useQuery({
    queryKey: ['statement', tab, statementId, from, to],
    queryFn: async () =>
      (await api.get(`/${tab}/${statementId}/statement`, { params: { from, to } })).data.data as PartnerStatementData,
    enabled: !!statementId,
  })

  const rows = tab === 'customers' ? customers.data : suppliers.data
  const totalBalance = (rows || []).reduce((sum, r) => sum + (Number(r.balance) || 0), 0)

  function balanceLabel(balance: number) {
    return partnerBalanceLabel(balance, kind, base, balanceLabels)
  }

  function openCreate() {
    setEditingId(null)
    setForm(emptyForm)
    msg.setError('')
    setModalOpen(true)
  }

  function openEdit(r: PartnerRow) {
    setEditingId(r.id)
    setForm({
      code: r.code,
      name: r.name,
      phone: r.phone || '',
      credit_limit: String(r.credit_limit ?? 0),
    })
    msg.setError('')
    setModalOpen(true)
  }

  function closeModal() {
    setModalOpen(false)
    setEditingId(null)
  }

  function printStatement(id: number) {
    const qs = new URLSearchParams()
    if (from) qs.set('from', from)
    if (to) qs.set('to', to)
    const q = qs.toString()
    openPrintPopup(`/print/${tab}/${id}/statement${q ? `?${q}` : ''}`)
  }

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, credit_limit: Number(form.credit_limit), is_active: true }
      if (editingId) return api.put(`/${tab}/${editingId}`, payload)
      return api.post(`/${tab}`, payload)
    },
    onSuccess: () => {
      msg.setMessage(editingId ? 'تم التحديث' : 'تم الحفظ')
      closeModal()
      void qc.invalidateQueries({ queryKey: [tab] })
    },
    onError: msg.fromErr,
  })

  return (
    <div className="page-layout">
      <PageHeader
        title="العملاء والموردون"
        subtitle="بطاقات الاتصال، حدود الائتمان، وكشوف الحساب"
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ListSearchInput value={search.q} onChange={search.setQ} />
            <ExcelExportButton path={`/exports/${excelModuleForPartnersTab(tab)}`} />
            <Button variant="primary" onClick={openCreate}>إضافة</Button>
          </div>
        }
      />
      <Tabs
        tabs={[{ id: 'customers', label: 'العملاء' }, { id: 'suppliers', label: 'الموردون' }]}
        active={tab}
        onChange={(id) => {
          setTab(id)
          setStatementId(null)
          closeModal()
        }}
      />
      <Msg message={msg.message} error={msg.error} />

      {search.debouncedQ && !(rows || []).length ? <EmptyState title="لا توجد نتائج مطابقة" /> : null}

      <Panel>
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-black/5 px-4 py-3 text-sm">
          <span className="text-black/60">{t('common.totalBalances')}</span>
          <strong className="tabular-nums">{balanceLabel(totalBalance)}</strong>
        </div>
        <div className="table-wrap">
        <table className="w-full text-sm">
          <thead className="bg-mist text-right text-black/60">
            <tr>
              <th className="px-4 py-3">رمز</th>
              <th className="px-4 py-3">الاسم</th>
              <th className="px-4 py-3">هاتف</th>
              <th className="px-4 py-3">الرصيد</th>
              <th className="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            {(rows || []).map((r) => (
              <tr
                key={r.id}
                className="row-clickable border-t border-black/5"
                onClick={() => openEdit(r)}
                onKeyDown={(e) => e.key === 'Enter' && openEdit(r)}
                tabIndex={0}
                title="انقر للتعديل"
              >
                <td className="px-4 py-3 font-mono">{r.code}</td>
                <td className="px-4 py-3">{r.name}</td>
                <td className="px-4 py-3">{r.phone || '—'}</td>
                <td className="px-4 py-3 tabular-nums font-medium">{balanceLabel(Number(r.balance) || 0)}</td>
                <td
                  className="whitespace-nowrap px-2 py-2"
                  onClick={(e) => e.stopPropagation()}
                  onKeyDown={(e) => e.stopPropagation()}
                >
                  <TableActions>
                    <button
                      type="button"
                      className="text-xs text-teal"
                      onClick={(e) => {
                        e.preventDefault()
                        e.stopPropagation()
                        openStatement(r.id)
                      }}
                    >
                      <FileText size={14} aria-hidden /> كشف حساب
                    </button>
                    <button
                      type="button"
                      className="text-xs text-teal"
                      onClick={(e) => {
                        e.preventDefault()
                        e.stopPropagation()
                        printStatement(r.id)
                      }}
                    >
                      <Printer size={14} aria-hidden /> طباعة
                    </button>
                    <PdfExportButton
                      compact
                      printPath={`/print/${tab}/${r.id}/statement${from || to ? `?${new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) }).toString()}` : ''}`}
                      fileName={`statement-${tab}-${r.id}`}
                    />
                    <WhatsAppSendButton
                      compact
                      defaultPhone={r.phone}
                      printPath={`/print/${tab}/${r.id}/statement${from || to ? `?${new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) }).toString()}` : ''}`}
                      fileName={`statement-${tab}-${r.id}`}
                      documentLabel={`كشف حساب — ${r.name}`}
                      messageExtra={from || to ? `${from || '…'} → ${to || '…'}` : undefined}
                      excelPath={`/exports/reports/${tab === 'suppliers' ? 'supplier-statement' : 'customer-statement'}`}
                      excelParams={{
                        from,
                        to,
                        ...(tab === 'suppliers' ? { supplier_id: r.id } : { customer_id: r.id }),
                      }}
                    />
                  </TableActions>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
      </Panel>

      {statementId && (
        <div ref={statementPanelRef}>
        <Panel>
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-black/5 px-4 py-3">
            <div className="font-semibold">
              كشف حساب — الرصيد الحالي:{' '}
              {statement.data
                ? balanceLabel(Number(statement.data.closing_balance ?? statement.data.balance) || 0)
                : '…'}
            </div>
            <div className="print-hide flex flex-wrap items-center gap-2">
              <ExcelExportButton
                path={`/exports/reports/${tab === 'suppliers' ? 'supplier-statement' : 'customer-statement'}`}
                params={{
                  from,
                  to,
                  ...(tab === 'suppliers' ? { supplier_id: statementId } : { customer_id: statementId }),
                }}
              />
              <Button variant="secondary" onClick={() => printStatement(statementId)}>
                <Printer size={16} /> طباعة
              </Button>
              <PdfExportButton
                printPath={`/print/${tab}/${statementId}/statement${from || to ? `?${new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) }).toString()}` : ''}`}
                fileName={`statement-${tab}-${statementId}`}
              />
              <WhatsAppSendButton
                defaultPhone={(rows || []).find((r) => r.id === statementId)?.phone}
                printPath={`/print/${tab}/${statementId}/statement${from || to ? `?${new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) }).toString()}` : ''}`}
                fileName={`statement-${tab}-${statementId}`}
                documentLabel={`كشف حساب — ${(rows || []).find((r) => r.id === statementId)?.name || ''}`}
                messageExtra={from || to ? `${from || '…'} → ${to || '…'}` : undefined}
                excelPath={`/exports/reports/${tab === 'suppliers' ? 'supplier-statement' : 'customer-statement'}`}
                excelParams={{
                  from,
                  to,
                  ...(tab === 'suppliers' ? { supplier_id: statementId } : { customer_id: statementId }),
                }}
              />
            </div>
          </div>
          <div className="print-hide flex flex-wrap gap-3 border-b border-black/5 px-4 py-3">
            <Field label="من">
              <input type="date" className={inputClass} value={from} onChange={(e) => setFrom(e.target.value)} />
            </Field>
            <Field label="إلى">
              <input type="date" className={inputClass} value={to} onChange={(e) => setTo(e.target.value)} />
            </Field>
            <Button variant="ghost" className="self-end" onClick={() => setStatementId(null)}>
              إغلاق
            </Button>
          </div>
          {statement.isLoading && <p className="p-4 text-sm text-black/55">جاري التحميل...</p>}
          {statement.error && <p className="p-4 text-sm text-danger">تعذر تحميل كشف الحساب</p>}
          {statement.data && (
            <PartnerStatementPanel data={statement.data} kind={kind} currency={base} />
          )}
        </Panel>
        </div>
      )}

      <Modal
        open={modalOpen}
        onClose={closeModal}
        title={editingId ? (tab === 'customers' ? 'تعديل عميل' : 'تعديل مورد') : tab === 'customers' ? 'عميل جديد' : 'مورد جديد'}
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? 'جاري الحفظ...' : 'حفظ'}
            </Button>
          </>
        }
      >
        <form
          className="form-stack"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
          <div className="form-grid-2">
            <Field label="الرمز">
              <input className={inputClass} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} required />
            </Field>
            <Field label="الاسم">
              <input className={inputClass} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            </Field>
            <Field label="الهاتف">
              <input className={inputClass} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            </Field>
            <Field label="حد الائتمان">
              <input className={inputClass} value={form.credit_limit} onChange={(e) => setForm({ ...form, credit_limit: e.target.value })} />
            </Field>
          </div>
        </form>
      </Modal>
    </div>
  )
}
