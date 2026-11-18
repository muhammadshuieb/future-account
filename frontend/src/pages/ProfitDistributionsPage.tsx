import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { todayYmd } from '@/lib/dates'
import { useListSearch } from '@/lib/useListSearch'
import { useTranslation } from 'react-i18next'
import {
  Button,
  EmptyState,
  Field,
  ListSearchInput,
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

type EquityPartner = {
  id: number
  code: string
  name: string
  share_percent: number
  account_id?: number | null
  phone?: string | null
  notes?: string | null
  is_active?: boolean
  account?: { id: number; code: string; name: string } | null
}

type CashBox = {
  id: number
  code: string
  name: string
  currency?: string
  balance?: number
}

type DistributionLine = {
  id: number
  equity_partner_id: number
  share_percent: number
  amount: number
  partner?: EquityPartner
}

type Distribution = {
  id: number
  distribution_number: string
  distribution_date: string
  cash_box_id: number
  total_amount: number
  currency: string
  status: string
  notes?: string | null
  cash_box?: CashBox
  lines?: DistributionLine[]
}

type GlAccount = { id: number; code: string; name: string; type?: string; is_group?: boolean; is_active?: boolean }

type LineDraft = { equity_partner_id: string; amount: string }

const emptyPartner = {
  code: '',
  name: '',
  share_percent: '',
  account_id: '',
  phone: '',
  notes: '',
  is_active: true,
}

function round2(n: number) {
  return Math.round(n * 100) / 100
}

export default function ProfitDistributionsPage() {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const msg = useFormMessage()
  const search = useListSearch()
  const [tab, setTab] = useState<'distributions' | 'partners'>('distributions')
  const [modalOpen, setModalOpen] = useState(false)
  const [editingPartnerId, setEditingPartnerId] = useState<number | null>(null)
  const [partnerForm, setPartnerForm] = useState(emptyPartner)
  const [splitMode, setSplitMode] = useState<'auto' | 'manual'>('auto')
  const [distForm, setDistForm] = useState({
    distribution_date: todayYmd(),
    cash_box_id: '',
    total_amount: '',
    notes: '',
    status: 'posted',
  })
  const [manualLines, setManualLines] = useState<LineDraft[]>([{ equity_partner_id: '', amount: '' }])

  const partners = useQuery({
    queryKey: ['equity-partners', tab === 'partners' ? search.debouncedQ : 'all'],
    queryFn: async () =>
      (await api.get('/equity-partners', { params: tab === 'partners' ? search.params : { active_only: 1 } })).data
        .data as EquityPartner[],
  })

  const distributions = useQuery({
    queryKey: ['profit-distributions', search.debouncedQ],
    queryFn: async () =>
      (await api.get('/profit-distributions', { params: search.params })).data.data as Distribution[],
    enabled: tab === 'distributions',
  })

  const cashBoxes = useQuery({
    queryKey: ['cash-boxes'],
    queryFn: async () => (await api.get('/cash-boxes')).data.data as CashBox[],
  })

  const accounts = useQuery({
    queryKey: ['accounts-flat-equity'],
    queryFn: async () =>
      (await api.get('/accounts', { params: { type: 'equity', postable_only: 1 } })).data.data as GlAccount[],
  })

  const activePartners = useMemo(
    () => (partners.data || []).filter((p) => p.is_active !== false && Number(p.share_percent) > 0),
    [partners.data],
  )

  const selectedBox = useMemo(
    () => (cashBoxes.data || []).find((b) => String(b.id) === distForm.cash_box_id),
    [cashBoxes.data, distForm.cash_box_id],
  )

  const previewAutoLines = useMemo(() => {
    const total = Number(distForm.total_amount) || 0
    if (total <= 0 || activePartners.length === 0) return []
    const shareSum = activePartners.reduce((s, p) => s + Number(p.share_percent), 0)
    if (shareSum <= 0) return []
    let allocated = 0
    return activePartners.map((p, i) => {
      const share = Number(p.share_percent)
      const amount =
        i === activePartners.length - 1
          ? round2(total - allocated)
          : round2((total * share) / shareSum)
      if (i < activePartners.length - 1) allocated += amount
      return { partner: p, amount, share }
    })
  }, [distForm.total_amount, activePartners])

  function closeModal() {
    setModalOpen(false)
    setEditingPartnerId(null)
  }

  function openCreate() {
    setEditingPartnerId(null)
    if (tab === 'partners') {
      setPartnerForm(emptyPartner)
    } else {
      setSplitMode('auto')
      setDistForm({
        distribution_date: todayYmd(),
        cash_box_id: cashBoxes.data?.[0] ? String(cashBoxes.data[0].id) : '',
        total_amount: '',
        notes: '',
        status: 'posted',
      })
      setManualLines([{ equity_partner_id: '', amount: '' }])
    }
    setModalOpen(true)
  }

  function openEditPartner(p: EquityPartner) {
    setEditingPartnerId(p.id)
    setPartnerForm({
      code: p.code,
      name: p.name,
      share_percent: String(p.share_percent ?? ''),
      account_id: p.account_id ? String(p.account_id) : '',
      phone: p.phone || '',
      notes: p.notes || '',
      is_active: p.is_active !== false,
    })
    setModalOpen(true)
  }

  const savePartner = useMutation({
    mutationFn: () => {
      const payload = {
        code: partnerForm.code.trim(),
        name: partnerForm.name.trim(),
        share_percent: Number(partnerForm.share_percent) || 0,
        account_id: partnerForm.account_id ? Number(partnerForm.account_id) : null,
        phone: partnerForm.phone || null,
        notes: partnerForm.notes || null,
        is_active: partnerForm.is_active,
      }
      if (editingPartnerId) return api.put(`/equity-partners/${editingPartnerId}`, payload)
      return api.post('/equity-partners', payload)
    },
    onSuccess: () => {
      msg.setMessage(t('profitDistributions.partnerSaved'))
      closeModal()
      void qc.invalidateQueries({ queryKey: ['equity-partners'] })
    },
    onError: msg.fromErr,
  })

  const saveDistribution = useMutation({
    mutationFn: () => {
      const box = selectedBox
      const payload: Record<string, unknown> = {
        distribution_date: distForm.distribution_date,
        cash_box_id: Number(distForm.cash_box_id),
        currency: box?.currency || 'USD',
        notes: distForm.notes || null,
        status: distForm.status,
      }
      if (splitMode === 'auto') {
        payload.auto_split = true
        payload.total_amount = Number(distForm.total_amount)
      } else {
        payload.lines = manualLines
          .filter((l) => l.equity_partner_id && Number(l.amount) > 0)
          .map((l) => ({
            equity_partner_id: Number(l.equity_partner_id),
            amount: Number(l.amount),
          }))
      }
      return api.post('/profit-distributions', payload)
    },
    onSuccess: () => {
      msg.setMessage(t('profitDistributions.saved'))
      closeModal()
      void qc.invalidateQueries({ queryKey: ['profit-distributions'] })
      void qc.invalidateQueries({ queryKey: ['cash-boxes'] })
    },
    onError: msg.fromErr,
  })

  const postDistribution = useMutation({
    mutationFn: (id: number) => api.post(`/profit-distributions/${id}/post`),
    onSuccess: () => {
      msg.setMessage(t('profitDistributions.posted'))
      void qc.invalidateQueries({ queryKey: ['profit-distributions'] })
      void qc.invalidateQueries({ queryKey: ['cash-boxes'] })
    },
    onError: msg.fromErr,
  })

  const deleteDistribution = useMutation({
    mutationFn: (id: number) => api.delete(`/profit-distributions/${id}`),
    onSuccess: () => {
      msg.setMessage(t('common.deleted'))
      void qc.invalidateQueries({ queryKey: ['profit-distributions'] })
    },
    onError: msg.fromErr,
  })

  return (
    <div className="page-layout">
      <PageHeader
        title={t('profitDistributions.title')}
        subtitle={t('profitDistributions.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ListSearchInput value={search.q} onChange={search.setQ} />
            <Button variant="primary" onClick={openCreate}>
              {tab === 'partners' ? t('profitDistributions.addPartner') : t('profitDistributions.addDistribution')}
            </Button>
          </div>
        }
      />

      <Tabs
        tabs={[
          { id: 'distributions', label: t('profitDistributions.tabDistributions') },
          { id: 'partners', label: t('profitDistributions.tabPartners') },
        ]}
        active={tab}
        onChange={(id) => {
          setTab(id as 'distributions' | 'partners')
          closeModal()
        }}
      />

      <Msg message={msg.message} error={msg.error} />

      {tab === 'distributions' && (
        <Panel>
          {search.debouncedQ && !(distributions.data || []).length ? (
            <EmptyState title={t('profitDistributions.noResults')} />
          ) : !(distributions.data || []).length ? (
            <EmptyState title={t('profitDistributions.emptyDistributions')} />
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr>
                  <th className="px-4 py-3">{t('profitDistributions.number')}</th>
                  <th className="px-4 py-3">{t('common.date')}</th>
                  <th className="px-4 py-3">{t('profitDistributions.cashBox')}</th>
                  <th className="px-4 py-3">{t('common.amount')}</th>
                  <th className="px-4 py-3">{t('common.status')}</th>
                  <th className="px-4 py-3">{t('profitDistributions.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {(distributions.data || []).map((d) => (
                  <tr key={d.id} className="border-t border-black/5">
                    <td className="px-4 py-3 font-mono text-xs">{d.distribution_number}</td>
                    <td className="px-4 py-3">{d.distribution_date}</td>
                    <td className="px-4 py-3">{d.cash_box?.name || '—'}</td>
                    <td className="px-4 py-3 tabular-nums">{formatMoney(Number(d.total_amount), d.currency)}</td>
                    <td className="px-4 py-3">{d.status === 'posted' ? t('common.posted') : t('common.draft')}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-2">
                        {d.status !== 'posted' && (
                          <>
                            <Button
                              variant="secondary"
                              onClick={() => postDistribution.mutate(d.id)}
                              disabled={postDistribution.isPending}
                            >
                              {t('common.post')}
                            </Button>
                            <Button
                              variant="ghost"
                              onClick={() => {
                                if (confirm(t('common.confirmDelete'))) deleteDistribution.mutate(d.id)
                              }}
                            >
                              {t('common.delete')}
                            </Button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      )}

      {tab === 'partners' && (
        <Panel>
          {search.debouncedQ && !(partners.data || []).length ? (
            <EmptyState title={t('profitDistributions.noResults')} />
          ) : !(partners.data || []).length ? (
            <EmptyState title={t('profitDistributions.emptyPartners')} />
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-mist text-right text-black/60">
                <tr>
                  <th className="px-4 py-3">{t('profitDistributions.code')}</th>
                  <th className="px-4 py-3">{t('common.name')}</th>
                  <th className="px-4 py-3">{t('profitDistributions.sharePercent')}</th>
                  <th className="px-4 py-3">{t('profitDistributions.linkedAccount')}</th>
                  <th className="px-4 py-3">{t('common.status')}</th>
                </tr>
              </thead>
              <tbody>
                {(partners.data || []).map((p) => (
                  <tr
                    key={p.id}
                    className="row-clickable border-t border-black/5"
                    onClick={() => openEditPartner(p)}
                  >
                    <td className="px-4 py-3 font-mono text-xs">{p.code}</td>
                    <td className="px-4 py-3">{p.name}</td>
                    <td className="px-4 py-3 tabular-nums">{Number(p.share_percent)}%</td>
                    <td className="px-4 py-3 text-xs text-black/60">
                      {p.account ? `${p.account.code} — ${p.account.name}` : t('profitDistributions.defaultDrawings')}
                    </td>
                    <td className="px-4 py-3">
                      {p.is_active !== false ? t('common.active') : t('common.inactive')}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      )}

      <Modal
        open={modalOpen}
        onClose={closeModal}
        title={
          tab === 'partners'
            ? editingPartnerId
              ? t('profitDistributions.editPartner')
              : t('profitDistributions.addPartner')
            : t('profitDistributions.addDistribution')
        }
      >
        {tab === 'partners' ? (
          <form
            className="space-y-3"
            onSubmit={(e) => {
              e.preventDefault()
              savePartner.mutate()
            }}
          >
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('profitDistributions.code')}>
                <input
                  className={inputClass}
                  value={partnerForm.code}
                  onChange={(e) => setPartnerForm({ ...partnerForm, code: e.target.value })}
                  required
                />
              </Field>
              <Field label={t('common.name')}>
                <input
                  className={inputClass}
                  value={partnerForm.name}
                  onChange={(e) => setPartnerForm({ ...partnerForm, name: e.target.value })}
                  required
                />
              </Field>
              <Field label={t('profitDistributions.sharePercent')}>
                <NumericInput
                  value={partnerForm.share_percent}
                  onChange={(v) => setPartnerForm({ ...partnerForm, share_percent: v })}
                />
              </Field>
              <Field label={t('profitDistributions.linkedAccount')}>
                <select
                  className={inputClass}
                  value={partnerForm.account_id}
                  onChange={(e) => setPartnerForm({ ...partnerForm, account_id: e.target.value })}
                >
                  <option value="">{t('profitDistributions.defaultDrawings')}</option>
                  {(accounts.data || []).map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.code} — {a.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={t('profitDistributions.phone')}>
                <input
                  className={inputClass}
                  value={partnerForm.phone}
                  onChange={(e) => setPartnerForm({ ...partnerForm, phone: e.target.value })}
                />
              </Field>
              <Field label={t('common.status')}>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={partnerForm.is_active}
                    onChange={(e) => setPartnerForm({ ...partnerForm, is_active: e.target.checked })}
                  />
                  {t('common.active')}
                </label>
              </Field>
            </div>
            <Field label={t('common.notes')}>
              <textarea
                className={inputClass}
                rows={2}
                value={partnerForm.notes}
                onChange={(e) => setPartnerForm({ ...partnerForm, notes: e.target.value })}
              />
            </Field>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="ghost" onClick={closeModal}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" variant="primary" disabled={savePartner.isPending}>
                {t('common.save')}
              </Button>
            </div>
          </form>
        ) : (
          <form
            className="space-y-3"
            onSubmit={(e) => {
              e.preventDefault()
              saveDistribution.mutate()
            }}
          >
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('common.date')}>
                <input
                  type="date"
                  className={inputClass}
                  value={distForm.distribution_date}
                  onChange={(e) => setDistForm({ ...distForm, distribution_date: e.target.value })}
                  required
                />
              </Field>
              <Field label={t('profitDistributions.cashBox')}>
                <select
                  className={inputClass}
                  value={distForm.cash_box_id}
                  onChange={(e) => setDistForm({ ...distForm, cash_box_id: e.target.value })}
                  required
                >
                  <option value="">{t('profitDistributions.selectCashBox')}</option>
                  {(cashBoxes.data || []).map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.code} — {b.name} ({b.currency || 'USD'})
                      {b.balance != null ? ` · ${formatMoney(Number(b.balance), b.currency || 'USD')}` : ''}
                    </option>
                  ))}
                </select>
              </Field>
            </div>

            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant={splitMode === 'auto' ? 'primary' : 'secondary'}
                onClick={() => setSplitMode('auto')}
              >
                {t('profitDistributions.autoSplit')}
              </Button>
              <Button
                type="button"
                variant={splitMode === 'manual' ? 'primary' : 'secondary'}
                onClick={() => setSplitMode('manual')}
              >
                {t('profitDistributions.manualSplit')}
              </Button>
            </div>

            {splitMode === 'auto' ? (
              <>
                <Field label={t('profitDistributions.totalAmount')}>
                  <NumericInput
                    value={distForm.total_amount}
                    onChange={(v) => setDistForm({ ...distForm, total_amount: v })}
                    required
                  />
                </Field>
                {previewAutoLines.length > 0 && (
                  <div className="rounded-lg border border-black/10 bg-mist/40 p-3 text-sm">
                    <p className="mb-2 font-medium">{t('profitDistributions.preview')}</p>
                    <ul className="space-y-1">
                      {previewAutoLines.map((row) => (
                        <li key={row.partner.id} className="flex justify-between gap-3">
                          <span>
                            {row.partner.name} ({row.share}%)
                          </span>
                          <span className="tabular-nums">
                            {formatMoney(row.amount, selectedBox?.currency || 'USD')}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </>
            ) : (
              <div className="space-y-2">
                {manualLines.map((line, idx) => (
                  <div key={idx} className="grid gap-2 sm:grid-cols-[1fr_8rem_auto]">
                    <select
                      className={inputClass}
                      value={line.equity_partner_id}
                      onChange={(e) => {
                        const next = [...manualLines]
                        next[idx] = { ...next[idx], equity_partner_id: e.target.value }
                        setManualLines(next)
                      }}
                      required
                    >
                      <option value="">{t('profitDistributions.selectPartner')}</option>
                      {(partners.data || [])
                        .filter((p) => p.is_active !== false)
                        .map((p) => (
                          <option key={p.id} value={p.id}>
                            {p.code} — {p.name}
                          </option>
                        ))}
                    </select>
                    <NumericInput
                      value={line.amount}
                      onChange={(v) => {
                        const next = [...manualLines]
                        next[idx] = { ...next[idx], amount: v }
                        setManualLines(next)
                      }}
                      required
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      onClick={() => setManualLines(manualLines.filter((_, i) => i !== idx))}
                      disabled={manualLines.length <= 1}
                    >
                      {t('common.delete')}
                    </Button>
                  </div>
                ))}
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => setManualLines([...manualLines, { equity_partner_id: '', amount: '' }])}
                >
                  {t('profitDistributions.addLine')}
                </Button>
              </div>
            )}

            <Field label={t('common.notes')}>
              <textarea
                className={inputClass}
                rows={2}
                value={distForm.notes}
                onChange={(e) => setDistForm({ ...distForm, notes: e.target.value })}
              />
            </Field>

            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="ghost" onClick={closeModal}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" variant="primary" disabled={saveDistribution.isPending}>
                {t('common.save')}
              </Button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  )
}
