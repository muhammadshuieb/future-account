import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Button, Field, Modal, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

type PartnerRow = { id: number; code: string; name: string; phone?: string; credit_limit?: number; is_active?: boolean }

const emptyForm = { code: '', name: '', phone: '', credit_limit: '0' }

export default function PartnersPage() {
  const [tab, setTab] = useState('customers')
  const [statementId, setStatementId] = useState<number | null>(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm)
  const qc = useQueryClient()
  const msg = useFormMessage()

  const customers = useQuery({ queryKey: ['customers'], queryFn: async () => (await api.get('/customers')).data.data as PartnerRow[] })
  const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: async () => (await api.get('/suppliers')).data.data as PartnerRow[] })
  const statement = useQuery({
    queryKey: ['statement', tab, statementId],
    queryFn: async () => (await api.get(`/${tab}/${statementId}/statement`)).data.data,
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
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Panel>

      {statementId && statement.data && (
        <Panel>
          <div className="border-b border-black/5 px-4 py-3 font-semibold">كشف حساب — الرصيد: {statement.data.balance}</div>
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
                  <td className="px-4 py-3">{r.type}</td>
                  <td className="px-4 py-3 font-mono text-xs">{r.number}</td>
                  <td className="px-4 py-3">{r.debit}</td>
                  <td className="px-4 py-3">{r.credit}</td>
                  <td className="px-4 py-3">{r.balance}</td>
                </tr>
              ))}
            </tbody>
          </table>
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
