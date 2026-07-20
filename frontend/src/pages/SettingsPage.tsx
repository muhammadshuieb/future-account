import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import type { Setting } from '@/types'
import { Button, EmptyState, Field, LoadingBlock, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

type CurrencyRow = {
  id: number
  code: string
  name: string
  name_en?: string
  symbol?: string
  is_active: boolean
}

type RateRow = {
  id: number
  from_currency: string
  to_currency: string
  rate: string | number
  rate_date: string
  notes?: string
}

type BackupRow = {
  filename: string
  size_human: string
  created_at: string
}

export default function SettingsPage() {
  const queryClient = useQueryClient()
  const [tab, setTab] = useState<'general' | 'currencies' | 'backup'>('general')
  const [values, setValues] = useState<Record<string, string>>({})
  const msg = useFormMessage()

  const { data: settings = [], isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: async () => {
      const res = await api.get('/settings')
      return res.data.data as Setting[]
    },
  })

  const currencies = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api.get('/currencies')).data.data as { base_currency: string; currencies: CurrencyRow[] },
    enabled: tab === 'currencies' || tab === 'general',
  })

  const rates = useQuery({
    queryKey: ['exchange-rates'],
    queryFn: async () => (await api.get('/exchange-rates')).data.data as RateRow[],
    enabled: tab === 'currencies',
  })

  const backups = useQuery({
    queryKey: ['backups'],
    queryFn: async () => (await api.get('/backups')).data.data as BackupRow[],
    enabled: tab === 'backup',
    retry: false,
  })

  const [rateForm, setRateForm] = useState({
    from_currency: 'USD',
    to_currency: 'SYP',
    rate: '',
    rate_date: new Date().toISOString().slice(0, 10),
    notes: '',
  })

  useEffect(() => {
    const map: Record<string, string> = {}
    settings.forEach((s) => {
      map[s.key] = s.value ?? ''
    })
    setValues(map)
  }, [settings])

  const saveMutation = useMutation({
    mutationFn: async () => {
      return api.put('/settings', {
        settings: Object.entries(values).map(([key, value]) => ({ key, value })),
      })
    },
    onSuccess: () => {
      msg.setMessage('تم حفظ الإعدادات.')
      void queryClient.invalidateQueries({ queryKey: ['settings', 'dashboard', 'currencies'] })
    },
    onError: msg.fromErr,
  })

  const saveRate = useMutation({
    mutationFn: () =>
      api.post('/exchange-rates', {
        ...rateForm,
        rate: Number(rateForm.rate),
      }),
    onSuccess: () => {
      msg.setMessage('تم حفظ سعر الصرف')
      void queryClient.invalidateQueries({ queryKey: ['exchange-rates'] })
    },
    onError: msg.fromErr,
  })

  const createBackup = useMutation({
    mutationFn: () => api.post('/backups', { label: 'manual' }),
    onSuccess: () => {
      msg.setMessage('تم إنشاء النسخة الاحتياطية')
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
    onError: msg.fromErr,
  })

  const restoreBackup = useMutation({
    mutationFn: (filename: string) => api.post('/backups/restore', { filename, confirm: true }),
    onSuccess: () => msg.setMessage('تمت الاستعادة — أعد تحميل الصفحة إن لزم'),
    onError: msg.fromErr,
  })

  const deleteBackup = useMutation({
    mutationFn: (filename: string) => api.delete(`/backups/${encodeURIComponent(filename)}`),
    onSuccess: () => {
      msg.setMessage('تم الحذف')
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
    onError: msg.fromErr,
  })

  async function downloadBackup(filename: string) {
    const res = await api.get(`/backups/${encodeURIComponent(filename)}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)
  }

  function onSubmit(e: FormEvent) {
    e.preventDefault()
    saveMutation.mutate()
  }

  if (isLoading) return <LoadingBlock />

  return (
    <div className="space-y-6">
      <PageHeader title="الإعدادات" subtitle="الشركة، العملات، والنسخ الاحتياطي" />
      <Tabs
        tabs={[
          { id: 'general', label: 'عام' },
          { id: 'currencies', label: 'العملات وأسعار الصرف' },
          { id: 'backup', label: 'النسخ الاحتياطي' },
        ]}
        active={tab}
        onChange={(id) => setTab(id as typeof tab)}
      />

      <Msg message={msg.message} error={msg.error} />

      {tab === 'general' && (
        <form onSubmit={onSubmit} className="mx-auto max-w-2xl space-y-4">
          <Panel className="space-y-4 p-6">
            {settings.map((setting) => (
              <Field key={setting.key} label={setting.label || setting.key}>
                {setting.key === 'currency' ? (
                  <select
                    className={inputClass}
                    value={values[setting.key] ?? 'SYP'}
                    onChange={(e) => setValues((prev) => ({ ...prev, [setting.key]: e.target.value }))}
                  >
                    {(currencies.data?.currencies || [
                      { code: 'SYP', name: 'الليرة السورية' },
                      { code: 'TRY', name: 'الليرة التركية' },
                      { code: 'USD', name: 'الدولار الأمريكي' },
                    ]).map((c) => (
                      <option key={c.code} value={c.code}>{c.code} — {c.name}</option>
                    ))}
                  </select>
                ) : (
                  <input
                    value={values[setting.key] ?? ''}
                    onChange={(e) => setValues((prev) => ({ ...prev, [setting.key]: e.target.value }))}
                    className={inputClass}
                  />
                )}
              </Field>
            ))}
            <Button type="submit" variant="primary" disabled={saveMutation.isPending}>
              حفظ الإعدادات
            </Button>
          </Panel>
        </form>
      )}

      {tab === 'currencies' && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Panel>
            <div className="border-b border-[var(--color-line)] px-4 py-3">
              <h2 className="font-semibold">العملات المدعومة</h2>
              <p className="text-xs text-black/45">الأساسية: {currencies.data?.base_currency || 'SYP'}</p>
            </div>
            <ul className="divide-y divide-[var(--color-line)]">
              {(currencies.data?.currencies || []).map((c) => (
                <li key={c.id} className="flex items-center justify-between px-4 py-3 text-sm">
                  <div>
                    <p className="font-semibold">{c.code} <span className="font-normal text-black/50">{c.symbol}</span></p>
                    <p className="text-xs text-black/45">{c.name} · {c.name_en}</p>
                  </div>
                  <span className="text-xs text-teal">{c.is_active ? 'نشطة' : 'موقوفة'}</span>
                </li>
              ))}
            </ul>
          </Panel>

          <Panel className="space-y-4 p-4">
            <h2 className="font-semibold">إدخال سعر صرف</h2>
            <p className="text-xs text-black/45">المعنى: 1 من العملة المصدر = السعر × عملة الهدف</p>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="من">
                <select className={inputClass} value={rateForm.from_currency} onChange={(e) => setRateForm({ ...rateForm, from_currency: e.target.value })}>
                  {['SYP', 'TRY', 'USD'].map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </Field>
              <Field label="إلى">
                <select className={inputClass} value={rateForm.to_currency} onChange={(e) => setRateForm({ ...rateForm, to_currency: e.target.value })}>
                  {['SYP', 'TRY', 'USD'].map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </Field>
              <Field label="السعر">
                <input className={inputClass} value={rateForm.rate} onChange={(e) => setRateForm({ ...rateForm, rate: e.target.value })} />
              </Field>
              <Field label="التاريخ">
                <input type="date" className={inputClass} value={rateForm.rate_date} onChange={(e) => setRateForm({ ...rateForm, rate_date: e.target.value })} />
              </Field>
            </div>
            <Button variant="primary" onClick={() => saveRate.mutate()} disabled={!rateForm.rate || saveRate.isPending}>
              حفظ السعر
            </Button>

            <div className="table-wrap pt-2">
              <table className="data-table">
                <thead><tr><th>تاريخ</th><th>من</th><th>إلى</th><th>سعر</th></tr></thead>
                <tbody>
                  {(rates.data || []).slice(0, 30).map((r) => (
                    <tr key={r.id}>
                      <td>{String(r.rate_date).slice(0, 10)}</td>
                      <td>{r.from_currency}</td>
                      <td>{r.to_currency}</td>
                      <td className="tabular-nums">{r.rate}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
        </div>
      )}

      {tab === 'backup' && (
        <Panel className="space-y-4 p-5">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="font-semibold">نسخ قاعدة البيانات الاحتياطي</h2>
              <p className="text-xs text-black/50">PostgreSQL عبر Docker — للمدير فقط. الاستعادة تستبدل البيانات الحالية.</p>
            </div>
            <Button variant="primary" onClick={() => createBackup.mutate()} disabled={createBackup.isPending}>
              إنشاء نسخة الآن
            </Button>
          </div>

          {backups.isError && (
            <p className="text-sm text-danger">تعذر تحميل القائمة — تأكد أن حسابك بصلاحية مدير.</p>
          )}
          {backups.isLoading && <LoadingBlock />}
          {!backups.isLoading && !(backups.data || []).length && !backups.isError && (
            <EmptyState title="لا توجد نسخ بعد" description="أنشئ أول نسخة احتياطية من الزر أعلاه." />
          )}
          <ul className="divide-y divide-[var(--color-line)]">
            {(backups.data || []).map((b) => (
              <li key={b.filename} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                <div>
                  <p className="font-mono text-xs sm:text-sm">{b.filename}</p>
                  <p className="text-xs text-black/45">{b.size_human} · {new Date(b.created_at).toLocaleString('ar')}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button variant="secondary" onClick={() => void downloadBackup(b.filename)}>تنزيل</Button>
                  <Button
                    variant="danger"
                    onClick={() => {
                      if (window.confirm('استعادة هذه النسخة ستستبدل قاعدة البيانات الحالية. هل أنت متأكد؟')) {
                        restoreBackup.mutate(b.filename)
                      }
                    }}
                  >
                    استعادة
                  </Button>
                  <Button
                    variant="ghost"
                    onClick={() => {
                      if (window.confirm('حذف الملف؟')) deleteBackup.mutate(b.filename)
                    }}
                  >
                    حذف
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        </Panel>
      )}
    </div>
  )
}
