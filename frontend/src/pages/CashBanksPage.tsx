import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { todayYmd } from '@/lib/dates'
import {
  Button,
  EmptyState,
  Field,
  LoadingBlock,
  Modal,
  Msg,
  NumericInput,
  PageHeader,
  Panel,
  Tabs,
  formatMoney,
  inputClass,
  useFormMessage,
} from '@/components/ui'
import type { CurrencyOption } from '@/components/CurrencyFields'

type CashBox = {
  id: number
  code: string
  name: string
  opening_balance: number
  currency?: string
  balance?: number
}
type Bank = { id: number; code: string; name: string; account_number?: string; opening_balance?: number; currency?: string }
type Transfer = { id: number; transfer_number: string; from_type: string; to_type: string; amount: number; status: string }
type CurrencyExchange = {
  id: number
  exchange_number: string
  exchange_date: string
  source_cash_box_id: number
  target_cash_box_id: number
  source_currency: string
  target_currency: string
  source_amount: number
  target_amount: number
  exchange_rate: number
  status: string
  notes?: string | null
  source_cash_box?: CashBox
  target_cash_box?: CashBox
}
type Reconciliation = {
  id: number
  statement_balance: number
  book_balance: number
  difference: number
  bank?: { name: string }
}

const emptyBox = { code: '', name: '', opening_balance: '0', currency: 'SYP' }
const emptyBank = { code: '', name: '', account_number: '', opening_balance: '0' }
const emptyTr = {
  transfer_date: todayYmd(),
  from_type: 'cash_box',
  from_id: '',
  to_type: 'bank',
  to_id: '',
  amount: '',
  status: 'posted',
}
const emptyEx = {
  exchange_date: todayYmd(),
  source_currency: 'SYP',
  target_currency: 'USD',
  source_cash_box_id: '',
  target_cash_box_id: '',
  source_amount: '',
  target_amount: '',
  exchange_rate: '',
  notes: '',
  status: 'posted',
}
const emptyRec = {
  bank_id: '',
  statement_date: todayYmd(),
  statement_balance: '',
}

function listOrEmpty<T>(data: T[] | undefined): T[] {
  return Array.isArray(data) ? data : []
}

function round2(n: number) {
  return Math.round(n * 100) / 100
}

function round8(n: number) {
  return Math.round(n * 1e8) / 1e8
}

function fmtLatn(n: number) {
  return n.toLocaleString('ar-SY-u-nu-latn', { maximumFractionDigits: 8 })
}

function rateToBase(code: string, currencies: CurrencyOption[], base: string) {
  if (code === base) return 1
  const row = currencies.find((c) => c.code === code)
  return row?.rate_to_base && row.rate_to_base > 0 ? row.rate_to_base : 0
}

/** 1 target = N source */
function sourcePerTarget(sourceCur: string, targetCur: string, currencies: CurrencyOption[], base: string) {
  const s = rateToBase(sourceCur, currencies, base)
  const t = rateToBase(targetCur, currencies, base)
  if (!s || !t) return 0
  return round8(t / s)
}

export default function CashBanksPage() {
  const [tab, setTab] = useState('boxes')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [viewRow, setViewRow] = useState<Record<string, unknown> | null>(null)

  const [boxForm, setBoxForm] = useState(emptyBox)
  const [bankForm, setBankForm] = useState(emptyBank)
  const [trForm, setTrForm] = useState(emptyTr)
  const [exForm, setExForm] = useState(emptyEx)
  const [recForm, setRecForm] = useState(emptyRec)

  const boxes = useQuery({
    queryKey: ['cash-boxes'],
    queryFn: async () => (await api.get('/cash-boxes')).data.data as CashBox[],
  })
  const banks = useQuery({
    queryKey: ['banks'],
    queryFn: async () => (await api.get('/banks')).data.data as Bank[],
  })
  const currencies = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api.get('/currencies')).data.data as CurrencyOption[],
  })
  const transfers = useQuery({
    queryKey: ['cash-transfers'],
    queryFn: async () => (await api.get('/cash-transfers')).data.data as Transfer[],
    enabled: tab === 'transfers',
  })
  const exchanges = useQuery({
    queryKey: ['currency-exchanges'],
    queryFn: async () => (await api.get('/currency-exchanges')).data.data as CurrencyExchange[],
    enabled: tab === 'exchange',
  })
  const reconciliations = useQuery({
    queryKey: ['bank-reconciliations'],
    queryFn: async () => (await api.get('/bank-reconciliations')).data.data as Reconciliation[],
    enabled: tab === 'reconcile',
  })

  const boxRows = listOrEmpty(boxes.data)
  const bankRows = listOrEmpty(banks.data)
  const transferRows = listOrEmpty(transfers.data)
  const exchangeRows = listOrEmpty(exchanges.data)
  const reconcileRows = listOrEmpty(reconciliations.data)
  const currencyList = listOrEmpty(currencies.data)
  const baseCurrency = 'SYP'
  const fromList = trForm.from_type === 'cash_box' ? boxRows : bankRows
  const toList = trForm.to_type === 'cash_box' ? boxRows : bankRows

  const sourceCur = (exForm.source_currency || 'SYP').toUpperCase()
  const targetCur = (exForm.target_currency || 'USD').toUpperCase()
  const sourceBoxes = boxRows.filter((b) => (b.currency || 'SYP').toUpperCase() === sourceCur)
  const targetBoxes = boxRows.filter(
    (b) => (b.currency || 'SYP').toUpperCase() === targetCur && String(b.id) !== String(exForm.source_cash_box_id),
  )
  const currencyOptions = useMemo(() => {
    const active = currencyList.filter((c) => c.is_active)
    if (active.length) return active
    if (currencyList.length) return currencyList
    return [
      { id: 1, code: 'SYP', name: 'الليرة السورية', is_active: true, rate_to_base: 1 },
      { id: 2, code: 'TRY', name: 'الليرة التركية', is_active: true, rate_to_base: 0 },
      { id: 3, code: 'USD', name: 'الدولار الأمريكي', is_active: true, rate_to_base: 0 },
    ] as CurrencyOption[]
  }, [currencyList])

  // Keep selected boxes aligned with chosen currencies; prefill rate when pair changes.
  useEffect(() => {
    setExForm((prev) => {
      const next = { ...prev }
      let changed = false
      const srcOk = sourceBoxes.some((b) => String(b.id) === String(prev.source_cash_box_id))
      if (prev.source_cash_box_id && !srcOk) {
        next.source_cash_box_id = ''
        changed = true
      }
      if (!next.source_cash_box_id && sourceBoxes.length === 1) {
        next.source_cash_box_id = String(sourceBoxes[0].id)
        changed = true
      }
      const tgtOk = targetBoxes.some((b) => String(b.id) === String(prev.target_cash_box_id))
      if (prev.target_cash_box_id && !tgtOk) {
        next.target_cash_box_id = ''
        changed = true
      }
      if (!next.target_cash_box_id && targetBoxes.length === 1) {
        next.target_cash_box_id = String(targetBoxes[0].id)
        changed = true
      }
      if (sourceCur === targetCur) return changed ? next : prev
      const rate = sourcePerTarget(sourceCur, targetCur, currencyList, baseCurrency)
      if (rate > 0 && (!prev.exchange_rate || Number(prev.exchange_rate) <= 0)) {
        const sourceAmount = Number(next.source_amount) || 0
        next.exchange_rate = String(rate)
        if (sourceAmount > 0) next.target_amount = String(round2(sourceAmount / rate))
        changed = true
      }
      return changed ? next : prev
    })
  }, [sourceCur, targetCur, sourceBoxes.map((b) => b.id).join(','), targetBoxes.map((b) => b.id).join(','), currencyList])

  const fxPreview = useMemo(() => {
    const s = Number(exForm.source_amount) || 0
    const t = Number(exForm.target_amount) || 0
    if (s <= 0 || t <= 0) return null
    return `${fmtLatn(s)} ${sourceCur} = ${fmtLatn(t)} ${targetCur}`
  }, [exForm.source_amount, exForm.target_amount, sourceCur, targetCur])

  const activeQuery =
    tab === 'boxes' ? boxes
      : tab === 'banks' ? banks
        : tab === 'transfers' ? transfers
          : tab === 'exchange' ? exchanges
            : reconciliations

  function closeModal() {
    setModalOpen(false)
    setEditingId(null)
    setViewRow(null)
  }

  function openCreate() {
    setEditingId(null)
    setViewRow(null)
    if (tab === 'boxes') setBoxForm(emptyBox)
    if (tab === 'banks') setBankForm(emptyBank)
    if (tab === 'transfers') setTrForm(emptyTr)
    if (tab === 'exchange') setExForm(emptyEx)
    if (tab === 'reconcile') setRecForm(emptyRec)
    setModalOpen(true)
  }

  const saveBox = useMutation({
    mutationFn: () => {
      const payload = {
        ...boxForm,
        opening_balance: Number(boxForm.opening_balance),
        currency: boxForm.currency || 'SYP',
        is_active: true,
      }
      if (editingId) return api.put(`/cash-boxes/${editingId}`, payload)
      return api.post('/cash-boxes', payload)
    },
    onSuccess: () => { msg.setMessage('تم حفظ الصندوق'); closeModal(); void qc.invalidateQueries({ queryKey: ['cash-boxes'] }) },
    onError: msg.fromErr,
  })
  const saveBank = useMutation({
    mutationFn: () => {
      const payload = { ...bankForm, opening_balance: Number(bankForm.opening_balance), currency: 'SAR', is_active: true }
      if (editingId) return api.put(`/banks/${editingId}`, payload)
      return api.post('/banks', payload)
    },
    onSuccess: () => { msg.setMessage('تم حفظ الحساب البنكي'); closeModal(); void qc.invalidateQueries({ queryKey: ['banks'] }) },
    onError: msg.fromErr,
  })
  const saveTr = useMutation({
    mutationFn: () => api.post('/cash-transfers', { ...trForm, from_id: Number(trForm.from_id), to_id: Number(trForm.to_id), amount: Number(trForm.amount) }),
    onSuccess: () => { msg.setMessage('تم ترحيل التحويل'); closeModal(); void qc.invalidateQueries({ queryKey: ['cash-transfers'] }) },
    onError: msg.fromErr,
  })
  const saveEx = useMutation({
    mutationFn: () => api.post('/currency-exchanges', {
      exchange_date: exForm.exchange_date,
      source_cash_box_id: Number(exForm.source_cash_box_id),
      target_cash_box_id: Number(exForm.target_cash_box_id),
      source_currency: sourceCur,
      target_currency: targetCur,
      from_currency: sourceCur,
      to_currency: targetCur,
      source_amount: Number(exForm.source_amount),
      target_amount: Number(exForm.target_amount),
      exchange_rate: Number(exForm.exchange_rate),
      notes: exForm.notes || null,
      status: exForm.status,
    }),
    onSuccess: () => {
      msg.setMessage('تم ترحيل صرف العملة')
      closeModal()
      void qc.invalidateQueries({ queryKey: ['currency-exchanges'] })
      void qc.invalidateQueries({ queryKey: ['cash-boxes'] })
    },
    onError: msg.fromErr,
  })
  const saveRec = useMutation({
    mutationFn: () => api.post('/bank-reconciliations', { ...recForm, bank_id: Number(recForm.bank_id), statement_balance: Number(recForm.statement_balance) }),
    onSuccess: () => { msg.setMessage('تم إنشاء التسوية'); closeModal(); void qc.invalidateQueries({ queryKey: ['bank-reconciliations'] }) },
    onError: msg.fromErr,
  })

  const addLabel =
    tab === 'boxes' ? 'صندوق جديد'
      : tab === 'banks' ? 'حساب بنكي'
        : tab === 'transfers' ? 'تحويل'
          : tab === 'exchange' ? 'صرف عملة'
            : 'تسوية بنكية'

  const emptyCopy: Record<string, { title: string; description: string }> = {
    boxes: { title: 'لا توجد صناديق', description: 'أضف صندوقاً نقدياً للبدء.' },
    banks: { title: 'لا توجد حسابات بنكية', description: 'أضف حساباً بنكياً للبدء.' },
    transfers: { title: 'لا توجد تحويلات', description: 'أنشئ تحويلاً بين صندوق وبنك.' },
    exchange: { title: 'لا توجد عمليات صرف', description: 'سجّل صرف عملة بين صندوقين بعملتين مختلفتين.' },
    reconcile: { title: 'لا توجد تسويات', description: 'أنشئ تسوية كشف حساب بنكي.' },
  }

  function onSourceAmount(v: string) {
    const rate = Number(exForm.exchange_rate) || 0
    const source = Number(v) || 0
    const target = rate > 0 && source > 0 ? round2(source / rate) : Number(exForm.target_amount) || 0
    setExForm((prev) => ({
      ...prev,
      source_amount: v,
      target_amount: source > 0 && rate > 0 ? String(target) : prev.target_amount,
    }))
  }

  function onTargetAmount(v: string) {
    const source = Number(exForm.source_amount) || 0
    const target = Number(v) || 0
    const rate = source > 0 && target > 0 ? round8(source / target) : Number(exForm.exchange_rate) || 0
    setExForm((prev) => ({
      ...prev,
      target_amount: v,
      exchange_rate: source > 0 && target > 0 ? String(rate) : prev.exchange_rate,
    }))
  }

  function onExchangeRate(v: string) {
    const rate = Number(v) || 0
    const source = Number(exForm.source_amount) || 0
    const target = rate > 0 && source > 0 ? round2(source / rate) : Number(exForm.target_amount) || 0
    setExForm((prev) => ({
      ...prev,
      exchange_rate: v,
      target_amount: source > 0 && rate > 0 ? String(target) : prev.target_amount,
    }))
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="الصناديق والبنوك"
        subtitle="صناديق نقدية، حسابات بنكية، تحويلات، صرف عملة، وتسوية كشف حساب"
        actions={<Button variant="primary" onClick={openCreate}>إضافة</Button>}
      />
      <Tabs
        tabs={[
          { id: 'boxes', label: 'الصناديق' },
          { id: 'banks', label: 'البنوك' },
          { id: 'transfers', label: 'التحويلات' },
          { id: 'exchange', label: 'صرف عملة' },
          { id: 'reconcile', label: 'التسويات' },
        ]}
        active={tab}
        onChange={(id) => { setTab(id); closeModal() }}
      />
      <Msg message={msg.message} error={msg.error} />

      {activeQuery.isError && !(Array.isArray(activeQuery.data) && activeQuery.data.length > 0) && (
        <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-danger">
          تعذر تحميل البيانات — تحقق من صلاحية «الصناديق والبنوك» أو أعد المحاولة.
        </p>
      )}

      {tab === 'boxes' && (
        <Panel>
          {boxes.isLoading && <LoadingBlock />}
          {!boxes.isLoading && !boxes.isError && boxRows.length === 0 && (
            <EmptyState title={emptyCopy.boxes.title} description={emptyCopy.boxes.description} />
          )}
          {!boxes.isLoading && boxRows.length > 0 && (
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr>
                  <th className="px-4 py-3">رمز</th>
                  <th className="px-4 py-3">اسم</th>
                  <th className="px-4 py-3">عملة</th>
                  <th className="px-4 py-3">رصيد</th>
                  <th className="px-4 py-3">افتتاحي</th>
                </tr>
              </thead>
              <tbody>
                {boxRows.map((b) => (
                  <tr
                    key={b.id}
                    className="row-clickable border-t border-black/5"
                    onClick={() => {
                      setEditingId(b.id)
                      setBoxForm({
                        code: b.code,
                        name: b.name,
                        opening_balance: String(b.opening_balance),
                        currency: b.currency || 'SYP',
                      })
                      setModalOpen(true)
                    }}
                    tabIndex={0}
                  >
                    <td className="px-4 py-3 font-mono">{b.code}</td>
                    <td className="px-4 py-3">{b.name}</td>
                    <td className="px-4 py-3 font-mono">{b.currency || 'SYP'}</td>
                    <td className="px-4 py-3">{formatMoney(b.balance ?? b.opening_balance, b.currency || 'SYP')}</td>
                    <td className="px-4 py-3">{formatMoney(b.opening_balance, b.currency || 'SYP')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      )}

      {tab === 'banks' && (
        <Panel>
          {banks.isLoading && <LoadingBlock />}
          {!banks.isLoading && !banks.isError && bankRows.length === 0 && (
            <EmptyState title={emptyCopy.banks.title} description={emptyCopy.banks.description} />
          )}
          {!banks.isLoading && bankRows.length > 0 && (
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr><th className="px-4 py-3">رمز</th><th className="px-4 py-3">اسم</th><th className="px-4 py-3">رقم الحساب</th></tr>
              </thead>
              <tbody>
                {bankRows.map((b) => (
                  <tr
                    key={b.id}
                    className="row-clickable border-t border-black/5"
                    onClick={() => {
                      setEditingId(b.id)
                      setBankForm({
                        code: b.code,
                        name: b.name,
                        account_number: b.account_number || '',
                        opening_balance: String(b.opening_balance ?? 0),
                      })
                      setModalOpen(true)
                    }}
                    tabIndex={0}
                  >
                    <td className="px-4 py-3 font-mono">{b.code}</td>
                    <td className="px-4 py-3">{b.name}</td>
                    <td className="px-4 py-3">{b.account_number || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      )}

      {tab === 'transfers' && (
        <Panel>
          {transfers.isLoading && <LoadingBlock />}
          {!transfers.isLoading && !transfers.isError && transferRows.length === 0 && (
            <EmptyState title={emptyCopy.transfers.title} description={emptyCopy.transfers.description} />
          )}
          {!transfers.isLoading && transferRows.length > 0 && (
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">من → إلى</th><th className="px-4 py-3">مبلغ</th><th className="px-4 py-3">حالة</th></tr>
              </thead>
              <tbody>
                {transferRows.map((t) => (
                  <tr
                    key={t.id}
                    className="row-clickable border-t border-black/5"
                    onClick={() => { setViewRow(t as unknown as Record<string, unknown>); setModalOpen(true) }}
                    tabIndex={0}
                  >
                    <td className="px-4 py-3 font-mono text-xs">{t.transfer_number}</td>
                    <td className="px-4 py-3">{t.from_type} → {t.to_type}</td>
                    <td className="px-4 py-3">{t.amount}</td>
                    <td className="px-4 py-3">{t.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      )}

      {tab === 'exchange' && (
        <Panel>
          {exchanges.isLoading && <LoadingBlock />}
          {!exchanges.isLoading && !exchanges.isError && exchangeRows.length === 0 && (
            <EmptyState title={emptyCopy.exchange.title} description={emptyCopy.exchange.description} />
          )}
          {!exchanges.isLoading && exchangeRows.length > 0 && (
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr>
                  <th className="px-4 py-3">رقم</th>
                  <th className="px-4 py-3">من → إلى</th>
                  <th className="px-4 py-3">مبلغ أصلي</th>
                  <th className="px-4 py-3">ناتج</th>
                  <th className="px-4 py-3">سعر</th>
                  <th className="px-4 py-3">حالة</th>
                </tr>
              </thead>
              <tbody>
                {exchangeRows.map((row) => (
                  <tr
                    key={row.id}
                    className="row-clickable border-t border-black/5"
                    onClick={() => { setViewRow(row as unknown as Record<string, unknown>); setModalOpen(true) }}
                    tabIndex={0}
                  >
                    <td className="px-4 py-3 font-mono text-xs">{row.exchange_number}</td>
                    <td className="px-4 py-3">
                      {row.source_cash_box?.name || row.source_currency}
                      {' → '}
                      {row.target_cash_box?.name || row.target_currency}
                    </td>
                    <td className="px-4 py-3">{formatMoney(row.source_amount, row.source_currency)}</td>
                    <td className="px-4 py-3">{formatMoney(row.target_amount, row.target_currency)}</td>
                    <td className="px-4 py-3 font-mono text-xs">{fmtLatn(Number(row.exchange_rate))}</td>
                    <td className="px-4 py-3">{row.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      )}

      {tab === 'reconcile' && (
        <Panel>
          {reconciliations.isLoading && <LoadingBlock />}
          {!reconciliations.isLoading && !reconciliations.isError && reconcileRows.length === 0 && (
            <EmptyState title={emptyCopy.reconcile.title} description={emptyCopy.reconcile.description} />
          )}
          {!reconciliations.isLoading && reconcileRows.length > 0 && (
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr><th className="px-4 py-3">بنك</th><th className="px-4 py-3">كشف</th><th className="px-4 py-3">دفاتر</th><th className="px-4 py-3">فرق</th></tr>
              </thead>
              <tbody>
                {reconcileRows.map((r) => (
                  <tr
                    key={r.id}
                    className="row-clickable border-t border-black/5"
                    onClick={() => { setViewRow(r as unknown as Record<string, unknown>); setModalOpen(true) }}
                    tabIndex={0}
                  >
                    <td className="px-4 py-3">{r.bank?.name}</td>
                    <td className="px-4 py-3">{r.statement_balance}</td>
                    <td className="px-4 py-3">{r.book_balance}</td>
                    <td className="px-4 py-3">{r.difference}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      )}

      <Modal
        open={modalOpen && tab === 'boxes'}
        onClose={closeModal}
        title={editingId ? 'تعديل صندوق' : 'صندوق جديد'}
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveBox.isPending} onClick={() => saveBox.mutate()}>حفظ</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="رمز"><input className={inputClass} value={boxForm.code} onChange={(e) => setBoxForm({ ...boxForm, code: e.target.value })} required /></Field>
          <Field label="اسم"><input className={inputClass} value={boxForm.name} onChange={(e) => setBoxForm({ ...boxForm, name: e.target.value })} required /></Field>
          <Field label="العملة">
            <select className={inputClass} value={boxForm.currency} onChange={(e) => setBoxForm({ ...boxForm, currency: e.target.value })}>
              {(currencyList.length ? currencyList : [
                { id: 1, code: 'SYP', name: 'ليرة سورية', is_active: true },
                { id: 2, code: 'USD', name: 'دولار', is_active: true },
                { id: 3, code: 'TRY', name: 'ليرة تركية', is_active: true },
              ]).map((c) => (
                <option key={c.code} value={c.code}>{c.code} — {c.name}</option>
              ))}
            </select>
          </Field>
          <Field label="رصيد افتتاحي"><NumericInput value={boxForm.opening_balance} onChange={(v) => setBoxForm((prev) => ({ ...prev, opening_balance: v }))} /></Field>
        </div>
      </Modal>

      <Modal
        open={modalOpen && tab === 'banks'}
        onClose={closeModal}
        title={editingId ? 'تعديل حساب بنكي' : 'حساب بنكي'}
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveBank.isPending} onClick={() => saveBank.mutate()}>حفظ</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="رمز"><input className={inputClass} value={bankForm.code} onChange={(e) => setBankForm({ ...bankForm, code: e.target.value })} required /></Field>
          <Field label="اسم"><input className={inputClass} value={bankForm.name} onChange={(e) => setBankForm({ ...bankForm, name: e.target.value })} required /></Field>
          <Field label="رقم الحساب"><input className={inputClass} value={bankForm.account_number} onChange={(e) => setBankForm({ ...bankForm, account_number: e.target.value })} /></Field>
          <Field label="رصيد افتتاحي"><NumericInput value={bankForm.opening_balance} onChange={(v) => setBankForm((prev) => ({ ...prev, opening_balance: v }))} /></Field>
        </div>
      </Modal>

      <Modal
        open={modalOpen && tab === 'transfers' && !viewRow}
        onClose={closeModal}
        title={addLabel}
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveTr.isPending} onClick={() => saveTr.mutate()}>ترحيل</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="من نوع">
            <select className={inputClass} value={trForm.from_type} onChange={(e) => setTrForm({ ...trForm, from_type: e.target.value, from_id: '' })}>
              <option value="cash_box">صندوق</option>
              <option value="bank">بنك</option>
            </select>
          </Field>
          <Field label="من معرف">
            <select className={inputClass} value={trForm.from_id} onChange={(e) => setTrForm({ ...trForm, from_id: e.target.value })} required>
              <option value="">—</option>
              {fromList.map((x) => <option key={x.id} value={x.id}>{x.name}{'currency' in x && x.currency ? ` (${x.currency})` : ''}</option>)}
            </select>
          </Field>
          <Field label="إلى نوع">
            <select className={inputClass} value={trForm.to_type} onChange={(e) => setTrForm({ ...trForm, to_type: e.target.value, to_id: '' })}>
              <option value="cash_box">صندوق</option>
              <option value="bank">بنك</option>
            </select>
          </Field>
          <Field label="إلى معرف">
            <select className={inputClass} value={trForm.to_id} onChange={(e) => setTrForm({ ...trForm, to_id: e.target.value })} required>
              <option value="">—</option>
              {toList.map((x) => <option key={x.id} value={x.id}>{x.name}{'currency' in x && x.currency ? ` (${x.currency})` : ''}</option>)}
            </select>
          </Field>
          <Field label="المبلغ"><NumericInput value={trForm.amount} onChange={(v) => setTrForm((prev) => ({ ...prev, amount: v }))} required /></Field>
        </div>
      </Modal>

      <Modal
        open={modalOpen && tab === 'transfers' && !!viewRow}
        onClose={closeModal}
        title="عرض تحويل"
        footer={<Button variant="secondary" onClick={closeModal}>إغلاق</Button>}
      >
        {viewRow && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-black/50">رقم</dt><dd className="font-mono">{String(viewRow.transfer_number)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">من → إلى</dt><dd>{String(viewRow.from_type)} → {String(viewRow.to_type)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">مبلغ</dt><dd>{String(viewRow.amount)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">حالة</dt><dd>{String(viewRow.status)}</dd></div>
          </dl>
        )}
      </Modal>

      <Modal
        open={modalOpen && tab === 'exchange' && !viewRow}
        onClose={closeModal}
        title="صرف عملة"
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveEx.isPending} onClick={() => saveEx.mutate()}>ترحيل</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="التاريخ">
            <input type="date" className={inputClass} value={exForm.exchange_date} onChange={(e) => setExForm({ ...exForm, exchange_date: e.target.value })} />
          </Field>
          <div className="grid grid-cols-2 gap-2">
            <Field label="العملة المصرّف منها">
              <select
                className={inputClass}
                value={sourceCur}
                onChange={(e) => {
                  const nextFrom = e.target.value
                  const nextTo = nextFrom === targetCur
                    ? (currencyOptions.find((c) => c.code !== nextFrom)?.code || targetCur)
                    : targetCur
                  setExForm({
                    ...exForm,
                    source_currency: nextFrom,
                    target_currency: nextTo,
                    source_cash_box_id: '',
                    target_cash_box_id: nextTo !== targetCur ? '' : exForm.target_cash_box_id,
                    exchange_rate: '',
                  })
                }}
                required
              >
                {currencyOptions.map((c) => (
                  <option key={c.code} value={c.code}>{c.code} — {c.name}</option>
                ))}
              </select>
            </Field>
            <Field label="العملة المصرّف إليها">
              <select
                className={inputClass}
                value={targetCur}
                onChange={(e) => setExForm({
                  ...exForm,
                  target_currency: e.target.value,
                  target_cash_box_id: '',
                  exchange_rate: '',
                })}
                required
              >
                {currencyOptions
                  .filter((c) => c.code !== sourceCur)
                  .map((c) => (
                    <option key={c.code} value={c.code}>{c.code} — {c.name}</option>
                  ))}
              </select>
            </Field>
          </div>
          <Field label={`الصندوق المصدر (${sourceCur})`}>
            <select
              className={inputClass}
              value={exForm.source_cash_box_id}
              onChange={(e) => setExForm({ ...exForm, source_cash_box_id: e.target.value })}
              required
            >
              <option value="">—</option>
              {sourceBoxes.map((b) => (
                <option key={b.id} value={b.id}>{b.name}</option>
              ))}
            </select>
            {sourceBoxes.length === 0 && (
              <p className="mt-1 text-xs text-amber">لا يوجد صندوق بعملة {sourceCur}. أنشئ صندوقاً بهذه العملة أولاً.</p>
            )}
          </Field>
          <Field label={`المبلغ بالعملة الأصلية (${sourceCur})`}>
            <NumericInput value={exForm.source_amount} onChange={onSourceAmount} required />
          </Field>
          <Field label={`الصندوق الهدف (${targetCur})`}>
            <select
              className={inputClass}
              value={exForm.target_cash_box_id}
              onChange={(e) => setExForm({ ...exForm, target_cash_box_id: e.target.value })}
              required
            >
              <option value="">—</option>
              {targetBoxes.map((b) => (
                <option key={b.id} value={b.id}>{b.name}</option>
              ))}
            </select>
            {targetBoxes.length === 0 && (
              <p className="mt-1 text-xs text-amber">لا يوجد صندوق بعملة {targetCur}. أنشئ صندوقاً بهذه العملة أولاً.</p>
            )}
          </Field>
          <Field
            label={`سعر الصرف (1 ${targetCur} = ? ${sourceCur})`}
            hint={`كم ${sourceCur} مقابل وحدة واحدة من ${targetCur}`}
          >
            <NumericInput value={exForm.exchange_rate} onChange={onExchangeRate} required />
          </Field>
          <Field label={`المبلغ الناتج (${targetCur})`}>
            <NumericInput value={exForm.target_amount} onChange={onTargetAmount} required />
          </Field>
          {fxPreview && (
            <p className="rounded-md bg-black/[0.03] px-2.5 py-2 text-xs text-black/65">{fxPreview}</p>
          )}
          <Field label="ملاحظات">
            <input className={inputClass} value={exForm.notes} onChange={(e) => setExForm({ ...exForm, notes: e.target.value })} placeholder="اسم الصراف مثلاً" />
          </Field>
        </div>
      </Modal>

      <Modal
        open={modalOpen && tab === 'exchange' && !!viewRow}
        onClose={closeModal}
        title="عرض صرف عملة"
        footer={
          <>
            <Button variant="secondary" onClick={() => window.print()}>طباعة</Button>
            <Button variant="secondary" onClick={closeModal}>إغلاق</Button>
          </>
        }
      >
        {viewRow && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-black/50">رقم</dt><dd className="font-mono">{String(viewRow.exchange_number)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">التاريخ</dt><dd>{String(viewRow.exchange_date || '').slice(0, 10)}</dd></div>
            <div className="flex justify-between gap-4">
              <dt className="text-black/50">من العملة</dt>
              <dd className="font-mono">{String(viewRow.source_currency)}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-black/50">إلى العملة</dt>
              <dd className="font-mono">{String(viewRow.target_currency)}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-black/50">من</dt>
              <dd>{(viewRow.source_cash_box as CashBox | undefined)?.name || '—'} ({String(viewRow.source_currency)})</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-black/50">إلى</dt>
              <dd>{(viewRow.target_cash_box as CashBox | undefined)?.name || '—'} ({String(viewRow.target_currency)})</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-black/50">المبلغ الأصلي</dt>
              <dd>{formatMoney(viewRow.source_amount as number, String(viewRow.source_currency))}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-black/50">المبلغ الناتج</dt>
              <dd>{formatMoney(viewRow.target_amount as number, String(viewRow.target_currency))}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-black/50">سعر الصرف</dt>
              <dd className="font-mono">{fmtLatn(Number(viewRow.exchange_rate))}</dd>
            </div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">حالة</dt><dd>{String(viewRow.status)}</dd></div>
            {viewRow.notes ? (
              <div className="flex justify-between gap-4"><dt className="text-black/50">ملاحظات</dt><dd>{String(viewRow.notes)}</dd></div>
            ) : null}
          </dl>
        )}
      </Modal>

      <Modal
        open={modalOpen && tab === 'reconcile' && !viewRow}
        onClose={closeModal}
        title="تسوية بنكية"
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveRec.isPending} onClick={() => saveRec.mutate()}>حفظ التسوية</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="بنك">
            <select className={inputClass} value={recForm.bank_id} onChange={(e) => setRecForm({ ...recForm, bank_id: e.target.value })} required>
              <option value="">—</option>
              {bankRows.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </Field>
          <Field label="تاريخ الكشف"><input type="date" className={inputClass} value={recForm.statement_date} onChange={(e) => setRecForm({ ...recForm, statement_date: e.target.value })} /></Field>
          <Field label="رصيد الكشف"><NumericInput value={recForm.statement_balance} onChange={(v) => setRecForm((prev) => ({ ...prev, statement_balance: v }))} required /></Field>
        </div>
      </Modal>

      <Modal
        open={modalOpen && tab === 'reconcile' && !!viewRow}
        onClose={closeModal}
        title="عرض تسوية"
        footer={<Button variant="secondary" onClick={closeModal}>إغلاق</Button>}
      >
        {viewRow && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-black/50">بنك</dt><dd>{(viewRow.bank as { name?: string } | undefined)?.name || '—'}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">كشف</dt><dd>{String(viewRow.statement_balance)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">دفاتر</dt><dd>{String(viewRow.book_balance)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">فرق</dt><dd>{String(viewRow.difference)}</dd></div>
          </dl>
        )}
      </Modal>
    </div>
  )
}
