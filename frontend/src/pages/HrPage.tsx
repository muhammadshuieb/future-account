import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Field, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

export default function HrPage() {
  const [tab, setTab] = useState('employees')
  const qc = useQueryClient()
  const msg = useFormMessage()

  const employees = useQuery({ queryKey: ['employees'], queryFn: async () => (await api.get('/employees')).data.data })
  const attendances = useQuery({ queryKey: ['attendances'], queryFn: async () => (await api.get('/attendances')).data.data, enabled: tab === 'attendance' })
  const leaves = useQuery({ queryKey: ['leave-requests'], queryFn: async () => (await api.get('/leave-requests')).data.data, enabled: tab === 'leaves' })
  const salaries = useQuery({ queryKey: ['salary-records'], queryFn: async () => (await api.get('/salary-records')).data.data, enabled: tab === 'salaries' })

  const [emp, setEmp] = useState({ employee_number: '', name: '', job_title: '', basic_salary: '0' })
  const [att, setAtt] = useState({ employee_id: '', attendance_date: new Date().toISOString().slice(0, 10), status: 'present', check_in: '09:00', check_out: '17:00' })
  const [leave, setLeave] = useState({ employee_id: '', from_date: new Date().toISOString().slice(0, 10), to_date: new Date().toISOString().slice(0, 10), leave_type: 'annual', reason: '' })
  const [sal, setSal] = useState({ employee_id: '', period: new Date().toISOString().slice(0, 7), allowances: '0', deductions: '0', status: 'posted' })

  const saveEmp = useMutation({
    mutationFn: () => api.post('/employees', { ...emp, basic_salary: Number(emp.basic_salary), is_active: true }),
    onSuccess: () => { msg.setMessage('تم حفظ الموظف'); void qc.invalidateQueries({ queryKey: ['employees'] }) },
    onError: msg.fromErr,
  })
  const saveAtt = useMutation({
    mutationFn: () => api.post('/attendances', { ...att, employee_id: Number(att.employee_id) }),
    onSuccess: () => { msg.setMessage('تم تسجيل الحضور'); void qc.invalidateQueries({ queryKey: ['attendances'] }) },
    onError: msg.fromErr,
  })
  const saveLeave = useMutation({
    mutationFn: () => api.post('/leave-requests', { ...leave, employee_id: Number(leave.employee_id), status: 'pending' }),
    onSuccess: () => { msg.setMessage('تم طلب الإجازة'); void qc.invalidateQueries({ queryKey: ['leave-requests'] }) },
    onError: msg.fromErr,
  })
  const saveSal = useMutation({
    mutationFn: () => api.post('/salary-records', { ...sal, employee_id: Number(sal.employee_id), allowances: Number(sal.allowances), deductions: Number(sal.deductions) }),
    onSuccess: () => { msg.setMessage('تم ترحيل الراتب'); void qc.invalidateQueries({ queryKey: ['salary-records'] }) },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-6">
      <PageHeader title="الموارد البشرية" subtitle="موظفون، حضور، إجازات، وسجلات رواتب" />
      <Tabs tabs={[{ id: 'employees', label: 'الموظفون' }, { id: 'attendance', label: 'الحضور' }, { id: 'leaves', label: 'الإجازات' }, { id: 'salaries', label: 'الرواتب' }]} active={tab} onChange={setTab} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'employees' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">رقم</th><th className="px-4 py-3">الاسم</th><th className="px-4 py-3">المسمى</th><th className="px-4 py-3">الراتب</th></tr></thead>
              <tbody>{(employees.data || []).map((e: { id: number; employee_number: string; name: string; job_title?: string; basic_salary: number }) => <tr key={e.id} className="border-t border-black/5"><td className="px-4 py-3 font-mono">{e.employee_number}</td><td className="px-4 py-3">{e.name}</td><td className="px-4 py-3">{e.job_title || '—'}</td><td className="px-4 py-3">{e.basic_salary}</td></tr>)}</tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveEmp.mutate() }}>
            <h2 className="font-semibold">موظف جديد</h2>
            <Field label="الرقم الوظيفي"><input className={inputClass} value={emp.employee_number} onChange={(e) => setEmp({ ...emp, employee_number: e.target.value })} required /></Field>
            <Field label="الاسم"><input className={inputClass} value={emp.name} onChange={(e) => setEmp({ ...emp, name: e.target.value })} required /></Field>
            <Field label="المسمى"><input className={inputClass} value={emp.job_title} onChange={(e) => setEmp({ ...emp, job_title: e.target.value })} /></Field>
            <Field label="الراتب الأساسي"><input className={inputClass} value={emp.basic_salary} onChange={(e) => setEmp({ ...emp, basic_salary: e.target.value })} /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">حفظ</button>
          </form>
        </div>
      )}

      {tab === 'attendance' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">موظف</th><th className="px-4 py-3">تاريخ</th><th className="px-4 py-3">حالة</th></tr></thead>
              <tbody>{(attendances.data || []).map((a: { id: number; attendance_date: string; status: string; employee?: { name: string } }) => <tr key={a.id} className="border-t border-black/5"><td className="px-4 py-3">{a.employee?.name}</td><td className="px-4 py-3">{a.attendance_date}</td><td className="px-4 py-3">{a.status}</td></tr>)}</tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveAtt.mutate() }}>
            <h2 className="font-semibold">تسجيل حضور</h2>
            <Field label="موظف"><select className={inputClass} value={att.employee_id} onChange={(e) => setAtt({ ...att, employee_id: e.target.value })} required><option value="">—</option>{(employees.data || []).map((e: { id: number; name: string }) => <option key={e.id} value={e.id}>{e.name}</option>)}</select></Field>
            <Field label="تاريخ"><input type="date" className={inputClass} value={att.attendance_date} onChange={(e) => setAtt({ ...att, attendance_date: e.target.value })} /></Field>
            <Field label="حالة"><select className={inputClass} value={att.status} onChange={(e) => setAtt({ ...att, status: e.target.value })}><option value="present">حاضر</option><option value="absent">غائب</option><option value="leave">إجازة</option></select></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">حفظ</button>
          </form>
        </div>
      )}

      {tab === 'leaves' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">موظف</th><th className="px-4 py-3">من</th><th className="px-4 py-3">إلى</th><th className="px-4 py-3">حالة</th></tr></thead>
              <tbody>{(leaves.data || []).map((l: { id: number; from_date: string; to_date: string; status: string; employee?: { name: string } }) => <tr key={l.id} className="border-t border-black/5"><td className="px-4 py-3">{l.employee?.name}</td><td className="px-4 py-3">{l.from_date}</td><td className="px-4 py-3">{l.to_date}</td><td className="px-4 py-3">{l.status}</td></tr>)}</tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveLeave.mutate() }}>
            <h2 className="font-semibold">طلب إجازة</h2>
            <Field label="موظف"><select className={inputClass} value={leave.employee_id} onChange={(e) => setLeave({ ...leave, employee_id: e.target.value })} required><option value="">—</option>{(employees.data || []).map((e: { id: number; name: string }) => <option key={e.id} value={e.id}>{e.name}</option>)}</select></Field>
            <Field label="من"><input type="date" className={inputClass} value={leave.from_date} onChange={(e) => setLeave({ ...leave, from_date: e.target.value })} /></Field>
            <Field label="إلى"><input type="date" className={inputClass} value={leave.to_date} onChange={(e) => setLeave({ ...leave, to_date: e.target.value })} /></Field>
            <Field label="السبب"><input className={inputClass} value={leave.reason} onChange={(e) => setLeave({ ...leave, reason: e.target.value })} /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">إرسال</button>
          </form>
        </div>
      )}

      {tab === 'salaries' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60"><tr><th className="px-4 py-3">موظف</th><th className="px-4 py-3">فترة</th><th className="px-4 py-3">صافي</th><th className="px-4 py-3">حالة</th></tr></thead>
              <tbody>{(salaries.data || []).map((s: { id: number; period: string; net_salary: number; status: string; employee?: { name: string } }) => <tr key={s.id} className="border-t border-black/5"><td className="px-4 py-3">{s.employee?.name}</td><td className="px-4 py-3">{s.period}</td><td className="px-4 py-3">{s.net_salary}</td><td className="px-4 py-3">{s.status}</td></tr>)}</tbody>
            </table>
          </Panel>
          <form className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveSal.mutate() }}>
            <h2 className="font-semibold">تسجيل راتب</h2>
            <Field label="موظف"><select className={inputClass} value={sal.employee_id} onChange={(e) => setSal({ ...sal, employee_id: e.target.value })} required><option value="">—</option>{(employees.data || []).map((e: { id: number; name: string }) => <option key={e.id} value={e.id}>{e.name}</option>)}</select></Field>
            <Field label="الفترة (YYYY-MM)"><input className={inputClass} value={sal.period} onChange={(e) => setSal({ ...sal, period: e.target.value })} /></Field>
            <Field label="بدلات"><input className={inputClass} value={sal.allowances} onChange={(e) => setSal({ ...sal, allowances: e.target.value })} /></Field>
            <Field label="خصومات"><input className={inputClass} value={sal.deductions} onChange={(e) => setSal({ ...sal, deductions: e.target.value })} /></Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">ترحيل الراتب</button>
          </form>
        </div>
      )}
    </div>
  )
}
