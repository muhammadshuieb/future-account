import { useMemo, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import type { Account } from '@/types'
import { Button, Modal, Msg, PageHeader, Panel, inputClass } from '@/components/ui'

const typeLabels: Record<Account['type'], string> = {
  asset: 'أصول',
  liability: 'خصوم',
  equity: 'ملكية',
  revenue: 'إيرادات',
  expense: 'مصروفات',
}

const emptyForm = {
  code: '',
  name: '',
  name_en: '',
  parent_id: '' as string | number,
  type: 'asset' as Account['type'],
  nature: 'debit' as Account['nature'],
  is_group: false,
  is_active: true,
  description: '',
}

export default function AccountsPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [form, setForm] = useState(emptyForm)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  const { data: accounts = [], isLoading } = useQuery({
    queryKey: ['accounts', search],
    queryFn: async () => {
      const res = await api.get('/accounts', { params: { search: search || undefined } })
      return res.data.data as Account[]
    },
  })

  const parents = useMemo(
    () => accounts.filter((a) => a.is_group || a.level < 3),
    [accounts],
  )

  function openCreate() {
    setEditingId(null)
    setForm(emptyForm)
    setMessage('')
    setError('')
    setModalOpen(true)
  }

  function openEdit(account: Account) {
    setEditingId(account.id)
    setForm({
      code: account.code,
      name: account.name,
      name_en: account.name_en || '',
      parent_id: account.parent_id || '',
      type: account.type,
      nature: account.nature,
      is_group: account.is_group,
      is_active: account.is_active,
      description: account.description || '',
    })
    setMessage('')
    setError('')
    setModalOpen(true)
  }

  function closeModal() {
    setModalOpen(false)
    setEditingId(null)
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        parent_id: form.parent_id === '' ? null : Number(form.parent_id),
      }
      if (editingId) return api.put(`/accounts/${editingId}`, payload)
      return api.post('/accounts', payload)
    },
    onSuccess: () => {
      setMessage(editingId ? 'تم تحديث الحساب.' : 'تم إنشاء الحساب.')
      setError('')
      closeModal()
      void queryClient.invalidateQueries({ queryKey: ['accounts'] })
    },
    onError: (err: { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }) => {
      const first = err.response?.data?.errors
        ? Object.values(err.response.data.errors)[0]?.[0]
        : undefined
      setError(first || err.response?.data?.message || 'تعذر حفظ الحساب')
    },
  })

  function onSubmit(e: FormEvent) {
    e.preventDefault()
    saveMutation.mutate()
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="دليل الحسابات"
        subtitle="هيكل حسابات هرمي مع أنواع القيد الخمسة"
        actions={
          <>
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="بحث بالرمز أو الاسم..."
              className="w-64 rounded-lg border border-black/10 bg-white px-3 py-2 outline-none ring-teal focus:ring-2"
            />
            <Button variant="primary" onClick={openCreate}>إضافة</Button>
          </>
        }
      />

      <Msg message={message} error={error} />

      <Panel>
        <table className="w-full text-sm">
          <thead className="bg-mist text-right text-black/60">
            <tr>
              <th className="px-4 py-3 font-medium">الرمز</th>
              <th className="px-4 py-3 font-medium">الاسم</th>
              <th className="px-4 py-3 font-medium">النوع</th>
              <th className="px-4 py-3 font-medium">حالة</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr>
                <td colSpan={4} className="px-4 py-8 text-center text-black/45">جاري التحميل...</td>
              </tr>
            )}
            {!isLoading && accounts.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-8 text-center text-black/45">لا توجد حسابات</td>
              </tr>
            )}
            {accounts.map((account) => (
              <tr
                key={account.id}
                className="row-clickable border-t border-black/5"
                onClick={() => openEdit(account)}
                onKeyDown={(e) => e.key === 'Enter' && openEdit(account)}
                tabIndex={0}
                title="انقر للتعديل"
              >
                <td className="px-4 py-3 font-mono text-xs" style={{ paddingInlineStart: `${account.level * 12}px` }}>
                  {account.code}
                </td>
                <td className="px-4 py-3">
                  {account.name}
                  {account.is_group && (
                    <span className="ms-2 rounded bg-mist px-1.5 py-0.5 text-[10px] text-black/50">مجموعة</span>
                  )}
                </td>
                <td className="px-4 py-3">{typeLabels[account.type]}</td>
                <td className="px-4 py-3">
                  <span className={account.is_active ? 'text-success' : 'text-danger'}>
                    {account.is_active ? 'نشط' : 'موقوف'}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Panel>

      <Modal
        open={modalOpen}
        onClose={closeModal}
        title={editingId ? 'تعديل حساب' : 'حساب جديد'}
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              حفظ
            </Button>
          </>
        }
      >
        <form onSubmit={onSubmit} className="space-y-3">
          <input
            required
            value={form.code}
            onChange={(e) => setForm({ ...form, code: e.target.value })}
            placeholder="رمز الحساب"
            className={inputClass}
          />
          <input
            required
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            placeholder="اسم الحساب"
            className={inputClass}
          />
          <input
            value={form.name_en}
            onChange={(e) => setForm({ ...form, name_en: e.target.value })}
            placeholder="الاسم بالإنجليزية (اختياري)"
            className={inputClass}
          />
          <select
            value={form.parent_id}
            onChange={(e) => setForm({ ...form, parent_id: e.target.value })}
            className={inputClass}
          >
            <option value="">بدون أب (جذر)</option>
            {parents.map((p) => (
              <option key={p.id} value={p.id}>
                {p.code} — {p.name}
              </option>
            ))}
          </select>
          <div className="grid grid-cols-2 gap-2">
            <select
              value={form.type}
              onChange={(e) => setForm({ ...form, type: e.target.value as Account['type'] })}
              className={inputClass}
            >
              {Object.entries(typeLabels).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
            <select
              value={form.nature}
              onChange={(e) => setForm({ ...form, nature: e.target.value as Account['nature'] })}
              className={inputClass}
            >
              <option value="debit">مدين</option>
              <option value="credit">دائن</option>
            </select>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.is_group}
              onChange={(e) => setForm({ ...form, is_group: e.target.checked })}
            />
            حساب تجميعي (لا يُرحّل عليه)
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
            />
            نشط
          </label>
          {error && <p className="text-sm text-danger">{error}</p>}
        </form>
      </Modal>
    </div>
  )
}
