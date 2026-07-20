import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
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
  const { t } = useTranslation()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [tab, setTab] = useState<'general' | 'currencies' | 'backup' | 'barcode' | 'users'>('general')
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

  const backupStatus = useQuery({
    queryKey: ['backups-status'],
    queryFn: async () => (await api.get('/backups/status')).data.data as {
      google_drive: { configured: boolean }
      telegram: { configured: boolean }
    },
    enabled: tab === 'backup',
    retry: false,
  })

  const usersAdmin = useQuery({
    queryKey: ['admin-users'],
    queryFn: async () => (await api.get('/users')).data.data as { id: number; name: string; email: string; is_active: boolean; roles: string[] }[],
    enabled: tab === 'users' && (user?.permissions.includes('users.manage') || user?.roles.includes('admin')),
    retry: false,
  })

  const rolesAdmin = useQuery({
    queryKey: ['admin-roles'],
    queryFn: async () => (await api.get('/roles')).data.data as { roles: { id: number; name: string; permissions: string[] }[]; permissions: string[] },
    enabled: tab === 'users' && (user?.permissions.includes('users.manage') || user?.roles.includes('admin')),
    retry: false,
  })

  const [userForm, setUserForm] = useState({ name: '', email: '', password: '', roles: ['accountant'] as string[] })

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

  const saveUser = useMutation({
    mutationFn: () => api.post('/users', userForm),
    onSuccess: () => {
      msg.setMessage('تم إنشاء المستخدم')
      setUserForm({ name: '', email: '', password: '', roles: ['accountant'] })
      void queryClient.invalidateQueries({ queryKey: ['admin-users'] })
    },
    onError: msg.fromErr,
  })

  const updateRolePerms = useMutation({
    mutationFn: ({ id, permissions }: { id: number; permissions: string[] }) => api.put(`/roles/${id}`, { permissions }),
    onSuccess: () => {
      msg.setMessage('تم تحديث الصلاحيات')
      void queryClient.invalidateQueries({ queryKey: ['admin-roles'] })
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
          { id: 'barcode', label: 'قارئ الباركود' },
          ...((user?.permissions.includes('users.manage') || user?.roles.includes('admin')) ? [{ id: 'users', label: t('settings.users') }] : []),
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
        <div className="space-y-4">
          <Panel className="space-y-3 p-5">
            <h2 className="font-semibold">وجهات النسخ الاحتياطي</h2>
            <p className="text-xs text-black/50">تُضبط عبر متغيرات البيئة على الخادم (.env.prod) — لا تُخزَّن الأسرار في قاعدة البيانات.</p>
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-lg border border-[var(--color-line)] p-3 text-sm">
                <p className="font-medium">Google Drive</p>
                <p className={`mt-1 text-xs ${backupStatus.data?.google_drive.configured ? 'text-success' : 'text-black/45'}`}>
                  {backupStatus.data?.google_drive.configured ? '● مُفعّل' : '○ غير مُعد — GOOGLE_DRIVE_CREDENTIALS_JSON + GOOGLE_DRIVE_FOLDER_ID'}
                </p>
              </div>
              <div className="rounded-lg border border-[var(--color-line)] p-3 text-sm">
                <p className="font-medium">Telegram</p>
                <p className={`mt-1 text-xs ${backupStatus.data?.telegram.configured ? 'text-success' : 'text-black/45'}`}>
                  {backupStatus.data?.telegram.configured ? '● مُفعّل' : '○ غير مُعد — TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID'}
                </p>
              </div>
            </div>
          </Panel>

        <Panel className="space-y-4 p-5">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="font-semibold">نسخ قاعدة البيانات الاحتياطي</h2>
              <p className="text-xs text-black/50">PostgreSQL عبر Docker — للمدير فقط. الاستعادة تستبدل البيانات الحالية.</p>
              <p className="mt-1 text-xs text-teal-dark">{t('settings.autoBackupNote')}</p>
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
        </div>
      )}

      {tab === 'barcode' && (
        <Panel className="space-y-4 p-6">
          <h2 className="font-semibold">{t('settings.barcodeScanner')}</h2>
          <p className="text-sm text-black/60">{t('settings.barcodeIntro')}</p>
          <ol className="list-decimal space-y-2 pr-5 text-sm text-black/70">
            <li>{t('settings.barcodeStep1')}</li>
            <li>{t('settings.barcodeStep2')}</li>
            <li>{t('settings.barcodeStep3')}</li>
            <li>{t('settings.barcodeStep4')}</li>
          </ol>
          <div className="rounded-lg border border-teal/20 bg-teal-soft/40 p-4 text-sm text-teal-dark">
            <p className="font-medium">{t('settings.barcodeAdvanced')}</p>
            <p className="mt-1 text-xs">{t('settings.barcodeAdvancedHint')}</p>
          </div>
          <p className="text-xs text-black/45">{t('settings.barcodeApi')}</p>
        </Panel>
      )}

      {tab === 'users' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <div className="border-b border-[var(--color-line)] px-5 py-3"><h2 className="font-semibold">{t('settings.users')}</h2></div>
            <table className="data-table text-sm">
              <thead><tr><th>الاسم</th><th>البريد</th><th>{t('settings.roles')}</th><th>{t('common.status')}</th></tr></thead>
              <tbody>
                {(usersAdmin.data || []).map((u) => (
                  <tr key={u.id}><td>{u.name}</td><td>{u.email}</td><td>{u.roles.join(', ')}</td><td>{u.is_active ? 'نشط' : 'معطّل'}</td></tr>
                ))}
              </tbody>
            </table>
            <div className="border-t border-[var(--color-line)] p-4">
              <h3 className="mb-3 text-sm font-semibold">{t('settings.roles')}</h3>
              {(rolesAdmin.data?.roles || []).map((role) => (
                <details key={role.id} className="mb-2 rounded border border-[var(--color-line)] p-2">
                  <summary className="cursor-pointer text-sm font-medium">{role.name} ({role.permissions.length})</summary>
                  {role.name !== 'admin' && (
                    <div className="mt-2 grid max-h-40 gap-1 overflow-y-auto text-xs">
                      {(rolesAdmin.data?.permissions || []).map((perm) => (
                        <label key={perm} className="flex items-center gap-2">
                          <input
                            type="checkbox"
                            checked={role.permissions.includes(perm)}
                            onChange={(e) => {
                              const next = e.target.checked
                                ? [...role.permissions, perm]
                                : role.permissions.filter((p) => p !== perm)
                              updateRolePerms.mutate({ id: role.id, permissions: next })
                            }}
                          />
                          {perm}
                        </label>
                      ))}
                    </div>
                  )}
                </details>
              ))}
            </div>
          </Panel>
          <form className="space-y-3 rounded-xl border border-[var(--color-line)] bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); saveUser.mutate() }}>
            <h2 className="font-semibold">مستخدم جديد</h2>
            <Field label="الاسم"><input className={inputClass} value={userForm.name} onChange={(e) => setUserForm({ ...userForm, name: e.target.value })} required /></Field>
            <Field label="البريد"><input type="email" className={inputClass} value={userForm.email} onChange={(e) => setUserForm({ ...userForm, email: e.target.value })} required /></Field>
            <Field label="كلمة المرور"><input type="password" className={inputClass} value={userForm.password} onChange={(e) => setUserForm({ ...userForm, password: e.target.value })} required minLength={8} /></Field>
            <Field label={t('settings.roles')}>
              <select className={inputClass} value={userForm.roles[0]} onChange={(e) => setUserForm({ ...userForm, roles: [e.target.value] })}>
                {(rolesAdmin.data?.roles || []).map((r) => <option key={r.id} value={r.name}>{r.name}</option>)}
              </select>
            </Field>
            <Button type="submit" variant="primary" disabled={saveUser.isPending}>{t('common.save')}</Button>
          </form>
        </div>
      )}
    </div>
  )
}
