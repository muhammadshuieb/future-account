import { useMemo, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { todayYmd } from '@/lib/dates'
import type { Account, JournalEntry } from '@/types'
import { documentStatusLabel } from '@/lib/statusLabels'
import { Button, Modal, Msg, NumericInput, PageHeader, Panel, inputClass } from '@/components/ui'

type LineDraft = {
  account_id: string
  debit: string
  credit: string
  memo: string
}

const emptyLine = (): LineDraft => ({ account_id: '', debit: '', credit: '', memo: '' })

export default function JournalEntriesPage() {
  const queryClient = useQueryClient()
  const [description, setDescription] = useState('')
  const [entryDate, setEntryDate] = useState(todayYmd())
  const [reference, setReference] = useState('')
  const [lines, setLines] = useState<LineDraft[]>([emptyLine(), emptyLine()])
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [viewOnly, setViewOnly] = useState(false)
  const [loadingEntry, setLoadingEntry] = useState(false)

  const { data: entriesData, isLoading } = useQuery({
    queryKey: ['journal-entries'],
    queryFn: async () => {
      const res = await api.get('/journal-entries')
      return res.data as { data: JournalEntry[] }
    },
  })

  const { data: accounts = [] } = useQuery({
    queryKey: ['accounts-postable'],
    queryFn: async () => {
      const res = await api.get('/accounts', { params: { postable_only: true } })
      return res.data.data as Account[]
    },
  })

  const totals = useMemo(() => {
    const debit = lines.reduce((sum, line) => sum + (Number(line.debit) || 0), 0)
    const credit = lines.reduce((sum, line) => sum + (Number(line.credit) || 0), 0)
    return { debit, credit, balanced: Math.round(debit * 100) === Math.round(credit * 100) && debit > 0 }
  }, [lines])

  function resetForm() {
    setDescription('')
    setReference('')
    setEntryDate(todayYmd())
    setLines([emptyLine(), emptyLine()])
    setEditingId(null)
    setViewOnly(false)
    setError('')
  }

  function openCreate() {
    resetForm()
    setModalOpen(true)
  }

  async function openEntry(entry: JournalEntry) {
    setLoadingEntry(true)
    setError('')
    try {
      const res = await api.get(`/journal-entries/${entry.id}`)
      const full = res.data.data as JournalEntry & {
        details?: { account_id: number; debit: number; credit: number; memo?: string }[]
        reference?: string
      }
      setEditingId(full.id)
      setEntryDate(String(full.entry_date).slice(0, 10))
      setDescription(full.description)
      setReference(full.reference || '')
      const details = full.details || []
      setLines(
        details.length >= 2
          ? details.map((d) => ({
              account_id: String(d.account_id),
              debit: d.debit ? String(d.debit) : '',
              credit: d.credit ? String(d.credit) : '',
              memo: d.memo || '',
            }))
          : [emptyLine(), emptyLine()],
      )
      setViewOnly(full.status !== 'draft')
      setModalOpen(true)
    } catch {
      setError('تعذر تحميل القيد')
    } finally {
      setLoadingEntry(false)
    }
  }

  function closeModal() {
    setModalOpen(false)
    resetForm()
  }

  const createMutation = useMutation({
    mutationFn: async (status: 'draft' | 'posted') => {
      const payload = {
        entry_date: entryDate,
        description,
        reference: reference || null,
        status,
        details: lines.map((line) => ({
          account_id: Number(line.account_id),
          debit: Number(line.debit) || 0,
          credit: Number(line.credit) || 0,
          memo: line.memo || null,
        })),
      }
      if (editingId && !viewOnly) {
        return api.put(`/journal-entries/${editingId}`, payload)
      }
      return api.post('/journal-entries', payload)
    },
    onSuccess: () => {
      setMessage('تم حفظ القيد.')
      closeModal()
      void queryClient.invalidateQueries({ queryKey: ['journal-entries'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: (err: { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }) => {
      const errors = err.response?.data?.errors
      const first = errors ? Object.values(errors)[0]?.[0] : undefined
      setError(first || err.response?.data?.message || 'تعذر حفظ القيد')
    },
  })

  const postMutation = useMutation({
    mutationFn: (id: number) => api.post(`/journal-entries/${id}/post`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['journal-entries'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })

  function updateLine(index: number, patch: Partial<LineDraft>) {
    setLines((prev) => prev.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  function onSubmit(e: FormEvent, status: 'draft' | 'posted') {
    e.preventDefault()
    createMutation.mutate(status)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="القيود اليومية"
        subtitle="قيد مزدوج — مجموع المدين يجب أن يساوي مجموع الدائن"
        actions={<Button variant="primary" onClick={openCreate}>إضافة</Button>}
      />
      <Msg message={message} error={error} />

      <Panel>
        <table className="w-full text-sm">
          <thead className="bg-mist text-black/60">
            <tr>
              <th className="px-4 py-3 text-right font-medium">الرقم</th>
              <th className="px-4 py-3 text-right font-medium">التاريخ</th>
              <th className="px-4 py-3 text-right font-medium">الوصف</th>
              <th className="px-4 py-3 text-right font-medium">الحالة</th>
              <th className="px-4 py-3 text-right font-medium"></th>
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-black/45">جاري التحميل...</td>
              </tr>
            )}
            {(entriesData?.data || []).map((entry) => (
              <tr
                key={entry.id}
                className="row-clickable border-t border-black/5"
                onClick={() => void openEntry(entry)}
                tabIndex={0}
                title={entry.status === 'draft' ? 'انقر للتعديل' : 'انقر للعرض'}
              >
                <td className="px-4 py-3 font-mono text-xs">{entry.entry_number}</td>
                <td className="px-4 py-3">{String(entry.entry_date).slice(0, 10)}</td>
                <td className="px-4 py-3">{entry.description}</td>
                <td className="px-4 py-3">{documentStatusLabel(entry.status)}</td>
                <td className="px-4 py-3">
                  {entry.status === 'draft' && (
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation()
                        postMutation.mutate(entry.id)
                      }}
                      className="text-teal hover:underline"
                    >
                      ترحيل
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Panel>

      <Modal
        open={modalOpen}
        onClose={closeModal}
        title={viewOnly ? 'عرض قيد' : editingId ? 'تعديل قيد' : 'قيد جديد'}
        size="xl"
        footer={
          viewOnly ? (
            <Button variant="secondary" onClick={closeModal}>إغلاق</Button>
          ) : (
            <>
              <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
              <Button variant="secondary" disabled={createMutation.isPending || !totals.balanced} onClick={(e) => onSubmit(e, 'draft')}>
                حفظ مسودة
              </Button>
              <Button variant="primary" disabled={createMutation.isPending || !totals.balanced} onClick={(e) => onSubmit(e, 'posted')}>
                حفظ وترحيل
              </Button>
            </>
          )
        }
      >
        {loadingEntry ? (
          <p className="text-sm text-black/50">جاري التحميل...</p>
        ) : (
          <div className="space-y-4">
            <div className="grid gap-3 md:grid-cols-3">
              <input
                type="date"
                value={entryDate}
                onChange={(e) => setEntryDate(e.target.value)}
                className={inputClass}
                required
                disabled={viewOnly}
              />
              <input
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="وصف القيد"
                className={inputClass}
                required
                disabled={viewOnly}
              />
              <input
                value={reference}
                onChange={(e) => setReference(e.target.value)}
                placeholder="مرجع (اختياري)"
                className={inputClass}
                disabled={viewOnly}
              />
            </div>

            <div className="overflow-x-auto">
              <table className="w-full min-w-[700px] text-sm">
                <thead className="bg-mist text-black/60">
                  <tr>
                    <th className="px-3 py-2 text-right font-medium">الحساب</th>
                    <th className="px-3 py-2 text-right font-medium">مدين</th>
                    <th className="px-3 py-2 text-right font-medium">دائن</th>
                    <th className="px-3 py-2 text-right font-medium">بيان</th>
                    <th className="px-3 py-2"></th>
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line, index) => (
                    <tr key={index} className="border-t border-black/5">
                      <td className="px-2 py-2">
                        <select
                          value={line.account_id}
                          onChange={(e) => updateLine(index, { account_id: e.target.value })}
                          className={inputClass}
                          required
                          disabled={viewOnly}
                        >
                          <option value="">اختر حساباً</option>
                          {accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                              {account.code} — {account.name}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="px-2 py-2">
                        <NumericInput
                          value={line.debit}
                          onChange={(v) => updateLine(index, { debit: v, credit: v ? '' : line.credit })}
                          className={inputClass}
                          disabled={viewOnly}
                        />
                      </td>
                      <td className="px-2 py-2">
                        <NumericInput
                          value={line.credit}
                          onChange={(v) => updateLine(index, { credit: v, debit: v ? '' : line.debit })}
                          className={inputClass}
                          disabled={viewOnly}
                        />
                      </td>
                      <td className="px-2 py-2">
                        <input
                          value={line.memo}
                          onChange={(e) => updateLine(index, { memo: e.target.value })}
                          className={inputClass}
                          disabled={viewOnly}
                        />
                      </td>
                      <td className="px-2 py-2">
                        {!viewOnly && lines.length > 2 && (
                          <button
                            type="button"
                            onClick={() => setLines((prev) => prev.filter((_, i) => i !== index))}
                            className="text-danger"
                          >
                            حذف
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {!viewOnly && (
              <div className="flex flex-wrap items-center justify-between gap-3">
                <button
                  type="button"
                  onClick={() => setLines((prev) => [...prev, emptyLine()])}
                  className="rounded-lg border border-black/10 px-3 py-2 text-sm"
                >
                  + سطر
                </button>
                <div className={`text-sm font-medium ${totals.balanced ? 'text-success' : 'text-amber'}`}>
                  مدين: {totals.debit.toFixed(2)} | دائن: {totals.credit.toFixed(2)}
                  {totals.balanced ? ' — متوازن' : ' — غير متوازن'}
                </div>
              </div>
            )}
            {error && <p className="text-sm text-danger">{error}</p>}
          </div>
        )}
      </Modal>
    </div>
  )
}
