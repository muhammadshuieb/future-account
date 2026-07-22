import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import api from '@/lib/api'
import { todayYmd, yearStartYmd } from '@/lib/dates'
import { openPrintPopup } from '@/lib/printPopup'
import { useQueryTab } from '@/lib/useQueryTab'
import { statementTypeLabel } from '@/components/StatementPrintView'
import WhatsAppSendButton from '@/components/WhatsAppSendButton'
import { Button, Field, Modal, Msg, PageHeader, Panel, Tabs, formatMoney, inputClass, useFormMessage } from '@/components/ui'

type PartnerRow = { id: number; code: string; name: string; phone?: string; credit_limit?: number; is_active?: boolean }

const PARTNER_TABS = ['customers', 'suppliers'] as const
const emptyForm = { code: '', name: '', phone: '', credit_limit: '0' }

export default function PartnersPage() {
  const [tab, setTab] = useQueryTab(PARTNER_TABS, 'customers')
  const [statementId, setStatementId] = useState<number | null>(null)
  const [from, setFrom] = useState(yearStartYmd)
  const [to, setTo] = useState(todayYmd)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm)
  const qc = useQueryClient()
  const msg = useFormMessage()

  const customers = useQuery({ queryKey: ['customers'], queryFn: async () => (await api.get('/customers')).data.data as PartnerRow[] })
  const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: async () => (await api.get('/suppliers')).data.data as PartnerRow[] })
  const currencies = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api.get('/currencies')).data.data as { base_currency: string },
  })
  const base = currencies.data?.base_currency || 'SYP'

  const statement = useQuery({
    queryKey: ['statement', tab, statementId, from, to],
    queryFn: async () =>
      (await api.get(`/${tab}/${statementId}/statement`, { params: { from, to } })).data.data,
    enabled: !!statementId,
  })

  const rows = tab === 'customers' ? customers.data : suppliers.data

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
    <div className="space-y-6">
      <PageHeader
        title="العملاء والموردون"
        subtitle="بطاقات الاتصال، حدود الائتمان، وكشوف الحساب"
        actions={<Button variant="primary" onClick={openCreate}>إضافة</Button>}
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

      <Panel>
        <table className="w-full text-sm">
          <thead className="bg-mist text-right text-black/60">
            <tr>
              <th className="px-4 py-3">رمز</th>
              <th className="px-4 py-3">الاسم</th>
              <th className="px-4 py-3">هاتف</th>
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
                <td className="px-4 py-3">
                  <div className="flex flex-wrap items-center gap-3">
                    <button
                      type="button"
                      className="text-teal"
                      onClick={(e) => {
                        e.stopPropagation()
                        setStatementId(r.id)
                      }}
                    >
                      كشف حساب
                    </button>
                    <button
                      type="button"
                      className="inline-flex items-center gap-1 text-teal"
                      onClick={(e) => {
                        e.stopPropagation()
                        printStatement(r.id)
                      }}
                    >
                      <Printer size={14} /> طباعة
                    </button>
                    <span onClick={(e) => e.stopPropagation()}>
                      <WhatsAppSendButton
                        compact
                        defaultPhone={r.phone}
                        printPath={`/print/${tab}/${r.id}/statement${from || to ? `?${new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) }).toString()}` : ''}`}
                        fileName={`statement-${tab}-${r.id}`}
                        documentLabel={`كشف حساب — ${r.name}`}
                      />
                    </span>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Panel>

      {statementId && (
        <Panel>
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-black/5 px-4 py-3">
            <div className="font-semibold">
              كشف حساب — الرصيد الختامي:{' '}
              {statement.data ? formatMoney(Number(statement.data.closing_balance ?? statement.data.balance) || 0, base) : '…'}
            </div>
            <div className="print-hide flex flex-wrap items-center gap-2">
              <Button variant="secondary" onClick={() => printStatement(statementId)}>
                <Printer size={16} /> طباعة
              </Button>
              <WhatsAppSendButton
                defaultPhone={(rows || []).find((r) => r.id === statementId)?.phone}
                printPath={`/print/${tab}/${statementId}/statement${from || to ? `?${new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) }).toString()}` : ''}`}
                fileName={`statement-${tab}-${statementId}`}
                documentLabel={`كشف حساب — ${(rows || []).find((r) => r.id === statementId)?.name || ''}`}
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
          </div>
          {statement.isLoading && <p className="p-4 text-sm text-black/55">جاري التحميل...</p>}
          {statement.error && <p className="p-4 text-sm text-danger">تعذر تحميل كشف الحساب</p>}
          {statement.data && (
            <>
              <div className="grid gap-2 px-4 py-3 text-sm sm:grid-cols-2">
                <p>الرصيد الافتتاحي: <strong className="tabular-nums">{formatMoney(Number(statement.data.opening_balance) || 0, base)}</strong></p>
                <p>الرصيد الختامي: <strong className="tabular-nums">{formatMoney(Number(statement.data.closing_balance ?? statement.data.balance) || 0, base)}</strong></p>
              </div>
              <table className="w-full text-sm">
                <thead className="bg-mist text-right text-black/60">
                  <tr>
                    <th className="px-4 py-3">تاريخ</th>
                    <th className="px-4 py-3">نوع</th>
                    <th className="px-4 py-3">رقم</th>
                    <th className="px-4 py-3">مدين</th>
                    <th className="px-4 py-3">دائن</th>
                    <th className="px-4 py-3">رصيد</th>
                  </tr>
                </thead>
                <tbody>
                  {(statement.data.rows || []).map((r: { date: string; type: string; number: string; debit: number; credit: number; balance: number }, idx: number) => (
                    <tr key={idx} className="border-t border-black/5">
                      <td className="px-4 py-3">{r.date}</td>
                      <td className="px-4 py-3">{statementTypeLabel(r.type)}</td>
                      <td className="px-4 py-3 font-mono text-xs">{r.number}</td>
                      <td className="px-4 py-3 tabular-nums">{formatMoney(Number(r.debit) || 0, base)}</td>
                      <td className="px-4 py-3 tabular-nums">{formatMoney(Number(r.credit) || 0, base)}</td>
                      <td className="px-4 py-3 tabular-nums">{formatMoney(Number(r.balance) || 0, base)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          )}
        </Panel>
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
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
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
        </form>
      </Modal>
    </div>
  )
}
