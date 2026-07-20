import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Button, Field, Modal, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

const emptyBox = { code: '', name: '', opening_balance: '0' }
const emptyBank = { code: '', name: '', account_number: '', opening_balance: '0' }
const emptyTr = {
  transfer_date: new Date().toISOString().slice(0, 10),
  from_type: 'cash_box',
  from_id: '',
  to_type: 'bank',
  to_id: '',
  amount: '',
  status: 'posted',
}
const emptyRec = {
  bank_id: '',
  statement_date: new Date().toISOString().slice(0, 10),
  statement_balance: '',
}

export default function CashBanksPage() {
  const [tab, setTab] = useState('boxes')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [viewRow, setViewRow] = useState<Record<string, unknown> | null>(null)

  const boxes = useQuery({ queryKey: ['cash-boxes'], queryFn: async () => (await api.get('/cash-boxes')).data.data })
  const banks = useQuery({ queryKey: ['banks'], queryFn: async () => (await api.get('/banks')).data.data })
  const transfers = useQuery({ queryKey: ['cash-transfers'], queryFn: async () => (await api.get('/cash-transfers')).data.data, enabled: tab === 'transfers' })
  const reconciliations = useQuery({ queryKey: ['bank-reconciliations'], queryFn: async () => (await api.get('/bank-reconciliations')).data.data, enabled: tab === 'reconcile' })

  const [boxForm, setBoxForm] = useState(emptyBox)
  const [bankForm, setBankForm] = useState(emptyBank)
  const [trForm, setTrForm] = useState(emptyTr)
  const [recForm, setRecForm] = useState(emptyRec)

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
    if (tab === 'reconcile') setRecForm(emptyRec)
    setModalOpen(true)
  }

  const saveBox = useMutation({
    mutationFn: () => {
      const payload = { ...boxForm, opening_balance: Number(boxForm.opening_balance), is_active: true }
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
  const saveRec = useMutation({
    mutationFn: () => api.post('/bank-reconciliations', { ...recForm, bank_id: Number(recForm.bank_id), statement_balance: Number(recForm.statement_balance) }),
    onSuccess: () => { msg.setMessage('تم إنشاء التسوية'); closeModal(); void qc.invalidateQueries({ queryKey: ['bank-reconciliations'] }) },
    onError: msg.fromErr,
  })

  const addLabel =
    tab === 'boxes' ? 'صندوق جديد'
      : tab === 'banks' ? 'حساب بنكي'
        : tab === 'transfers' ? 'تحويل'
          : 'تسوية بنكية'

  return (
    <div className="space-y-6">
      <PageHeader
        title="الصناديق والبنوك"
        subtitle="صناديق نقدية، حسابات بنكية، تحويلات، وتسوية كشف حساب"
        actions={<Button variant="primary" onClick={openCreate}>إضافة</Button>}
      />
      <Tabs
        tabs={[
          { id: 'boxes', label: 'الصناديق' },
          { id: 'banks', label: 'البنوك' },
          { id: 'transfers', label: 'التحويلات' },
          { id: 'reconcile', label: 'التسويات' },
        ]}
        active={tab}
        onChange={(id) => { setTab(id); closeModal() }}
      />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'boxes' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">رمز</th><th className="px-4 py-3">اسم</th><th className="px-4 py-3">افتتاحي</th></tr>
            </thead>
            <tbody>
              {(boxes.data || []).map((b: { id: number; code: string; name: string; opening_balance: number }) => (
                <tr
                  key={b.id}
                  className="row-clickable border-t border-black/5"
                  onClick={() => {
                    setEditingId(b.id)
                    setBoxForm({ code: b.code, name: b.name, opening_balance: String(b.opening_balance) })
                    setModalOpen(true)
                  }}
                  tabIndex={0}
                >
                  <td className="px-4 py-3 font-mono">{b.code}</td>
                  <td className="px-4 py-3">{b.name}</td>
                  <td className="px-4 py-3">{b.opening_balance}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'banks' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">رمز</th><th className="px-4 py-3">اسم</th><th className="px-4 py-3">رقم الحساب</th></tr>
            </thead>
            <tbody>
              {(banks.data || []).map((b: { id: number; code: string; name: string; account_number?: string; opening_balance?: number }) => (
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
        </Panel>
      )}

      {tab === 'transfers' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">من → إلى</th><th className="px-4 py-3">مبلغ</th><th className="px-4 py-3">حالة</th></tr>
            </thead>
            <tbody>
              {(transfers.data || []).map((t: { id: number; transfer_number: string; from_type: string; to_type: string; amount: number; status: string }) => (
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
        </Panel>
      )}

      {tab === 'reconcile' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">بنك</th><th className="px-4 py-3">كشف</th><th className="px-4 py-3">دفاتر</th><th className="px-4 py-3">فرق</th></tr>
            </thead>
            <tbody>
              {(reconciliations.data || []).map((r: { id: number; statement_balance: number; book_balance: number; difference: number; bank?: { name: string } }) => (
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
          <Field label="رصيد افتتاحي"><input className={inputClass} value={boxForm.opening_balance} onChange={(e) => setBoxForm({ ...boxForm, opening_balance: e.target.value })} /></Field>
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
          <Field label="رصيد افتتاحي"><input className={inputClass} value={bankForm.opening_balance} onChange={(e) => setBankForm({ ...bankForm, opening_balance: e.target.value })} /></Field>
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
          <Field label="من نوع"><select className={inputClass} value={trForm.from_type} onChange={(e) => setTrForm({ ...trForm, from_type: e.target.value })}><option value="cash_box">صندوق</option><option value="bank">بنك</option></select></Field>
          <Field label="من معرف"><select className={inputClass} value={trForm.from_id} onChange={(e) => setTrForm({ ...trForm, from_id: e.target.value })} required><option value="">—</option>{(trForm.from_type === 'cash_box' ? boxes.data : banks.data || []).map((x: { id: number; name: string }) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
          <Field label="إلى نوع"><select className={inputClass} value={trForm.to_type} onChange={(e) => setTrForm({ ...trForm, to_type: e.target.value })}><option value="cash_box">صندوق</option><option value="bank">بنك</option></select></Field>
          <Field label="إلى معرف"><select className={inputClass} value={trForm.to_id} onChange={(e) => setTrForm({ ...trForm, to_id: e.target.value })} required><option value="">—</option>{(trForm.to_type === 'cash_box' ? boxes.data : banks.data || []).map((x: { id: number; name: string }) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
          <Field label="المبلغ"><input className={inputClass} value={trForm.amount} onChange={(e) => setTrForm({ ...trForm, amount: e.target.value })} required /></Field>
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
          <Field label="بنك"><select className={inputClass} value={recForm.bank_id} onChange={(e) => setRecForm({ ...recForm, bank_id: e.target.value })} required><option value="">—</option>{(banks.data || []).map((b: { id: number; name: string }) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></Field>
          <Field label="تاريخ الكشف"><input type="date" className={inputClass} value={recForm.statement_date} onChange={(e) => setRecForm({ ...recForm, statement_date: e.target.value })} /></Field>
          <Field label="رصيد الكشف"><input className={inputClass} value={recForm.statement_balance} onChange={(e) => setRecForm({ ...recForm, statement_balance: e.target.value })} required /></Field>
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
