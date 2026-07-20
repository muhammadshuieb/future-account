import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Button, Field, Modal, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

const emptyEmp = { employee_number: '', name: '', job_title: '', basic_salary: '0' }
const emptyAtt = { employee_id: '', attendance_date: new Date().toISOString().slice(0, 10), status: 'present', check_in: '09:00', check_out: '17:00' }
const emptyLeave = { employee_id: '', from_date: new Date().toISOString().slice(0, 10), to_date: new Date().toISOString().slice(0, 10), leave_type: 'annual', reason: '' }
const emptySal = { employee_id: '', period: new Date().toISOString().slice(0, 7), allowances: '0', deductions: '0', status: 'posted' }

export default function HrPage() {
  const [tab, setTab] = useState('employees')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [viewRow, setViewRow] = useState<Record<string, unknown> | null>(null)

  const employees = useQuery({ queryKey: ['employees'], queryFn: async () => (await api.get('/employees')).data.data })
  const attendances = useQuery({ queryKey: ['attendances'], queryFn: async () => (await api.get('/attendances')).data.data, enabled: tab === 'attendance' })
  const leaves = useQuery({ queryKey: ['leave-requests'], queryFn: async () => (await api.get('/leave-requests')).data.data, enabled: tab === 'leaves' })
  const salaries = useQuery({ queryKey: ['salary-records'], queryFn: async () => (await api.get('/salary-records')).data.data, enabled: tab === 'salaries' })

  const [emp, setEmp] = useState(emptyEmp)
  const [att, setAtt] = useState(emptyAtt)
  const [leave, setLeave] = useState(emptyLeave)
  const [sal, setSal] = useState(emptySal)

  function closeModal() {
    setModalOpen(false)
    setEditingId(null)
    setViewRow(null)
  }

  function openCreate() {
    setEditingId(null)
    setViewRow(null)
    if (tab === 'employees') setEmp(emptyEmp)
    if (tab === 'attendance') setAtt(emptyAtt)
    if (tab === 'leaves') setLeave(emptyLeave)
    if (tab === 'salaries') setSal(emptySal)
    setModalOpen(true)
  }

  const saveEmp = useMutation({
    mutationFn: () => {
      const payload = { ...emp, basic_salary: Number(emp.basic_salary), is_active: true }
      if (editingId) return api.put(`/employees/${editingId}`, payload)
      return api.post('/employees', payload)
    },
    onSuccess: () => { msg.setMessage('تم حفظ الموظف'); closeModal(); void qc.invalidateQueries({ queryKey: ['employees'] }) },
    onError: msg.fromErr,
  })
  const saveAtt = useMutation({
    mutationFn: () => api.post('/attendances', { ...att, employee_id: Number(att.employee_id) }),
    onSuccess: () => { msg.setMessage('تم تسجيل الحضور'); closeModal(); void qc.invalidateQueries({ queryKey: ['attendances'] }) },
    onError: msg.fromErr,
  })
  const saveLeave = useMutation({
    mutationFn: () => {
      const payload = { ...leave, employee_id: Number(leave.employee_id), status: 'pending' }
      if (editingId) return api.put(`/leave-requests/${editingId}`, payload)
      return api.post('/leave-requests', payload)
    },
    onSuccess: () => { msg.setMessage('تم طلب الإجازة'); closeModal(); void qc.invalidateQueries({ queryKey: ['leave-requests'] }) },
    onError: msg.fromErr,
  })
  const saveSal = useMutation({
    mutationFn: () => api.post('/salary-records', { ...sal, employee_id: Number(sal.employee_id), allowances: Number(sal.allowances), deductions: Number(sal.deductions) }),
    onSuccess: () => { msg.setMessage('تم ترحيل الراتب'); closeModal(); void qc.invalidateQueries({ queryKey: ['salary-records'] }) },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-6">
      <PageHeader
        title="الموارد البشرية"
        subtitle="موظفون، حضور، إجازات، وسجلات رواتب"
        actions={<Button variant="primary" onClick={openCreate}>إضافة</Button>}
      />
      <Tabs
        tabs={[
          { id: 'employees', label: 'الموظفون' },
          { id: 'attendance', label: 'الحضور' },
          { id: 'leaves', label: 'الإجازات' },
          { id: 'salaries', label: 'الرواتب' },
        ]}
        active={tab}
        onChange={(id) => { setTab(id); closeModal() }}
      />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'employees' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">الاسم</th><th className="px-4 py-3">المسمى</th><th className="px-4 py-3">الراتب</th></tr>
            </thead>
            <tbody>
              {(employees.data || []).map((e: { id: number; employee_number: string; name: string; job_title?: string; basic_salary: number }) => (
                <tr
                  key={e.id}
                  className="row-clickable border-t border-black/5"
                  onClick={() => {
                    setEditingId(e.id)
                    setEmp({
                      employee_number: e.employee_number,
                      name: e.name,
                      job_title: e.job_title || '',
                      basic_salary: String(e.basic_salary),
                    })
                    setModalOpen(true)
                  }}
                  tabIndex={0}
                >
                  <td className="px-4 py-3 font-mono">{e.employee_number}</td>
                  <td className="px-4 py-3">{e.name}</td>
                  <td className="px-4 py-3">{e.job_title || '—'}</td>
                  <td className="px-4 py-3">{e.basic_salary}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'attendance' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">موظف</th><th className="px-4 py-3">تاريخ</th><th className="px-4 py-3">حالة</th></tr>
            </thead>
            <tbody>
              {(attendances.data || []).map((a: { id: number; attendance_date: string; status: string; employee?: { name: string } }) => (
                <tr
                  key={a.id}
                  className="row-clickable border-t border-black/5"
                  onClick={() => { setViewRow(a as unknown as Record<string, unknown>); setModalOpen(true) }}
                  tabIndex={0}
                >
                  <td className="px-4 py-3">{a.employee?.name}</td>
                  <td className="px-4 py-3">{a.attendance_date}</td>
                  <td className="px-4 py-3">{a.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'leaves' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">موظف</th><th className="px-4 py-3">من</th><th className="px-4 py-3">إلى</th><th className="px-4 py-3">حالة</th></tr>
            </thead>
            <tbody>
              {(leaves.data || []).map((l: { id: number; employee_id: number; from_date: string; to_date: string; leave_type?: string; reason?: string; status: string; employee?: { name: string } }) => (
                <tr
                  key={l.id}
                  className="row-clickable border-t border-black/5"
                  onClick={() => {
                    setEditingId(l.id)
                    setLeave({
                      employee_id: String(l.employee_id),
                      from_date: String(l.from_date).slice(0, 10),
                      to_date: String(l.to_date).slice(0, 10),
                      leave_type: l.leave_type || 'annual',
                      reason: l.reason || '',
                    })
                    setModalOpen(true)
                  }}
                  tabIndex={0}
                >
                  <td className="px-4 py-3">{l.employee?.name}</td>
                  <td className="px-4 py-3">{l.from_date}</td>
                  <td className="px-4 py-3">{l.to_date}</td>
                  <td className="px-4 py-3">{l.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {tab === 'salaries' && (
        <Panel>
          <table className="w-full text-sm">
            <thead className="bg-mist text-right text-black/60">
              <tr><th className="px-4 py-3">موظف</th><th className="px-4 py-3">فترة</th><th className="px-4 py-3">صافي</th><th className="px-4 py-3">حالة</th></tr>
            </thead>
            <tbody>
              {(salaries.data || []).map((s: { id: number; period: string; net_salary: number; status: string; employee?: { name: string } }) => (
                <tr
                  key={s.id}
                  className="row-clickable border-t border-black/5"
                  onClick={() => { setViewRow(s as unknown as Record<string, unknown>); setModalOpen(true) }}
                  tabIndex={0}
                >
                  <td className="px-4 py-3">{s.employee?.name}</td>
                  <td className="px-4 py-3">{s.period}</td>
                  <td className="px-4 py-3">{s.net_salary}</td>
                  <td className="px-4 py-3">{s.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      <Modal
        open={modalOpen && tab === 'employees'}
        onClose={closeModal}
        title={editingId ? 'تعديل موظف' : 'موظف جديد'}
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveEmp.isPending} onClick={() => saveEmp.mutate()}>حفظ</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="الرقم الوظيفي"><input className={inputClass} value={emp.employee_number} onChange={(e) => setEmp({ ...emp, employee_number: e.target.value })} required /></Field>
          <Field label="الاسم"><input className={inputClass} value={emp.name} onChange={(e) => setEmp({ ...emp, name: e.target.value })} required /></Field>
          <Field label="المسمى"><input className={inputClass} value={emp.job_title} onChange={(e) => setEmp({ ...emp, job_title: e.target.value })} /></Field>
          <Field label="الراتب الأساسي"><input className={inputClass} value={emp.basic_salary} onChange={(e) => setEmp({ ...emp, basic_salary: e.target.value })} /></Field>
        </div>
      </Modal>

      <Modal
        open={modalOpen && tab === 'attendance' && !viewRow}
        onClose={closeModal}
        title="تسجيل حضور"
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveAtt.isPending} onClick={() => saveAtt.mutate()}>حفظ</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="موظف"><select className={inputClass} value={att.employee_id} onChange={(e) => setAtt({ ...att, employee_id: e.target.value })} required><option value="">—</option>{(employees.data || []).map((e: { id: number; name: string }) => <option key={e.id} value={e.id}>{e.name}</option>)}</select></Field>
          <Field label="تاريخ"><input type="date" className={inputClass} value={att.attendance_date} onChange={(e) => setAtt({ ...att, attendance_date: e.target.value })} /></Field>
          <Field label="حالة"><select className={inputClass} value={att.status} onChange={(e) => setAtt({ ...att, status: e.target.value })}><option value="present">حاضر</option><option value="absent">غائب</option><option value="leave">إجازة</option></select></Field>
        </div>
      </Modal>

      <Modal
        open={modalOpen && tab === 'attendance' && !!viewRow}
        onClose={closeModal}
        title="عرض حضور"
        footer={<Button variant="secondary" onClick={closeModal}>إغلاق</Button>}
      >
        {viewRow && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-black/50">موظف</dt><dd>{(viewRow.employee as { name?: string } | undefined)?.name}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">تاريخ</dt><dd>{String(viewRow.attendance_date)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">حالة</dt><dd>{String(viewRow.status)}</dd></div>
          </dl>
        )}
      </Modal>

      <Modal
        open={modalOpen && tab === 'leaves'}
        onClose={closeModal}
        title={editingId ? 'تعديل إجازة' : 'طلب إجازة'}
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveLeave.isPending} onClick={() => saveLeave.mutate()}>حفظ</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="موظف"><select className={inputClass} value={leave.employee_id} onChange={(e) => setLeave({ ...leave, employee_id: e.target.value })} required><option value="">—</option>{(employees.data || []).map((e: { id: number; name: string }) => <option key={e.id} value={e.id}>{e.name}</option>)}</select></Field>
          <Field label="من"><input type="date" className={inputClass} value={leave.from_date} onChange={(e) => setLeave({ ...leave, from_date: e.target.value })} /></Field>
          <Field label="إلى"><input type="date" className={inputClass} value={leave.to_date} onChange={(e) => setLeave({ ...leave, to_date: e.target.value })} /></Field>
          <Field label="السبب"><input className={inputClass} value={leave.reason} onChange={(e) => setLeave({ ...leave, reason: e.target.value })} /></Field>
        </div>
      </Modal>

      <Modal
        open={modalOpen && tab === 'salaries' && !viewRow}
        onClose={closeModal}
        title="تسجيل راتب"
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>إلغاء</Button>
            <Button variant="primary" disabled={saveSal.isPending} onClick={() => saveSal.mutate()}>ترحيل الراتب</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="موظف"><select className={inputClass} value={sal.employee_id} onChange={(e) => setSal({ ...sal, employee_id: e.target.value })} required><option value="">—</option>{(employees.data || []).map((e: { id: number; name: string }) => <option key={e.id} value={e.id}>{e.name}</option>)}</select></Field>
          <Field label="الفترة (YYYY-MM)"><input className={inputClass} value={sal.period} onChange={(e) => setSal({ ...sal, period: e.target.value })} /></Field>
          <Field label="بدلات"><input className={inputClass} value={sal.allowances} onChange={(e) => setSal({ ...sal, allowances: e.target.value })} /></Field>
          <Field label="خصومات"><input className={inputClass} value={sal.deductions} onChange={(e) => setSal({ ...sal, deductions: e.target.value })} /></Field>
        </div>
      </Modal>

      <Modal
        open={modalOpen && tab === 'salaries' && !!viewRow}
        onClose={closeModal}
        title="عرض راتب"
        footer={<Button variant="secondary" onClick={closeModal}>إغلاق</Button>}
      >
        {viewRow && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-black/50">موظف</dt><dd>{(viewRow.employee as { name?: string } | undefined)?.name}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">فترة</dt><dd>{String(viewRow.period)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">صافي</dt><dd>{String(viewRow.net_salary)}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-black/50">حالة</dt><dd>{String(viewRow.status)}</dd></div>
          </dl>
        )}
      </Modal>
    </div>
  )
}
