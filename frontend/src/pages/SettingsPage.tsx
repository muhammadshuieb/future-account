import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { formatDateTimeLocal, todayYmd } from '@/lib/dates'
import { permissionLabel, roleLabel } from '@/lib/rbacLabels'
import { useQueryTab } from '@/lib/useQueryTab'
import type { Setting } from '@/types'
import ExcelExportButton from '@/components/ExcelExportButton'
import { Button, EmptyState, Field, ListSearchInput, LoadingBlock, Modal, Msg, NumericInput, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'
import { useListSearch } from '@/lib/useListSearch'

const SETTINGS_TABS = ['general', 'currencies', 'backup', 'whatsapp', 'barcode', 'users'] as const

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
  kind?: 'sql' | 'excel' | string
}

const HIDDEN_GENERAL_KEYS = new Set([
  'tax_enabled',
  'tax_rate',
  'default_locale',
  'locale',
  'backup_time_1',
  'backup_time_2',
  'backup_retention_days',
  'backup_min_keep',
  'backup_last_cleanup',
  'default_branch_id',
  'default_warehouse_id',
  'default_cash_box_id',
  'multi_language',
])

function isTruthy(value: string | undefined): boolean {
  return ['1', 'true', 'yes', 'on'].includes(String(value ?? '').toLowerCase())
}

export default function SettingsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [tab, setTab] = useQueryTab(SETTINGS_TABS, 'general')
  const [values, setValues] = useState<Record<string, string>>({})
  const msg = useFormMessage()
  const backupFileInputRef = useRef<HTMLInputElement>(null)
  const search = useListSearch()

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

  type DestStatus = {
    configured: boolean
    status: 'connected' | 'disconnected' | 'error' | string
    source?: string
    credentials_set?: boolean
    folder_id_set?: boolean
    folder_id_masked?: string | null
    token_set?: boolean
    chat_id_set?: boolean
    chat_id_masked?: string | null
    last_success_at?: string | null
    last_error?: string | null
  }

  const backupStatus = useQuery({
    queryKey: ['backups-status'],
    queryFn: async () => (await api.get('/backups/status')).data.data as {
      google_drive: DestStatus
      telegram: DestStatus
      retention?: {
        retention_days: number
        min_keep: number
        has_fresh_backup: boolean
        local_count: number
        policy_ar: string
        last_cleanup: {
          pruned?: boolean
          skipped?: boolean
          deleted_count?: number
          remaining?: number
          message?: string
          at?: string
        } | null
      }
    },
    enabled: tab === 'backup',
    retry: false,
  })

  const driveFileInputRef = useRef<HTMLInputElement>(null)
  const [driveCredentials, setDriveCredentials] = useState('')
  const [driveFolderId, setDriveFolderId] = useState('')
  const [telegramToken, setTelegramToken] = useState('')
  const [telegramChatId, setTelegramChatId] = useState('')

  function destStatusLabel(status?: string) {
    if (status === 'connected') return t('settings.destConnected')
    if (status === 'error') return t('settings.destError')
    return t('settings.destDisconnected')
  }

  function destStatusClass(status?: string) {
    if (status === 'connected') return 'text-success'
    if (status === 'error') return 'text-danger'
    return 'text-black/45'
  }

  const whatsappStatus = useQuery({
    queryKey: ['whatsapp-status'],
    queryFn: async () => (await api.get('/whatsapp/status')).data.data as { configured: boolean },
    enabled: tab === 'whatsapp',
    retry: false,
  })

  const usersAdmin = useQuery({
    queryKey: ['admin-users', search.debouncedQ],
    queryFn: async () => (await api.get('/users', { params: search.params })).data.data as {
      id: number
      name: string
      first_name?: string
      last_name?: string
      username?: string
      mobile?: string
      email: string
      is_active: boolean
      roles: string[]
      warehouse_ids: number[]
      warehouses?: { id: number; name: string; code: string }[]
    }[],
    enabled: tab === 'users' && (user?.permissions.includes('users.manage') || user?.roles.includes('admin')),
    retry: false,
  })

  const rolesAdmin = useQuery({
    queryKey: ['admin-roles'],
    queryFn: async () => (await api.get('/roles')).data.data as { roles: { id: number; name: string; permissions: string[] }[]; permissions: string[] },
    enabled: tab === 'users' && (user?.permissions.includes('users.manage') || user?.roles.includes('admin')),
    retry: false,
  })

  const warehousesAdmin = useQuery({
    queryKey: ['admin-warehouses'],
    queryFn: async () => (await api.get('/warehouses')).data.data as { id: number; name: string; code: string }[],
    enabled: tab === 'users' && (user?.permissions.includes('users.manage') || user?.roles.includes('admin')),
    retry: false,
  })

  const emptyUserForm = {
    first_name: '',
    last_name: '',
    username: '',
    mobile: '',
    email: '',
    password: '',
    roles: ['accountant'] as string[],
    warehouse_ids: [] as number[],
  }
  const [userForm, setUserForm] = useState(emptyUserForm)
  const [userModal, setUserModal] = useState<'create' | 'edit' | null>(null)
  const [editingUserId, setEditingUserId] = useState<number | null>(null)

  const [rateForm, setRateForm] = useState({
    from_currency: 'USD',
    to_currency: 'SYP',
    rate: '',
    rate_date: todayYmd(),
    notes: '',
  })

  useEffect(() => {
    const map: Record<string, string> = {}
    settings.forEach((s) => {
      map[s.key] = s.value ?? ''
    })
    if (!map.default_locale && map.locale) map.default_locale = map.locale
    if (map.tax_enabled === undefined) map.tax_enabled = '0'
    if (!map.backup_time_1) map.backup_time_1 = '02:00'
    if (!map.backup_time_2) map.backup_time_2 = '14:00'
    if (!map.backup_retention_days) map.backup_retention_days = '7'
    if (!map.backup_min_keep) map.backup_min_keep = '3'
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

  const restoreBackupFromFile = useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData()
      form.append('file', file)
      form.append('confirm', '1')
      return api.post('/backups/restore-upload', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: 600_000,
      })
    },
    onSuccess: () => {
      msg.setMessage(t('settings.backupRestored'))
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
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

  const saveDrive = useMutation({
    mutationFn: () =>
      api.put('/backups/destinations/google-drive', {
        credentials_json: driveCredentials.trim() || undefined,
        folder_id: driveFolderId.trim() || undefined,
      }),
    onSuccess: (res) => {
      msg.setMessage(res.data?.data?.message || t('settings.driveSaved'))
      setDriveCredentials('')
      setDriveFolderId('')
      void queryClient.invalidateQueries({ queryKey: ['backups-status'] })
    },
    onError: msg.fromErr,
  })

  const testDrive = useMutation({
    mutationFn: () => api.post('/backups/destinations/google-drive/test'),
    onSuccess: (res) => {
      msg.setMessage(res.data?.data?.message || t('settings.driveTestOk'))
      void queryClient.invalidateQueries({ queryKey: ['backups-status'] })
    },
    onError: msg.fromErr,
  })

  const disconnectDrive = useMutation({
    mutationFn: () => api.delete('/backups/destinations/google-drive'),
    onSuccess: (res) => {
      msg.setMessage(res.data?.data?.message || t('settings.driveDisconnected'))
      setDriveCredentials('')
      setDriveFolderId('')
      void queryClient.invalidateQueries({ queryKey: ['backups-status'] })
    },
    onError: msg.fromErr,
  })

  const saveTelegram = useMutation({
    mutationFn: () =>
      api.put('/backups/destinations/telegram', {
        bot_token: telegramToken.trim() || undefined,
        chat_id: telegramChatId.trim() || undefined,
      }),
    onSuccess: (res) => {
      msg.setMessage(res.data?.data?.message || t('settings.telegramSaved'))
      setTelegramToken('')
      setTelegramChatId('')
      void queryClient.invalidateQueries({ queryKey: ['backups-status'] })
    },
    onError: msg.fromErr,
  })

  const testTelegram = useMutation({
    mutationFn: () => api.post('/backups/destinations/telegram/test'),
    onSuccess: (res) => {
      msg.setMessage(res.data?.data?.message || t('settings.telegramTestOk'))
      void queryClient.invalidateQueries({ queryKey: ['backups-status'] })
    },
    onError: msg.fromErr,
  })

  const disconnectTelegram = useMutation({
    mutationFn: () => api.delete('/backups/destinations/telegram'),
    onSuccess: (res) => {
      msg.setMessage(res.data?.data?.message || t('settings.telegramDisconnected'))
      setTelegramToken('')
      setTelegramChatId('')
      void queryClient.invalidateQueries({ queryKey: ['backups-status'] })
    },
    onError: msg.fromErr,
  })

  const saveUser = useMutation({
    mutationFn: () => {
      const payload = {
        first_name: userForm.first_name,
        last_name: userForm.last_name,
        username: userForm.username,
        mobile: userForm.mobile,
        email: userForm.email || undefined,
        roles: userForm.roles,
        warehouse_ids: userForm.warehouse_ids,
        ...(userForm.password ? { password: userForm.password } : {}),
      }
      if (!editingUserId) {
        return api.post('/users', { ...payload, password: userForm.password })
      }
      return api.put(`/users/${editingUserId}`, payload)
    },
    onSuccess: () => {
      msg.setMessage(editingUserId ? t('settings.userUpdated') : t('settings.userCreated'))
      setUserForm(emptyUserForm)
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
        actions={tab === 'users' ? (
          <div className="flex flex-wrap items-center gap-2">
            <ListSearchInput value={search.q} onChange={search.setQ} />
            <Button variant="primary" onClick={() => { setEditingUserId(null); setUserForm(emptyUserForm); setUserModal('create') }}>{t('common.add')}</Button>
          </div>
        ) : undefined}
      />
      <Tabs
        tabs={[
          { id: 'general', label: t('settings.tabGeneral') },
          { id: 'currencies', label: t('settings.tabCurrencies') },
          { id: 'backup', label: t('settings.tabBackup') },
          { id: 'whatsapp', label: t('settings.tabWhatsapp') },
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
                    value={values[setting.key] ?? 'USD'}
                    onChange={(e) => setValue(setting.key, e.target.value)}
                  >
                    {(currencies.data?.currencies || [
                      { code: 'USD', name: 'الدولار الأمريكي' },
                      { code: 'SYP', name: 'الليرة السورية' },
                      { code: 'TRY', name: 'الليرة التركية' },
                      { code: 'CNY', name: 'اليوان الصيني' },
                      { code: 'SAR', name: 'الريال السعودي' },
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
              <p className="text-xs text-black/45">{t('settings.baseCurrency')}: {currencies.data?.base_currency || 'USD'}</p>
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
                  {(currencies.data?.currencies || [{ code: 'USD' }, { code: 'SYP' }, { code: 'TRY' }, { code: 'CNY' }, { code: 'SAR' }]).map((c) => (
                    <option key={c.code} value={c.code}>{c.code}</option>
                  ))}
                </select>
              </Field>
              <Field label={t('settings.to')}>
                <select className={inputClass} value={rateForm.to_currency} onChange={(e) => setRateForm({ ...rateForm, to_currency: e.target.value })}>
                  {(currencies.data?.currencies || [{ code: 'USD' }, { code: 'SYP' }, { code: 'TRY' }, { code: 'CNY' }, { code: 'SAR' }]).map((c) => (
                    <option key={c.code} value={c.code}>{c.code}</option>
                  ))}
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

          <Panel className="space-y-4 p-5">
            <h2 className="font-semibold">{t('settings.backupRetention')}</h2>
            <p className="text-xs text-black/50">
              {backupStatus.data?.retention?.policy_ar || t('settings.backupRetentionPolicy')}
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('settings.backupRetentionDays')}>
                <NumericInput
                  value={values.backup_retention_days || '7'}
                  onChange={(v) => setValue('backup_retention_days', v)}
                />
              </Field>
              <Field label={t('settings.backupMinKeep')}>
                <NumericInput
                  value={values.backup_min_keep || '3'}
                  onChange={(v) => setValue('backup_min_keep', v)}
                />
              </Field>
            </div>
            {backupStatus.data?.retention?.last_cleanup && (
              <div className={`rounded-lg border px-3 py-2 text-sm ${
                backupStatus.data.retention.last_cleanup.skipped
                  ? 'border-amber-300 bg-amber-50 text-amber-900'
                  : 'border-[var(--color-line)] text-black/70'
              }`}>
                <p className="font-medium">{t('settings.backupLastCleanup')}</p>
                <p className="mt-1 text-xs">
                  {backupStatus.data.retention.last_cleanup.message}
                  {backupStatus.data.retention.last_cleanup.at
                    ? ` · ${formatDateTimeLocal(backupStatus.data.retention.last_cleanup.at)}`
                    : ''}
                </p>
              </div>
            )}
            {!backupStatus.data?.retention?.has_fresh_backup && (
              <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                {t('settings.backupNoFreshWarning')}
              </div>
            )}
            <Button
              type="button"
              variant="primary"
              disabled={saveMutation.isPending}
              onClick={() => {
                saveMutation.mutate(undefined, {
                  onSuccess: () => {
                    void queryClient.invalidateQueries({ queryKey: ['backups-status'] })
                  },
                })
              }}
            >
              {t('settings.saveRetention')}
            </Button>
          </Panel>

          <Panel className="space-y-4 p-5">
            <div>
              <h2 className="font-semibold">{t('settings.backupDestinations')}</h2>
              <p className="mt-1 text-xs text-black/50">{t('settings.backupDestHint')}</p>
            </div>
            {!backupStatus.data?.google_drive.configured && (
              <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                {t('settings.driveNotConnected')}
              </div>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
              {/* Google Drive */}
              <div className="space-y-3 rounded-xl border border-[var(--color-line)] p-4">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <h3 className="font-semibold">Google Drive</h3>
                    <p className={`mt-1 text-xs font-medium ${destStatusClass(backupStatus.data?.google_drive.status)}`}>
                      {destStatusLabel(backupStatus.data?.google_drive.status)}
                    </p>
                  </div>
                  {backupStatus.data?.google_drive.folder_id_masked && (
                    <p className="font-mono text-[11px] text-black/40">
                      {t('settings.folderId')}: {backupStatus.data.google_drive.folder_id_masked}
                    </p>
                  )}
                </div>

                <Field label={t('settings.driveCredentialsJson')}>
                  <textarea
                    className={`${inputClass} min-h-[96px] font-mono text-xs`}
                    value={driveCredentials}
                    onChange={(e) => setDriveCredentials(e.target.value)}
                    placeholder={backupStatus.data?.google_drive.credentials_set ? t('settings.secretKeepHint') : '{"type":"service_account",...}'}
                    autoComplete="off"
                    spellCheck={false}
                  />
                  <div className="mt-2 flex flex-wrap gap-2">
                    <Button type="button" variant="secondary" onClick={() => driveFileInputRef.current?.click()}>
                      {t('settings.uploadJsonFile')}
                    </Button>
                    <input
                      ref={driveFileInputRef}
                      type="file"
                      accept="application/json,.json"
                      className="hidden"
                      onChange={(e) => {
                        const file = e.target.files?.[0]
                        e.target.value = ''
                        if (!file) return
                        const reader = new FileReader()
                        reader.onload = () => setDriveCredentials(String(reader.result ?? ''))
                        reader.readAsText(file)
                      }}
                    />
                  </div>
                  <p className="mt-1 text-xs text-black/45">{t('settings.driveCredentialsHint')}</p>
                </Field>

                <Field label={t('settings.folderId')}>
                  <input
                    className={inputClass}
                    value={driveFolderId}
                    onChange={(e) => setDriveFolderId(e.target.value)}
                    placeholder={backupStatus.data?.google_drive.folder_id_set ? t('settings.secretKeepHint') : t('settings.folderIdPlaceholder')}
                    autoComplete="off"
                  />
                </Field>

                {(backupStatus.data?.google_drive.last_success_at || backupStatus.data?.google_drive.last_error) && (
                  <div className="space-y-1 text-xs text-black/55">
                    {backupStatus.data.google_drive.last_success_at && (
                      <p>{t('settings.lastSuccess')}: {formatDateTimeLocal(backupStatus.data.google_drive.last_success_at)}</p>
                    )}
                    {backupStatus.data.google_drive.last_error && (
                      <p className="text-danger">{t('settings.lastError')}: {backupStatus.data.google_drive.last_error}</p>
                    )}
                  </div>
                )}

                <div className="flex flex-wrap gap-2 pt-1">
                  <Button
                    type="button"
                    variant="primary"
                    disabled={saveDrive.isPending || (!driveCredentials.trim() && !driveFolderId.trim())}
                    onClick={() => saveDrive.mutate()}
                  >
                    {t('settings.save')}
                  </Button>
                  <Button
                    type="button"
                    variant="secondary"
                    disabled={testDrive.isPending || !backupStatus.data?.google_drive.configured}
                    onClick={() => testDrive.mutate()}
                  >
                    {t('settings.testConnection')}
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    disabled={disconnectDrive.isPending || backupStatus.data?.google_drive.source === 'none'}
                    onClick={() => {
                      if (window.confirm(t('settings.disconnectConfirm'))) disconnectDrive.mutate()
                    }}
                  >
                    {t('settings.disconnect')}
                  </Button>
                </div>
              </div>

              {/* Telegram */}
              <div className="space-y-3 rounded-xl border border-[var(--color-line)] p-4">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <h3 className="font-semibold">Telegram</h3>
                    <p className={`mt-1 text-xs font-medium ${destStatusClass(backupStatus.data?.telegram.status)}`}>
                      {destStatusLabel(backupStatus.data?.telegram.status)}
                    </p>
                  </div>
                  {backupStatus.data?.telegram.chat_id_masked && (
                    <p className="font-mono text-[11px] text-black/40">
                      Chat: {backupStatus.data.telegram.chat_id_masked}
                    </p>
                  )}
                </div>

                <Field label={t('settings.telegramBotToken')}>
                  <input
                    type="password"
                    className={inputClass}
                    value={telegramToken}
                    onChange={(e) => setTelegramToken(e.target.value)}
                    placeholder={backupStatus.data?.telegram.token_set ? t('settings.secretKeepHint') : '123456:ABC...'}
                    autoComplete="new-password"
                  />
                </Field>

                <Field label={t('settings.telegramChatId')}>
                  <input
                    className={inputClass}
                    value={telegramChatId}
                    onChange={(e) => setTelegramChatId(e.target.value)}
                    placeholder={backupStatus.data?.telegram.chat_id_set ? t('settings.secretKeepHint') : '-100...'}
                    autoComplete="off"
                    inputMode="numeric"
                  />
                  <p className="mt-1 text-xs text-black/45">{t('settings.telegramChatHint')}</p>
                </Field>

                {(backupStatus.data?.telegram.last_success_at || backupStatus.data?.telegram.last_error) && (
                  <div className="space-y-1 text-xs text-black/55">
                    {backupStatus.data.telegram.last_success_at && (
                      <p>{t('settings.lastSuccess')}: {formatDateTimeLocal(backupStatus.data.telegram.last_success_at)}</p>
                    )}
                    {backupStatus.data.telegram.last_error && (
                      <p className="text-danger">{t('settings.lastError')}: {backupStatus.data.telegram.last_error}</p>
                    )}
                  </div>
                )}

                <div className="flex flex-wrap gap-2 pt-1">
                  <Button
                    type="button"
                    variant="primary"
                    disabled={saveTelegram.isPending || (!telegramToken.trim() && !telegramChatId.trim())}
                    onClick={() => saveTelegram.mutate()}
                  >
                    {t('settings.save')}
                  </Button>
                  <Button
                    type="button"
                    variant="secondary"
                    disabled={testTelegram.isPending || !backupStatus.data?.telegram.configured}
                    onClick={() => testTelegram.mutate()}
                  >
                    {t('settings.testSend')}
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    disabled={disconnectTelegram.isPending || backupStatus.data?.telegram.source === 'none'}
                    onClick={() => {
                      if (window.confirm(t('settings.disconnectConfirm'))) disconnectTelegram.mutate()
                    }}
                  >
                    {t('settings.disconnect')}
                  </Button>
                </div>
              </div>
            </div>
          </Panel>

          <Panel className="space-y-4 p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="font-semibold">{t('settings.dbBackup')}</h2>
                <p className="text-xs text-black/50">{t('settings.dbBackupHint')}</p>
              </div>
              <div className="flex flex-wrap gap-2">
                <ExcelExportButton
                  path="/exports/full"
                  label={t('settings.exportFullExcel')}
                  fileName={`syna-full-archive-${new Date().toISOString().slice(0, 10)}.xlsx`}
                />
                <Button variant="primary" onClick={() => createBackup.mutate()} disabled={createBackup.isPending}>
                  {t('settings.createBackupNow')}
                </Button>
                <Button
                  variant="secondary"
                  disabled={restoreBackupFromFile.isPending}
                  onClick={() => backupFileInputRef.current?.click()}
                >
                  {restoreBackupFromFile.isPending ? t('settings.restoringFromFile') : t('settings.restoreFromFile')}
                </Button>
                <input
                  ref={backupFileInputRef}
                  type="file"
                  accept=".sql,.dump,.backup,.gz,application/gzip,application/sql"
                  className="hidden"
                  onChange={(e) => {
                    const file = e.target.files?.[0]
                    e.target.value = ''
                    if (!file) return
                    if (!window.confirm(t('settings.restoreFromFileConfirm'))) return
                    restoreBackupFromFile.mutate(file)
                  }}
                />
              </div>
            </div>
            <p className="text-xs text-black/50">{t('settings.exportFullExcelHint')}</p>

            {backups.isError && (
              <p className="text-sm text-danger">{t('settings.backupListError')}</p>
            )}
            {backups.isLoading && <LoadingBlock />}
            {!backups.isLoading && !(backups.data || []).length && !backups.isError && (
              <EmptyState title={t('settings.noBackups')} description={t('settings.noBackupsHint')} />
            )}
            <ul className="divide-y divide-[var(--color-line)]">
              {(backups.data || []).map((b) => {
                const isExcel = b.kind === 'excel' || b.filename.toLowerCase().endsWith('.xlsx')
                return (
                <li key={b.filename} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                  <div>
                    <p className="font-mono text-xs sm:text-sm">{b.filename}</p>
                    <p className="text-xs text-black/45">
                      {isExcel ? t('settings.backupKindExcel') : t('settings.backupKindSql')}
                      {' · '}
                      {b.size_human} · {formatDateTimeLocal(b.created_at)}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={() => void downloadBackup(b.filename)}>{t('settings.download')}</Button>
                    {!isExcel && (
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
                    )}
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
                )
              })}
            </ul>
          </Panel>
        </div>
      )}

      {tab === 'whatsapp' && (
        <div className="space-y-4">
          <Panel className="space-y-4 p-5">
            <h2 className="font-semibold">{t('settings.whatsappSection')}</h2>
            <div className="rounded-lg border border-[var(--color-line)] p-3 text-sm">
              <p className="font-medium">{t('settings.whatsappStatus')}</p>
              <p className={`mt-1 text-xs ${whatsappStatus.data?.configured ? 'text-success' : 'text-black/45'}`}>
                {whatsappStatus.data?.configured ? t('settings.whatsappConnected') : t('settings.whatsappDisconnected')}
              </p>
            </div>
            <div className="space-y-2 text-sm text-black/70">
              <p className="font-medium">{t('settings.whatsappHowTitle')}</p>
              <ol className="list-decimal space-y-1.5 pr-5">
                <li>{t('settings.whatsappHow1')}</li>
                <li>{t('settings.whatsappHow2')}</li>
                <li>{t('settings.whatsappHow3')}</li>
              </ol>
            </div>
            <p className="rounded-lg border border-black/10 bg-mist/60 px-3 py-2 text-xs text-black/65">
              {t('settings.whatsappEnvHint')}
            </p>
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
            {search.debouncedQ && !(usersAdmin.data || []).length ? (
              <EmptyState title={t('common.noSearchResults')} />
            ) : null}
            <table className="data-table text-sm">
              <thead>
                <tr>
                  <th>{t('settings.name')}</th>
                  <th>{t('settings.username')}</th>
                  <th>{t('settings.mobile')}</th>
                  <th>{t('settings.email')}</th>
                  <th>{t('settings.roles')}</th>
                  <th>{t('common.status')}</th>
                </tr>
              </thead>
              <tbody>
                {(usersAdmin.data || []).map((u) => (
                  <tr
                    key={u.id}
                    className="cursor-pointer"
                    onClick={() => {
                      setEditingUserId(u.id)
                      setUserForm({
                        first_name: u.first_name || '',
                        last_name: u.last_name || '',
                        username: u.username || '',
                        mobile: u.mobile || '',
                        email: u.email || '',
                        password: '',
                        roles: u.roles.length ? u.roles : ['accountant'],
                        warehouse_ids: u.warehouse_ids || [],
                      })
                      setUserModal('edit')
                    }}
                  >
                    <td>{u.name}</td>
                    <td className="font-mono text-xs">{u.username || '—'}</td>
                    <td>{u.mobile || '—'}</td>
                    <td>{u.email}</td>
                    <td>{u.roles.map((r) => roleLabel(t, r)).join(', ')}</td>
                    <td>{u.is_active ? t('common.active') : t('common.inactive')}</td>
                  </tr>
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
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('settings.firstName')}><input className={inputClass} value={userForm.first_name} onChange={(e) => setUserForm({ ...userForm, first_name: e.target.value })} required /></Field>
            <Field label={t('settings.lastName')}><input className={inputClass} value={userForm.last_name} onChange={(e) => setUserForm({ ...userForm, last_name: e.target.value })} required /></Field>
          </div>
          <Field label={t('settings.username')}><input className={inputClass} value={userForm.username} onChange={(e) => setUserForm({ ...userForm, username: e.target.value })} required autoComplete="username" /></Field>
          <Field label={t('settings.mobile')}><input className={inputClass} value={userForm.mobile} onChange={(e) => setUserForm({ ...userForm, mobile: e.target.value })} required inputMode="tel" /></Field>
          <Field label={t('settings.email')} hint={t('settings.emailOptionalHint')}><input type="email" className={inputClass} value={userForm.email} onChange={(e) => setUserForm({ ...userForm, email: e.target.value })} /></Field>
          <Field label={t('settings.password')} hint={userModal === 'edit' ? t('settings.passwordKeepHint') : undefined}><input type="password" className={inputClass} value={userForm.password} onChange={(e) => setUserForm({ ...userForm, password: e.target.value })} required={userModal === 'create'} minLength={8} /></Field>
          <Field label={t('settings.roles')}><select className={inputClass} value={userForm.roles[0]} onChange={(e) => setUserForm({ ...userForm, roles: [e.target.value] })}>{(rolesAdmin.data?.roles || []).map((r) => <option key={r.id} value={r.name}>{roleLabel(t, r.name)}</option>)}</select></Field>
          {userForm.roles.includes('warehouse_manager') && (
            <Field label={t('settings.assignedWarehouses')} hint={t('settings.assignedWarehousesHint')}>
              <div className="grid gap-2 rounded-lg border border-[var(--color-line)] p-3 sm:grid-cols-2">
                {(warehousesAdmin.data || []).map((warehouse) => (
                  <label key={warehouse.id} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={userForm.warehouse_ids.includes(warehouse.id)}
                      onChange={(e) => setUserForm({
                        ...userForm,
                        warehouse_ids: e.target.checked
                          ? [...userForm.warehouse_ids, warehouse.id]
                          : userForm.warehouse_ids.filter((id) => id !== warehouse.id),
                      })}
                    />
                    <span>{warehouse.code} — {warehouse.name}</span>
                  </label>
                ))}
              </div>
            </Field>
          )}
        </form>
      </Modal>
    </div>
  )
}
