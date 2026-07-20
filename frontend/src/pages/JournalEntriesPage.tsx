import { useMemo, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import type { Account, JournalEntry } from '@/types'

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
  const [entryDate, setEntryDate] = useState(new Date().toISOString().slice(0, 10))
  const [reference, setReference] = useState('')
  const [lines, setLines] = useState<LineDraft[]>([emptyLine(), emptyLine()])
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

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

  const createMutation = useMutation({
    mutationFn: async (status: 'draft' | 'posted') => {
      return api.post('/journal-entries', {
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
      })
    },
    onSuccess: () => {
      setMessage('تم حفظ القيد.')
      setError('')
      setDescription('')
      setReference('')
      setLines([emptyLine(), emptyLine()])
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

  const statusLabel = {
    draft: 'مسودة',
    posted: 'مرحّل',
    void: 'ملغى',
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-3xl font-bold">القيود اليومية</h1>
        <p className="mt-1 text-black/55">قيد مزدوج — مجموع المدين يجب أن يساوي مجموع الدائن</p>
      </header>

      <form className="space-y-4 rounded-2xl border border-black/5 bg-white p-5 shadow-sm">
        <div className="grid gap-3 md:grid-cols-3">
          <input
            type="date"
            value={entryDate}
            onChange={(e) => setEntryDate(e.target.value)}
            className="rounded-lg border border-black/10 px-3 py-2"
            required
          />
          <input
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="وصف القيد"
            className="rounded-lg border border-black/10 px-3 py-2 md:col-span-1"
            required
          />
          <input
            value={reference}
            onChange={(e) => setReference(e.target.value)}
            placeholder="مرجع (اختياري)"
            className="rounded-lg border border-black/10 px-3 py-2"
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
                      className="w-full rounded-lg border border-black/10 px-2 py-2"
                      required
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
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={line.debit}
                      onChange={(e) => updateLine(index, { debit: e.target.value, credit: e.target.value ? '' : line.credit })}
                      className="w-full rounded-lg border border-black/10 px-2 py-2"
                    />
                  </td>
                  <td className="px-2 py-2">
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={line.credit}
                      onChange={(e) => updateLine(index, { credit: e.target.value, debit: e.target.value ? '' : line.debit })}
                      className="w-full rounded-lg border border-black/10 px-2 py-2"
                    />
                  </td>
                  <td className="px-2 py-2">
                    <input
                      value={line.memo}
                      onChange={(e) => updateLine(index, { memo: e.target.value })}
                      className="w-full rounded-lg border border-black/10 px-2 py-2"
                    />
                  </td>
                  <td className="px-2 py-2">
                    {lines.length > 2 && (
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

        {error && <p className="text-sm text-danger">{error}</p>}
        {message && <p className="text-sm text-success">{message}</p>}

        <div className="flex gap-2">
          <button
            type="button"
            onClick={(e) => onSubmit(e, 'draft')}
            className="rounded-lg border border-teal px-4 py-2 text-teal"
          >
            حفظ مسودة
          </button>
          <button
            type="button"
            onClick={(e) => onSubmit(e, 'posted')}
            className="rounded-lg bg-teal px-4 py-2 text-white hover:bg-teal-dark"
          >
            حفظ وترحيل
          </button>
        </div>
      </form>

      <div className="overflow-hidden rounded-2xl border border-black/5 bg-white shadow-sm">
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
              <tr key={entry.id} className="border-t border-black/5">
                <td className="px-4 py-3 font-mono text-xs">{entry.entry_number}</td>
                <td className="px-4 py-3">{String(entry.entry_date).slice(0, 10)}</td>
                <td className="px-4 py-3">{entry.description}</td>
                <td className="px-4 py-3">{statusLabel[entry.status]}</td>
                <td className="px-4 py-3">
                  {entry.status === 'draft' && (
                    <button
                      type="button"
                      onClick={() => postMutation.mutate(entry.id)}
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
      </div>
    </div>
  )
}
