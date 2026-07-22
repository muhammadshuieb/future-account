import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { permissionLabel, roleLabel } from '@/lib/rbacLabels'
import type { Setting } from '@/types'
import { Button, EmptyState, Field, LoadingBlock, Modal, Msg, NumericInput, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

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

const HIDDEN_GENERAL_KEYS = new Set([
  'tax_enabled',
  'tax_rate',
  'default_locale',
  'locale',
  'backup_time_1',
  'backup_time_2',
])

function isTruthy(value: string | undefined): boolean {
  return ['1', 'true', 'yes', 'on'].includes(String(value ?? '').toLowerCase())
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
  const [userModal, setUserModal] = useState<'create' | 'edit' | null>(null)
  const [editingUserId, setEditingUserId] = useState<number | null>(null)

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
    if (!map.default_locale && map.locale) map.default_locale = map.locale
    if (map.tax_enabled === undefined) map.tax_enabled = '1'
    if (!map.backup_time_1) map.backup_time_1 = '02:00'
    if (!map.backup_time_2) map.backup_time_2 = '14:00'
    setValues(map)
  }, [settings])

  const generalSettings = useMemo(
    () => settings.filter((s) => !HIDDEN_GENERAL_KEYS.has(s.key)),
    [settings],
  )

  const taxEnabled = isTruthy(values.tax_enabled)

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = { ...values }
      if (!payload.default_locale) payload.default_locale = 'ar'
      payload.locale = payload.default_locale
      payload.tax_enabled = isTruthy(payload.tax_enabled) ? '1' : '0'
      return api.put('/settings', {
        settings: Object.entries(payload).map(([key, value]) => ({ key, value })),
      })
    },
    onSuccess: () => {
      msg.setMessage(t('settings.saved'))
      void queryClient.invalidateQueries({ queryKey: ['settings'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      void queryClient.invalidateQueries({ queryKey: ['currencies'] })
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
      msg.setMessage(t('settings.rateSaved'))
      void queryClient.invalidateQueries({ queryKey: ['exchange-rates'] })
    },
    onError: msg.fromErr,
  })

  const createBackup = useMutation({
    mutationFn: () => api.post('/backups', { label: 'manual' }),
    onSuccess: () => {
      msg.setMessage(t('settings.backupCreated'))
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
    onError: msg.fromErr,
  })

  const restoreBackup = useMutation({
    mutationFn: (filename: string) => api.post('/backups/restore', { filename, confirm: true }),
    onSuccess: () => msg.setMessage(t('settings.backupRestored')),
    onError: msg.fromErr,
  })

  const deleteBackup = useMutation({
    mutationFn: (filename: string) => api.delete(`/backups/${encodeURIComponent(filename)}`),
    onSuccess: () => {
      msg.setMessage(t('settings.backupDeleted'))
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
    onError: msg.fromErr,
  })

  const saveUser = useMutation({
    mutationFn: () => {
      if (!editingUserId) return api.post('/users', userForm)
      const { password, ...payload } = userForm
      return api.put(`/users/${editingUserId}`, password ? { ...payload, password } : payload)
    },
    onSuccess: () => {
      msg.setMessage(editingUserId ? t('settings.userUpdated') : t('settings.userCreated'))
      setUserForm({ name: '', email: '', password: '', roles: ['accountant'] })
      setEditingUserId(null)
      setUserModal(null)
      void queryClient.invalidateQueries({ queryKey: ['admin-users'] })
    },
    onError: msg.fromErr,
  })

  const updateRolePerms = useMutation({
    mutationFn: ({ id, permissions }: { id: number; permissions: string[] }) => api.put(`/roles/${id}`, { permissions }),
    onSuccess: () => {
      msg.setMessage(t('settings.permsUpdated'))
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

  function setValue(key: string, value: string) {
    setValues((prev) => ({ ...prev, [key]: value }))
  }

  if (isLoading) return <LoadingBlock />

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('nav.settings')}
        subtitle={t('settings.subtitle')}
        actions={tab === 'users' ? <Button variant="primary" onClick={() => { setEditingUserId(null); setUserForm({ name: '', email: '', password: '', roles: ['accountant'] }); setUserModal('create') }}>{t('common.add')}</Button> : undefined}
      />
      <Tabs
        tabs={[
          { id: 'general', label: t('settings.tabGeneral') },
          { id: 'currencies', label: t('settings.tabCurrencies') },
          { id: 'backup', label: t('settings.tabBackup') },
          { id: 'barcode', label: t('settings.barcodeScanner') },
          ...((user?.permissions.includes('users.manage') || user?.roles.includes('admin')) ? [{ id: 'users', label: t('settings.users') }] : []),
        ]}
        active={tab}
        onChange={(id) => setTab(id as typeof tab)}
      />

      <Msg message={msg.message} error={msg.error} />

      {tab === 'general' && (
        <form onSubmit={onSubmit} className="mx-auto max-w-2xl space-y-4">
          <Panel className="space-y-4 p-6">
            <h2 className="text-sm font-semibold text-black/70">{t('settings.companySection')}</h2>
            {generalSettings.map((setting) => (
              <Field key={setting.key} label={setting.label || setting.key}>
                {setting.key === 'currency' ? (
                  <select
                    className={inputClass}
                    value={values[setting.key] ?? 'SYP'}
                    onChange={(e) => setValue(setting.key, e.target.value)}
                  >
                    {(currencies.data?.currencies || [
                      { code: 'SYP', name: 'الليرة السورية' },
                      { code: 'TRY', name: 'الليرة التركية' },
                      { code: 'USD', name: 'الدولار الأمريكي' },
                    ]).map((c) => (
                      <option key={c.code} value={c.code}>{c.code} — {c.name}</option>
                    ))}
                  </select>
                ) : setting.type === 'boolean' || ['multi_currency', 'multi_language'].includes(setting.key) ? (
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={isTruthy(values[setting.key])}
                      onChange={(e) => setValue(setting.key, e.target.checked ? '1' : '0')}
                    />
                    <span>{isTruthy(values[setting.key]) ? t('common.enabled') : t('common.disabled')}</span>
                  </label>
                ) : (
                  <input
                    value={values[setting.key] ?? ''}
                    onChange={(e) => setValue(setting.key, e.target.value)}
                    className={inputClass}
                  />
                )}
              </Field>
            ))}

            <div className="border-t border-[var(--color-line)] pt-4 space-y-4">
              <h2 className="text-sm font-semibold text-black/70">{t('settings.taxAndLocale')}</h2>
              <Field label={t('settings.taxEnabled')}>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={taxEnabled}
                    onChange={(e) => setValue('tax_enabled', e.target.checked ? '1' : '0')}
                  />
                  <span>{taxEnabled ? t('common.enabled') : t('common.disabled')}</span>
                </label>
                <p className="mt-1 text-xs text-black/45">{t('settings.taxEnabledHint')}</p>
              </Field>
              {taxEnabled && (
                <Field label={t('settings.taxRate')}>
                  <NumericInput
                    value={values.tax_rate ?? '15'}
                    onChange={(v) => setValue('tax_rate', v)}
                  />
                </Field>
              )}
              <Field label={t('settings.defaultLocale')}>
                <select
                  className={inputClass}
                  value={values.default_locale || values.locale || 'ar'}
                  onChange={(e) => setValue('default_locale', e.target.value)}
                >
                  <option value="ar">العربية</option>
                  <option value="en">English</option>
                  <option value="tr">Türkçe</option>
                </select>
                <p className="mt-1 text-xs text-black/45">{t('settings.defaultLocaleHint')}</p>
              </Field>
            </div>

            <Button type="submit" variant="primary" disabled={saveMutation.isPending}>
              {t('settings.save')}
            </Button>
          </Panel>
        </form>
      )}

      {tab === 'currencies' && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Panel>
            <div className="border-b border-[var(--color-line)] px-4 py-3">
              <h2 className="font-semibold">{t('settings.supportedCurrencies')}</h2>
              <p className="text-xs text-black/45">{t('settings.baseCurrency')}: {currencies.data?.base_currency || 'SYP'}</p>
            </div>
            <ul className="divide-y divide-[var(--color-line)]">
              {(currencies.data?.currencies || []).map((c) => (
                <li key={c.id} className="flex items-center justify-between px-4 py-3 text-sm">
                  <div>
                    <p className="font-semibold">{c.code} <span className="font-normal text-black/50">{c.symbol}</span></p>
                    <p className="text-xs text-black/45">{c.name} · {c.name_en}</p>
                  </div>
                  <span className="text-xs text-teal">{c.is_active ? t('common.active') : t('common.inactive')}</span>
                </li>
              ))}
            </ul>
          </Panel>

          <Panel className="space-y-4 p-4">
            <h2 className="font-semibold">{t('settings.enterRate')}</h2>
            <p className="text-xs text-black/45">{t('settings.rateMeaning')}</p>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('settings.from')}>
                <select className={inputClass} value={rateForm.from_currency} onChange={(e) => setRateForm({ ...rateForm, from_currency: e.target.value })}>
                  {['SYP', 'TRY', 'USD'].map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </Field>
              <Field label={t('settings.to')}>
                <select className={inputClass} value={rateForm.to_currency} onChange={(e) => setRateForm({ ...rateForm, to_currency: e.target.value })}>
                  {['SYP', 'TRY', 'USD'].map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </Field>
              <Field label={t('settings.rate')}>
                <NumericInput value={rateForm.rate} onChange={(v) => setRateForm((prev) => ({ ...prev, rate: v }))} />
              </Field>
              <Field label={t('common.date')}>
                <input type="date" className={inputClass} value={rateForm.rate_date} onChange={(e) => setRateForm({ ...rateForm, rate_date: e.target.value })} />
              </Field>
            </div>
            <Button variant="primary" onClick={() => saveRate.mutate()} disabled={!rateForm.rate || saveRate.isPending}>
              {t('settings.saveRate')}
            </Button>

            <div className="table-wrap pt-2">
              <table className="data-table">
                <thead><tr><th>{t('common.date')}</th><th>{t('settings.from')}</th><th>{t('settings.to')}</th><th>{t('settings.rate')}</th></tr></thead>
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
          <Panel className="space-y-4 p-5">
            <h2 className="font-semibold">{t('settings.autoBackupSchedule')}</h2>
            <p className="text-xs text-black/50">{t('settings.autoBackupNote')}</p>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('settings.backupTime1')}>
                <input
                  type="time"
                  className={inputClass}
                  value={values.backup_time_1 || '02:00'}
                  onChange={(e) => setValue('backup_time_1', e.target.value)}
                />
              </Field>
              <Field label={t('settings.backupTime2')}>
                <input
                  type="time"
                  className={inputClass}
                  value={values.backup_time_2 || '14:00'}
                  onChange={(e) => setValue('backup_time_2', e.target.value)}
                />
              </Field>
            </div>
            <Button type="button" variant="primary" disabled={saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              {t('settings.saveSchedule')}
            </Button>
          </Panel>

          <Panel className="space-y-3 p-5">
            <h2 className="font-semibold">{t('settings.backupDestinations')}</h2>
            <p className="text-xs text-black/50">{t('settings.backupDestHint')}</p>
            {!backupStatus.data?.google_drive.configured && (
              <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                {t('settings.driveNotConnected')}
              </div>
            )}
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-lg border border-[var(--color-line)] p-3 text-sm">
                <p className="font-medium">Google Drive</p>
                <p className={`mt-1 text-xs ${backupStatus.data?.google_drive.configured ? 'text-success' : 'text-danger'}`}>
                  {backupStatus.data?.google_drive.configured ? t('settings.driveActive') : t('settings.driveInactive')}
                </p>
              </div>
              <div className="rounded-lg border border-[var(--color-line)] p-3 text-sm">
                <p className="font-medium">Telegram</p>
                <p className={`mt-1 text-xs ${backupStatus.data?.telegram.configured ? 'text-success' : 'text-black/45'}`}>
                  {backupStatus.data?.telegram.configured ? t('settings.telegramActive') : t('settings.telegramInactive')}
                </p>
              </div>
            </div>
          </Panel>

          <Panel className="space-y-4 p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="font-semibold">{t('settings.dbBackup')}</h2>
                <p className="text-xs text-black/50">{t('settings.dbBackupHint')}</p>
              </div>
              <Button variant="primary" onClick={() => createBackup.mutate()} disabled={createBackup.isPending}>
                {t('settings.createBackupNow')}
              </Button>
            </div>

            {backups.isError && (
              <p className="text-sm text-danger">{t('settings.backupListError')}</p>
            )}
            {backups.isLoading && <LoadingBlock />}
            {!backups.isLoading && !(backups.data || []).length && !backups.isError && (
              <EmptyState title={t('settings.noBackups')} description={t('settings.noBackupsHint')} />
            )}
            <ul className="divide-y divide-[var(--color-line)]">
              {(backups.data || []).map((b) => (
                <li key={b.filename} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                  <div>
                    <p className="font-mono text-xs sm:text-sm">{b.filename}</p>
                    <p className="text-xs text-black/45">{b.size_human} · {new Date(b.created_at).toLocaleString()}</p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={() => void downloadBackup(b.filename)}>{t('settings.download')}</Button>
                    <Button
                      variant="danger"
                      onClick={() => {
                        if (window.confirm(t('settings.restoreConfirm'))) {
                          restoreBackup.mutate(b.filename)
                        }
                      }}
                    >
                      {t('settings.restore')}
                    </Button>
                    <Button
                      variant="ghost"
                      onClick={() => {
                        if (window.confirm(t('settings.deleteConfirm'))) deleteBackup.mutate(b.filename)
                      }}
                    >
                      {t('common.delete')}
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
        <Panel>
            <div className="border-b border-[var(--color-line)] px-5 py-3"><h2 className="font-semibold">{t('settings.users')}</h2></div>
            <table className="data-table text-sm">
              <thead><tr><th>{t('settings.name')}</th><th>{t('settings.email')}</th><th>{t('settings.roles')}</th><th>{t('common.status')}</th></tr></thead>
              <tbody>
                {(usersAdmin.data || []).map((u) => (
                  <tr key={u.id} className="cursor-pointer" onClick={() => { setEditingUserId(u.id); setUserForm({ name: u.name, email: u.email, password: '', roles: u.roles.length ? u.roles : ['accountant'] }); setUserModal('edit') }}><td>{u.name}</td><td>{u.email}</td><td>{u.roles.map((r) => roleLabel(t, r)).join(', ')}</td><td>{u.is_active ? t('common.active') : t('common.inactive')}</td></tr>
                ))}
              </tbody>
            </table>
            <div className="border-t border-[var(--color-line)] p-4">
              <h3 className="mb-3 text-sm font-semibold">{t('settings.roles')}</h3>
              {(rolesAdmin.data?.roles || []).map((role) => {
                const isAdminRole = role.name === 'admin'
                return (
                  <details key={role.id} className="mb-2 rounded border border-[var(--color-line)] p-2">
                    <summary className="cursor-pointer text-sm font-medium">
                      {roleLabel(t, role.name)} ({role.permissions.length})
                    </summary>
                    <div className="mt-2 grid max-h-48 gap-1 overflow-y-auto text-xs">
                      {isAdminRole && (
                        <p className="mb-1 text-[11px] text-black/45">{t('settings.adminRoleLocked')}</p>
                      )}
                      {(rolesAdmin.data?.permissions || []).map((perm) => (
                        <label key={`${role.id}-${perm}`} className="flex items-center gap-2">
                          <input
                            type="checkbox"
                            checked={role.permissions.includes(perm)}
                            disabled={isAdminRole || updateRolePerms.isPending}
                            onChange={(e) => {
                              if (isAdminRole) return
                              const next = e.target.checked
                                ? [...role.permissions, perm]
                                : role.permissions.filter((p) => p !== perm)
                              updateRolePerms.mutate({ id: role.id, permissions: next })
                            }}
                          />
                          <span>{permissionLabel(t, perm)}</span>
                        </label>
                      ))}
                    </div>
                  </details>
                )
              })}
            </div>
        </Panel>
      )}
      <Modal open={userModal !== null} onClose={() => { setUserModal(null); setEditingUserId(null) }} title={userModal === 'edit' ? t('common.edit') : t('settings.newUser')} footer={<><Button variant="secondary" onClick={() => { setUserModal(null); setEditingUserId(null) }}>{t('common.cancel')}</Button><Button type="submit" form="user-form" variant="primary" disabled={saveUser.isPending}>{t('common.save')}</Button></>}>
        <form id="user-form" className="space-y-3" onSubmit={(e) => { e.preventDefault(); saveUser.mutate() }}>
          <Field label={t('settings.name')}><input className={inputClass} value={userForm.name} onChange={(e) => setUserForm({ ...userForm, name: e.target.value })} required /></Field>
          <Field label={t('settings.email')}><input type="email" className={inputClass} value={userForm.email} onChange={(e) => setUserForm({ ...userForm, email: e.target.value })} required /></Field>
          <Field label={t('settings.password')} hint={userModal === 'edit' ? t('settings.passwordKeepHint') : undefined}><input type="password" className={inputClass} value={userForm.password} onChange={(e) => setUserForm({ ...userForm, password: e.target.value })} required={userModal === 'create'} minLength={8} /></Field>
          <Field label={t('settings.roles')}><select className={inputClass} value={userForm.roles[0]} onChange={(e) => setUserForm({ ...userForm, roles: [e.target.value] })}>{(rolesAdmin.data?.roles || []).map((r) => <option key={r.id} value={r.name}>{roleLabel(t, r.name)}</option>)}</select></Field>
        </form>
      </Modal>
    </div>
  )
}
