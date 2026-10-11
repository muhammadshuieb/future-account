import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Cable, Plus, Printer, Save, Trash2 } from 'lucide-react'
import api from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { openPrintPopup } from '@/lib/printPopup'
import { todayYmd } from '@/lib/dates'
import SelectOrAdd from '@/components/SelectOrAdd'
import FtthReportView, {
  type FtthDailyReport,
  type FtthMaterialRow,
  type FtthNextDayRow,
  type FtthResourceRow,
  type FtthWorkRow,
} from '@/components/FtthReportView'
import {
  FTTH_ACTIVITIES,
  FTTH_MATERIALS,
  FTTH_RESOURCES,
  FTTH_TEAMS,
  FTTH_UNITS,
  FTTH_WEATHER,
  FTTH_WORK_TYPES,
  presetForActivity,
  unitForMaterial,
  unitForResource,
  unitForWorkType,
} from '@/lib/ftthCatalog'
import {
  Button,
  Field,
  ListSearchInput,
  LoadingBlock,
  Msg,
  NumericInput,
  PageHeader,
  Panel,
  Tabs,
  formatQuantity,
  inputClass,
  useFormMessage,
} from '@/components/ui'
import { useListSearch } from '@/lib/useListSearch'

type SectorRow = {
  id?: number
  sort_order?: number
  name?: string
  from_point?: string
  to_point?: string
  planned_length_m?: number | string
  excavation_done_m?: number | string
  duct_done_m?: number | string
  manholes_done?: number | string
  notes?: string
  remaining_excavation_m?: number
  completion_pct?: number
}

type TabId = 'list' | 'daily' | 'plan' | 'sectors' | 'preview'

const emptyReport = (): FtthDailyReport => ({
  report_number: 'جديد',
  report_date: todayYmd(),
  project_name: 'مشروع FTTH – الأعمال المدنية',
  phase: 'الحفر وتمديد الدكت وتركيب المنهولات',
  weather: '',
  area_sector: '',
  supervisor: '',
  contractor_team: '',
  workers_count: 0,
  start_time: '',
  end_time: '',
  works: [],
  materials: [],
  next_day_plan: [],
  next_day_resources: [],
  obstacles: '',
  remediation: '',
  quality_notes: '',
  safety_notes: '',
  attachments_notes: '',
  next_day_obstacles: '',
  next_day_coordination: '',
  next_day_safety: '',
  next_day_inspection: '',
  next_day_goal: '',
  prepared_by: '',
  approved_by: '',
  status: 'draft',
})

function n(v: unknown): number {
  const x = Number(v)
  return Number.isFinite(x) ? x : 0
}

export default function FtthDailyReportPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const canManage = hasPermission('ftth.manage')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const search = useListSearch()
  const [tab, setTab] = useState<TabId>('list')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [form, setForm] = useState<FtthDailyReport>(emptyReport())
  const [sectors, setSectors] = useState<SectorRow[]>([])

  const list = useQuery({
    queryKey: ['ftth-daily-reports', search.debouncedQ],
    queryFn: async () => (await api.get('/ftth/daily-reports', { params: search.params })).data.data as FtthDailyReport[],
    enabled: hasPermission('ftth.view'),
  })

  const defaults = useQuery({
    queryKey: ['ftth-daily-report-defaults'],
    queryFn: async () => (await api.get('/ftth/daily-reports/defaults')).data.data as {
      works: FtthWorkRow[]
      materials: FtthMaterialRow[]
      next_day_plan: FtthNextDayRow[]
      next_day_resources: FtthResourceRow[]
      project_name: string
      phase: string
    },
    enabled: hasPermission('ftth.view'),
  })

  const detail = useQuery({
    queryKey: ['ftth-daily-report', selectedId],
    enabled: !!selectedId && hasPermission('ftth.view'),
    queryFn: async () => (await api.get(`/ftth/daily-reports/${selectedId}`)).data.data as FtthDailyReport,
  })

  const sectorsQ = useQuery({
    queryKey: ['ftth-sectors'],
    queryFn: async () => (await api.get('/ftth/sectors')).data.data as SectorRow[],
    enabled: hasPermission('ftth.view'),
  })

  useEffect(() => {
    if (detail.data) setForm(detail.data)
  }, [detail.data])

  useEffect(() => {
    if (sectorsQ.data) setSectors(sectorsQ.data)
  }, [sectorsQ.data])

  const areaOptions = useMemo(() => {
    const names = (sectorsQ.data || [])
      .map((s) => (s.name || '').trim())
      .filter(Boolean)
    return Array.from(new Set(names))
  }, [sectorsQ.data])

  const workTypeOptions = useMemo(() => {
    const fromForm = (form.works || []).map((w) => w.type).filter(Boolean)
    return Array.from(new Set([...FTTH_WORK_TYPES.map((w) => w.type), ...fromForm]))
  }, [form.works])

  const materialOptions = useMemo(() => {
    const fromForm = (form.materials || []).map((m) => m.item).filter(Boolean)
    return Array.from(new Set([...FTTH_MATERIALS.map((m) => m.item), ...fromForm]))
  }, [form.materials])

  const activityOptions = useMemo(() => {
    const fromForm = (form.next_day_plan || []).map((p) => p.activity).filter(Boolean)
    return Array.from(new Set([...FTTH_ACTIVITIES.map((a) => a.activity), ...fromForm]))
  }, [form.next_day_plan])

  const teamOptions = useMemo(() => {
    const fromForm = (form.next_day_plan || []).map((p) => p.team || '').filter(Boolean)
    return Array.from(new Set([...FTTH_TEAMS, ...fromForm]))
  }, [form.next_day_plan])

  const resourceOptions = useMemo(() => {
    const fromForm = (form.next_day_resources || []).map((r) => r.item).filter(Boolean)
    return Array.from(new Set([...FTTH_RESOURCES.map((r) => r.item), ...fromForm]))
  }, [form.next_day_resources])

  const summary = useMemo(() => {
    const works = form.works || []
    let planned = 0
    let actual = 0
    let duct = 0
    let manholes = 0
    for (const w of works) {
      const type = w.type || ''
      if (type.includes('الحفر')) {
        planned += n(w.planned)
        actual += n(w.actual)
      } else if (type.includes('الدكت')) duct += n(w.actual)
      else if (type.includes('المنهولات')) manholes += n(w.actual)
    }
    return {
      planned_excavation_m: planned,
      actual_excavation_m: actual,
      excavation_pct: planned > 0 ? Math.round((actual / planned) * 1000) / 10 : 0,
      duct_m: duct,
      manholes,
    }
  }, [form.works])

  const previewReport = useMemo(
    () => ({ ...form, summary }),
    [form, summary],
  )

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        workers_count: n(form.workers_count),
        works: form.works,
        materials: form.materials,
        next_day_plan: form.next_day_plan,
        next_day_resources: form.next_day_resources,
      }
      if (selectedId) {
        return (await api.put(`/ftth/daily-reports/${selectedId}`, payload)).data.data as FtthDailyReport
      }
      return (await api.post('/ftth/daily-reports', payload)).data.data as FtthDailyReport
    },
    onSuccess: (data) => {
      msg.setError('')
      msg.setMessage(t('ftth.saved'))
      setSelectedId(data.id || null)
      setForm(data)
      qc.invalidateQueries({ queryKey: ['ftth-daily-reports'] })
      qc.invalidateQueries({ queryKey: ['ftth-daily-report', data.id] })
    },
    onError: () => { msg.setMessage(''); msg.setError(t('ftth.saveFailed')) },
  })

  const remove = useMutation({
    mutationFn: async (id: number) => api.delete(`/ftth/daily-reports/${id}`),
    onSuccess: () => {
      msg.setError('')
      msg.setMessage(t('common.deleted'))
      setSelectedId(null)
      setForm(emptyReport())
      qc.invalidateQueries({ queryKey: ['ftth-daily-reports'] })
      setTab('list')
    },
    onError: () => { msg.setMessage(''); msg.setError(t('ftth.deleteFailed')) },
  })

  const saveSectors = useMutation({
    mutationFn: async () => (await api.put('/ftth/sectors', { sectors })).data.data as SectorRow[],
    onSuccess: (data) => {
      msg.setError('')
      msg.setMessage(t('ftth.sectorsSaved'))
      setSectors(data)
      qc.invalidateQueries({ queryKey: ['ftth-sectors'] })
    },
    onError: () => { msg.setMessage(''); msg.setError(t('ftth.saveFailed')) },
  })

  if (!hasPermission('ftth.view')) {
    return <div className="page-layout"><Panel><p className="p-6 text-sm text-danger">{t('ftth.noPermission')}</p></Panel></div>
  }

  function startNew() {
    const d = defaults.data
    setSelectedId(null)
    setForm({
      ...emptyReport(),
      project_name: d?.project_name || emptyReport().project_name,
      phase: d?.phase || emptyReport().phase,
      works: [],
      materials: [],
      next_day_plan: [],
      next_day_resources: [],
    })
    setTab('daily')
  }

  function openReport(id: number) {
    setSelectedId(id)
    setTab('daily')
  }

  function patchWork(i: number, key: keyof FtthWorkRow, value: string | number) {
    setForm((prev) => {
      const works = [...(prev.works || [])]
      const row = { ...works[i], [key]: value }
      if (key === 'type' && typeof value === 'string') {
        row.unit = unitForWorkType(value) || row.unit || 'متر'
      }
      works[i] = row
      return { ...prev, works }
    })
  }

  function patchMaterial(i: number, key: keyof FtthMaterialRow, value: string | number) {
    setForm((prev) => {
      const materials = [...(prev.materials || [])]
      const row = { ...materials[i], [key]: value }
      if (key === 'item' && typeof value === 'string') {
        row.unit = unitForMaterial(value) || row.unit || 'عدد'
      }
      materials[i] = row
      return { ...prev, materials }
    })
  }

  function patchPlan(i: number, key: keyof FtthNextDayRow, value: string | number) {
    setForm((prev) => {
      const next_day_plan = [...(prev.next_day_plan || [])]
      const row = { ...next_day_plan[i], [key]: value }
      if (key === 'activity' && typeof value === 'string') {
        const preset = presetForActivity(value)
        if (preset) {
          row.unit = preset.unit
          if (!row.team) row.team = preset.team
          if (!row.resources) row.resources = preset.resources
        }
      }
      next_day_plan[i] = row
      return { ...prev, next_day_plan }
    })
  }

  function patchResource(i: number, key: keyof FtthResourceRow, value: string) {
    setForm((prev) => {
      const next_day_resources = [...(prev.next_day_resources || [])]
      const row = { ...next_day_resources[i], [key]: value }
      if (key === 'item') row.unit = unitForResource(value) || row.unit || 'عدد'
      next_day_resources[i] = row
      return { ...prev, next_day_resources }
    })
  }

  function addWork(type?: string) {
    const picked = type || FTTH_WORK_TYPES[0]?.type || 'أعمال أخرى'
    setForm((prev) => ({
      ...prev,
      works: [
        ...(prev.works || []),
        { type: picked, location: '', unit: unitForWorkType(picked), planned: 0, actual: 0, notes: '' },
      ],
    }))
  }

  function addMaterial(item?: string) {
    const picked = item || FTTH_MATERIALS[0]?.item || 'بند جديد'
    setForm((prev) => ({
      ...prev,
      materials: [
        ...(prev.materials || []),
        { item: picked, unit: unitForMaterial(picked), opening: 0, received: 0, used: 0, notes: '' },
      ],
    }))
  }

  function addPlan(activity?: string) {
    const picked = activity || FTTH_ACTIVITIES[0]?.activity || 'أعمال أخرى'
    const preset = presetForActivity(picked)
    setForm((prev) => ({
      ...prev,
      next_day_plan: [
        ...(prev.next_day_plan || []),
        {
          activity: picked,
          location: '',
          unit: preset?.unit || 'متر',
          target: 0,
          team: preset?.team || '',
          resources: preset?.resources || '',
          notes: '',
        },
      ],
    }))
  }

  function addResource(item?: string) {
    const picked = item || FTTH_RESOURCES[0]?.item || 'بند جديد'
    setForm((prev) => ({
      ...prev,
      next_day_resources: [
        ...(prev.next_day_resources || []),
        { item: picked, quantity: '', unit: unitForResource(picked), location: '', needed_at: '', owner: '', notes: '' },
      ],
    }))
  }

  function removeAt<K extends 'works' | 'materials' | 'next_day_plan' | 'next_day_resources'>(key: K, index: number) {
    setForm((prev) => ({
      ...prev,
      [key]: ((prev[key] as unknown[]) || []).filter((_, i) => i !== index),
    }))
  }

  const tabs = [
    { id: 'list', label: t('ftth.tabList') },
    { id: 'daily', label: t('ftth.tabDaily') },
    { id: 'plan', label: t('ftth.tabPlan') },
    { id: 'sectors', label: t('ftth.tabSectors') },
    { id: 'preview', label: t('ftth.tabPreview') },
  ]

  return (
    <div className="page-layout">
      <PageHeader
        title={t('ftth.title')}
        subtitle={t('ftth.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ListSearchInput value={search.q} onChange={search.setQ} placeholder={t('ftth.searchPlaceholder')} />
            {canManage && (
              <Button variant="primary" onClick={startNew}>
                <Plus size={16} /> {t('ftth.newReport')}
              </Button>
            )}
            {canManage && (tab === 'daily' || tab === 'plan' || tab === 'preview') && (
                <Button variant="secondary" onClick={() => save.mutate()} disabled={save.isPending}>
                <Save size={16} /> {save.isPending ? t('common.loading') : t('common.save')}
              </Button>
            )}
            {selectedId ? (
              <Button variant="secondary" onClick={() => openPrintPopup(`/print/ftth-daily-reports/${selectedId}`)}>
                <Printer size={16} /> {t('common.print')}
              </Button>
            ) : null}
          </div>
        }
      />

      <div className="ftth-page-hero print-hide">
        <div className="ftth-page-hero__icon" aria-hidden>
          <Cable size={28} />
        </div>
        <div>
          <p className="ftth-page-hero__kicker">FTTH Civil Works</p>
          <h2 className="ftth-page-hero__title">{t('ftth.heroTitle')}</h2>
          <p className="ftth-page-hero__text">{t('ftth.heroText')}</p>
        </div>
        <div className="ftth-page-hero__stats">
          <div>
            <span>{t('ftth.kpiPlanned')}</span>
            <strong>{formatQuantity(summary.planned_excavation_m)} م</strong>
          </div>
          <div>
            <span>{t('ftth.kpiActual')}</span>
            <strong>{formatQuantity(summary.actual_excavation_m)} م</strong>
          </div>
          <div>
            <span>{t('ftth.kpiPct')}</span>
            <strong>{summary.excavation_pct}%</strong>
          </div>
        </div>
      </div>

      <Tabs tabs={tabs} active={tab} onChange={(id) => setTab(id as TabId)} />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'list' && (
        <Panel>
          {list.isLoading ? <LoadingBlock /> : (
            <div className="table-wrap">
              <table className="data-table text-sm">
                <thead>
                  <tr>
                    <th>{t('common.number')}</th>
                    <th>{t('common.date')}</th>
                    <th>{t('ftth.area')}</th>
                    <th>{t('ftth.supervisor')}</th>
                    <th>{t('ftth.excavationDone')}</th>
                    <th>{t('common.status')}</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {(list.data || []).map((r) => (
                    <tr key={r.id} className="cursor-pointer" onClick={() => openReport(r.id!)}>
                      <td className="font-mono text-xs">{r.report_number}</td>
                      <td>{r.report_date}</td>
                      <td>{r.area_sector || '—'}</td>
                      <td>{r.supervisor || '—'}</td>
                      <td className="tabular-nums">{formatQuantity(r.summary?.actual_excavation_m ?? 0)} م</td>
                      <td>{r.status === 'final' ? t('ftth.statusFinal') : t('ftth.statusDraft')}</td>
                      <td className="space-x-2 space-x-reverse">
                        <button type="button" className="text-xs text-teal" onClick={(e) => { e.stopPropagation(); openReport(r.id!) }}>{t('common.edit')}</button>
                        <button type="button" className="text-xs text-teal" onClick={(e) => { e.stopPropagation(); openPrintPopup(`/print/ftth-daily-reports/${r.id}`) }}>{t('common.print')}</button>
                        {canManage ? (
                          <button
                            type="button"
                            className="text-xs text-rose-600"
                            onClick={(e) => {
                              e.stopPropagation()
                              if (window.confirm(t('common.confirmDelete'))) remove.mutate(r.id!)
                            }}
                          >
                            {t('common.delete')}
                          </button>
                        ) : null}
                      </td>
                    </tr>
                  ))}
                  {!list.data?.length ? (
                    <tr><td colSpan={7} className="py-8 text-center text-black/45">{t('ftth.emptyList')}</td></tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      )}

      {(tab === 'daily' || tab === 'plan' || tab === 'preview') && detail.isLoading && selectedId ? <LoadingBlock /> : null}

      {tab === 'daily' && (
        <div className="space-y-4">
          <Panel>
            <h3 className="mb-3 text-sm font-bold text-[#064e51]">{t('ftth.generalInfo')}</h3>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              <Field label={t('ftth.projectName')}><input className={inputClass} value={form.project_name || ''} onChange={(e) => setForm({ ...form, project_name: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('common.date')}><input type="date" className={inputClass} value={form.report_date || ''} onChange={(e) => setForm({ ...form, report_date: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.weather')}>
                <SelectOrAdd
                  value={form.weather || ''}
                  options={[...FTTH_WEATHER]}
                  onChange={(v) => setForm({ ...form, weather: v })}
                  disabled={!canManage}
                  placeholder={t('ftth.selectItem')}
                  customLabel={t('ftth.addCustom')}
                />
              </Field>
              <Field label={t('ftth.area')}>
                <SelectOrAdd
                  value={form.area_sector || ''}
                  options={areaOptions}
                  onChange={(v) => setForm({ ...form, area_sector: v })}
                  disabled={!canManage}
                  placeholder={t('ftth.selectItem')}
                  customLabel={t('ftth.addCustom')}
                />
              </Field>
              <Field label={t('ftth.supervisor')}><input className={inputClass} value={form.supervisor || ''} onChange={(e) => setForm({ ...form, supervisor: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.contractor')}><input className={inputClass} value={form.contractor_team || ''} onChange={(e) => setForm({ ...form, contractor_team: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.workers')}><NumericInput className={inputClass} value={String(form.workers_count ?? 0)} onChange={(v) => setForm({ ...form, workers_count: n(v) })} disabled={!canManage} /></Field>
              <Field label={t('ftth.startTime')}><input type="time" className={inputClass} value={form.start_time || ''} onChange={(e) => setForm({ ...form, start_time: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.endTime')}><input type="time" className={inputClass} value={form.end_time || ''} onChange={(e) => setForm({ ...form, end_time: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('common.status')}>
                <select className={inputClass} value={form.status || 'draft'} onChange={(e) => setForm({ ...form, status: e.target.value })} disabled={!canManage}>
                  <option value="draft">{t('ftth.statusDraft')}</option>
                  <option value="final">{t('ftth.statusFinal')}</option>
                </select>
              </Field>
              <Field label={t('ftth.preparedBy')}><input className={inputClass} value={form.prepared_by || ''} onChange={(e) => setForm({ ...form, prepared_by: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.approvedBy')}><input className={inputClass} value={form.approved_by || ''} onChange={(e) => setForm({ ...form, approved_by: e.target.value })} disabled={!canManage} /></Field>
            </div>
          </Panel>

          <Panel>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-bold text-[#064e51]">{t('ftth.worksToday')}</h3>
              {canManage && (
                <Button variant="secondary" onClick={() => addWork()}>
                  <Plus size={14} /> {t('ftth.addWork')}
                </Button>
              )}
            </div>
            <div className="table-wrap">
              <table className="data-table text-sm">
                <thead>
                  <tr>
                    <th>م</th>
                    <th>{t('ftth.workType')}</th>
                    <th>{t('ftth.location')}</th>
                    <th>{t('common.unit')}</th>
                    <th>{t('ftth.planned')}</th>
                    <th>{t('ftth.actual')}</th>
                    <th>{t('common.notes')}</th>
                    {canManage ? <th></th> : null}
                  </tr>
                </thead>
                <tbody>
                  {(form.works || []).map((w, i) => (
                    <tr key={i}>
                      <td>{i + 1}</td>
                      <td className="min-w-[10rem]">
                        <SelectOrAdd
                          value={w.type || ''}
                          options={workTypeOptions}
                          onChange={(v) => patchWork(i, 'type', v)}
                          disabled={!canManage}
                          placeholder={t('ftth.selectItem')}
                          customLabel={t('ftth.addCustom')}
                        />
                      </td>
                      <td><input className={inputClass} value={w.location || ''} onChange={(e) => patchWork(i, 'location', e.target.value)} disabled={!canManage} /></td>
                      <td>
                        <SelectOrAdd
                          value={w.unit || ''}
                          options={[...FTTH_UNITS]}
                          onChange={(v) => patchWork(i, 'unit', v)}
                          disabled={!canManage}
                          placeholder={t('ftth.selectItem')}
                          customLabel={t('ftth.addCustom')}
                        />
                      </td>
                      <td><NumericInput className={inputClass} value={String(w.planned ?? 0)} onChange={(v) => patchWork(i, 'planned', n(v))} disabled={!canManage} /></td>
                      <td><NumericInput className={inputClass} value={String(w.actual ?? 0)} onChange={(v) => patchWork(i, 'actual', n(v))} disabled={!canManage} /></td>
                      <td><input className={inputClass} value={w.notes || ''} onChange={(e) => patchWork(i, 'notes', e.target.value)} disabled={!canManage} /></td>
                      {canManage ? (
                        <td>
                          <button type="button" className="text-rose-600" onClick={() => removeAt('works', i)} aria-label={t('common.delete')}>
                            <Trash2 size={14} />
                          </button>
                        </td>
                      ) : null}
                    </tr>
                  ))}
                  {!form.works?.length ? (
                    <tr><td colSpan={canManage ? 8 : 7} className="py-6 text-center text-black/40">{t('ftth.emptyRows')}</td></tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </Panel>

          <Panel>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-bold text-[#064e51]">{t('ftth.materials')}</h3>
              {canManage && (
                <Button variant="secondary" onClick={() => addMaterial()}>
                  <Plus size={14} /> {t('ftth.addMaterial')}
                </Button>
              )}
            </div>
            <div className="table-wrap">
              <table className="data-table text-sm">
                <thead>
                  <tr>
                    <th>م</th>
                    <th>{t('ftth.materialItem')}</th>
                    <th>{t('common.unit')}</th>
                    <th>{t('ftth.opening')}</th>
                    <th>{t('ftth.received')}</th>
                    <th>{t('ftth.used')}</th>
                    <th>{t('ftth.closing')}</th>
                    <th>{t('common.notes')}</th>
                    {canManage ? <th></th> : null}
                  </tr>
                </thead>
                <tbody>
                  {(form.materials || []).map((m, i) => {
                    const closing = n(m.opening) + n(m.received) - n(m.used)
                    return (
                      <tr key={i}>
                        <td>{i + 1}</td>
                        <td className="min-w-[10rem]">
                          <SelectOrAdd
                            value={m.item || ''}
                            options={materialOptions}
                            onChange={(v) => patchMaterial(i, 'item', v)}
                            disabled={!canManage}
                            placeholder={t('ftth.selectItem')}
                            customLabel={t('ftth.addCustom')}
                          />
                        </td>
                        <td>
                          <SelectOrAdd
                            value={m.unit || ''}
                            options={[...FTTH_UNITS]}
                            onChange={(v) => patchMaterial(i, 'unit', v)}
                            disabled={!canManage}
                            placeholder={t('ftth.selectItem')}
                            customLabel={t('ftth.addCustom')}
                          />
                        </td>
                        <td><NumericInput className={inputClass} value={String(m.opening ?? 0)} onChange={(v) => patchMaterial(i, 'opening', n(v))} disabled={!canManage} /></td>
                        <td><NumericInput className={inputClass} value={String(m.received ?? 0)} onChange={(v) => patchMaterial(i, 'received', n(v))} disabled={!canManage} /></td>
                        <td><NumericInput className={inputClass} value={String(m.used ?? 0)} onChange={(v) => patchMaterial(i, 'used', n(v))} disabled={!canManage} /></td>
                        <td className="tabular-nums font-medium">{formatQuantity(closing)}</td>
                        <td><input className={inputClass} value={m.notes || ''} onChange={(e) => patchMaterial(i, 'notes', e.target.value)} disabled={!canManage} /></td>
                        {canManage ? (
                          <td>
                            <button type="button" className="text-rose-600" onClick={() => removeAt('materials', i)} aria-label={t('common.delete')}>
                              <Trash2 size={14} />
                            </button>
                          </td>
                        ) : null}
                      </tr>
                    )
                  })}
                  {!form.materials?.length ? (
                    <tr><td colSpan={canManage ? 9 : 8} className="py-6 text-center text-black/40">{t('ftth.emptyRows')}</td></tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </Panel>

          <Panel>
            <h3 className="mb-3 text-sm font-bold text-[#064e51]">{t('ftth.qualitySafety')}</h3>
            <div className="grid gap-3 md:grid-cols-2">
              <Field label={t('ftth.obstacles')}><textarea className={inputClass} rows={3} value={form.obstacles || ''} onChange={(e) => setForm({ ...form, obstacles: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.remediation')}><textarea className={inputClass} rows={3} value={form.remediation || ''} onChange={(e) => setForm({ ...form, remediation: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.qualityNotes')}><textarea className={inputClass} rows={3} value={form.quality_notes || ''} onChange={(e) => setForm({ ...form, quality_notes: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.safetyNotes')}><textarea className={inputClass} rows={3} value={form.safety_notes || ''} onChange={(e) => setForm({ ...form, safety_notes: e.target.value })} disabled={!canManage} /></Field>
              <div className="md:col-span-2">
                <Field label={t('ftth.attachmentsNotes')}><textarea className={inputClass} rows={2} value={form.attachments_notes || ''} onChange={(e) => setForm({ ...form, attachments_notes: e.target.value })} disabled={!canManage} /></Field>
              </div>
            </div>
          </Panel>
        </div>
      )}

      {tab === 'plan' && (
        <div className="space-y-4">
          <Panel>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-bold text-[#064e51]">{t('ftth.nextDayWorks')}</h3>
              {canManage && (
                <Button variant="secondary" onClick={() => addPlan()}>
                  <Plus size={14} /> {t('ftth.addActivity')}
                </Button>
              )}
            </div>
            <div className="table-wrap">
              <table className="data-table text-sm">
                <thead>
                  <tr>
                    <th>م</th>
                    <th>{t('ftth.activity')}</th>
                    <th>{t('ftth.location')}</th>
                    <th>{t('ftth.target')}</th>
                    <th>{t('ftth.team')}</th>
                    <th>{t('ftth.resources')}</th>
                    <th>{t('common.notes')}</th>
                    {canManage ? <th></th> : null}
                  </tr>
                </thead>
                <tbody>
                  {(form.next_day_plan || []).map((p, i) => (
                    <tr key={i}>
                      <td>{i + 1}</td>
                      <td className="min-w-[10rem]">
                        <SelectOrAdd
                          value={p.activity || ''}
                          options={activityOptions}
                          onChange={(v) => patchPlan(i, 'activity', v)}
                          disabled={!canManage}
                          placeholder={t('ftth.selectItem')}
                          customLabel={t('ftth.addCustom')}
                        />
                      </td>
                      <td><input className={inputClass} value={p.location || ''} onChange={(e) => patchPlan(i, 'location', e.target.value)} disabled={!canManage} /></td>
                      <td><NumericInput className={inputClass} value={String(p.target ?? 0)} onChange={(v) => patchPlan(i, 'target', n(v))} disabled={!canManage} /></td>
                      <td className="min-w-[8rem]">
                        <SelectOrAdd
                          value={p.team || ''}
                          options={teamOptions}
                          onChange={(v) => patchPlan(i, 'team', v)}
                          disabled={!canManage}
                          placeholder={t('ftth.selectItem')}
                          customLabel={t('ftth.addCustom')}
                        />
                      </td>
                      <td><input className={inputClass} value={p.resources || ''} onChange={(e) => patchPlan(i, 'resources', e.target.value)} disabled={!canManage} /></td>
                      <td><input className={inputClass} value={p.notes || ''} onChange={(e) => patchPlan(i, 'notes', e.target.value)} disabled={!canManage} /></td>
                      {canManage ? (
                        <td>
                          <button type="button" className="text-rose-600" onClick={() => removeAt('next_day_plan', i)} aria-label={t('common.delete')}>
                            <Trash2 size={14} />
                          </button>
                        </td>
                      ) : null}
                    </tr>
                  ))}
                  {!form.next_day_plan?.length ? (
                    <tr><td colSpan={canManage ? 8 : 7} className="py-6 text-center text-black/40">{t('ftth.emptyRows')}</td></tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </Panel>
          <Panel>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-bold text-[#064e51]">{t('ftth.nextDayResources')}</h3>
              {canManage && (
                <Button variant="secondary" onClick={() => addResource()}>
                  <Plus size={14} /> {t('ftth.addResource')}
                </Button>
              )}
            </div>
            <div className="table-wrap">
              <table className="data-table text-sm">
                <thead>
                  <tr>
                    <th>م</th>
                    <th>{t('ftth.materialItem')}</th>
                    <th>{t('common.quantity')}</th>
                    <th>{t('common.unit')}</th>
                    <th>{t('ftth.location')}</th>
                    <th>{t('ftth.neededAt')}</th>
                    <th>{t('ftth.owner')}</th>
                    <th>{t('common.notes')}</th>
                    {canManage ? <th></th> : null}
                  </tr>
                </thead>
                <tbody>
                  {(form.next_day_resources || []).map((r, i) => (
                    <tr key={i}>
                      <td>{i + 1}</td>
                      <td className="min-w-[10rem]">
                        <SelectOrAdd
                          value={r.item || ''}
                          options={resourceOptions}
                          onChange={(v) => patchResource(i, 'item', v)}
                          disabled={!canManage}
                          placeholder={t('ftth.selectItem')}
                          customLabel={t('ftth.addCustom')}
                        />
                      </td>
                      <td><input className={inputClass} value={r.quantity || ''} onChange={(e) => patchResource(i, 'quantity', e.target.value)} disabled={!canManage} /></td>
                      <td>
                        <SelectOrAdd
                          value={r.unit || ''}
                          options={[...FTTH_UNITS]}
                          onChange={(v) => patchResource(i, 'unit', v)}
                          disabled={!canManage}
                          placeholder={t('ftth.selectItem')}
                          customLabel={t('ftth.addCustom')}
                        />
                      </td>
                      <td><input className={inputClass} value={r.location || ''} onChange={(e) => patchResource(i, 'location', e.target.value)} disabled={!canManage} /></td>
                      <td><input className={inputClass} value={r.needed_at || ''} onChange={(e) => patchResource(i, 'needed_at', e.target.value)} disabled={!canManage} /></td>
                      <td><input className={inputClass} value={r.owner || ''} onChange={(e) => patchResource(i, 'owner', e.target.value)} disabled={!canManage} /></td>
                      <td><input className={inputClass} value={r.notes || ''} onChange={(e) => patchResource(i, 'notes', e.target.value)} disabled={!canManage} /></td>
                      {canManage ? (
                        <td>
                          <button type="button" className="text-rose-600" onClick={() => removeAt('next_day_resources', i)} aria-label={t('common.delete')}>
                            <Trash2 size={14} />
                          </button>
                        </td>
                      ) : null}
                    </tr>
                  ))}
                  {!form.next_day_resources?.length ? (
                    <tr><td colSpan={canManage ? 9 : 8} className="py-6 text-center text-black/40">{t('ftth.emptyRows')}</td></tr>
                  ) : null}
                </tbody>
              </table>
            </div>
            <div className="mt-4 grid gap-3 md:grid-cols-2">
              <Field label={t('ftth.nextObstacles')}><textarea className={inputClass} rows={2} value={form.next_day_obstacles || ''} onChange={(e) => setForm({ ...form, next_day_obstacles: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.nextCoordination')}><textarea className={inputClass} rows={2} value={form.next_day_coordination || ''} onChange={(e) => setForm({ ...form, next_day_coordination: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.nextSafety')}><textarea className={inputClass} rows={2} value={form.next_day_safety || ''} onChange={(e) => setForm({ ...form, next_day_safety: e.target.value })} disabled={!canManage} /></Field>
              <Field label={t('ftth.nextInspection')}><textarea className={inputClass} rows={2} value={form.next_day_inspection || ''} onChange={(e) => setForm({ ...form, next_day_inspection: e.target.value })} disabled={!canManage} /></Field>
              <div className="md:col-span-2">
                <Field label={t('ftth.nextGoal')}><textarea className={inputClass} rows={2} value={form.next_day_goal || ''} onChange={(e) => setForm({ ...form, next_day_goal: e.target.value })} disabled={!canManage} /></Field>
              </div>
            </div>
          </Panel>
        </div>
      )}

      {tab === 'sectors' && (
        <Panel>
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-sm font-bold text-[#064e51]">{t('ftth.sectorsTitle')}</h3>
            {canManage && (
              <div className="flex gap-2">
                <Button
                  variant="secondary"
                  onClick={() => setSectors((prev) => [...prev, { sort_order: prev.length + 1, name: '', planned_length_m: 0, excavation_done_m: 0, duct_done_m: 0, manholes_done: 0 }])}
                >
                  <Plus size={14} /> {t('ftth.addSector')}
                </Button>
                <Button variant="primary" onClick={() => saveSectors.mutate()} disabled={saveSectors.isPending}>
                  <Save size={14} /> {t('common.save')}
                </Button>
              </div>
            )}
          </div>
          {sectorsQ.isLoading ? <LoadingBlock /> : (
            <div className="table-wrap">
              <table className="data-table text-sm">
                <thead>
                  <tr>
                    <th>م</th>
                    <th>{t('ftth.sectorName')}</th>
                    <th>{t('ftth.fromPoint')}</th>
                    <th>{t('ftth.toPoint')}</th>
                    <th>{t('ftth.plannedLength')}</th>
                    <th>{t('ftth.excavationDone')}</th>
                    <th>{t('ftth.ductDone')}</th>
                    <th>{t('ftth.manholesDone')}</th>
                    <th>{t('ftth.remaining')}</th>
                    <th>%</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {sectors.map((s, i) => {
                    const planned = n(s.planned_length_m)
                    const done = n(s.excavation_done_m)
                    const remaining = Math.max(0, planned - done)
                    const pct = planned > 0 ? Math.round((done / planned) * 1000) / 10 : 0
                    return (
                      <tr key={s.id || i}>
                        <td>{i + 1}</td>
                        <td><input className={inputClass} value={s.name || ''} onChange={(e) => setSectors((prev) => prev.map((row, idx) => idx === i ? { ...row, name: e.target.value } : row))} disabled={!canManage} /></td>
                        <td><input className={inputClass} value={s.from_point || ''} onChange={(e) => setSectors((prev) => prev.map((row, idx) => idx === i ? { ...row, from_point: e.target.value } : row))} disabled={!canManage} /></td>
                        <td><input className={inputClass} value={s.to_point || ''} onChange={(e) => setSectors((prev) => prev.map((row, idx) => idx === i ? { ...row, to_point: e.target.value } : row))} disabled={!canManage} /></td>
                        <td><NumericInput className={inputClass} value={String(s.planned_length_m ?? 0)} onChange={(v) => setSectors((prev) => prev.map((row, idx) => idx === i ? { ...row, planned_length_m: n(v) } : row))} disabled={!canManage} /></td>
                        <td><NumericInput className={inputClass} value={String(s.excavation_done_m ?? 0)} onChange={(v) => setSectors((prev) => prev.map((row, idx) => idx === i ? { ...row, excavation_done_m: n(v) } : row))} disabled={!canManage} /></td>
                        <td><NumericInput className={inputClass} value={String(s.duct_done_m ?? 0)} onChange={(v) => setSectors((prev) => prev.map((row, idx) => idx === i ? { ...row, duct_done_m: n(v) } : row))} disabled={!canManage} /></td>
                        <td><NumericInput className={inputClass} value={String(s.manholes_done ?? 0)} onChange={(v) => setSectors((prev) => prev.map((row, idx) => idx === i ? { ...row, manholes_done: n(v) } : row))} disabled={!canManage} /></td>
                        <td className="tabular-nums">{formatQuantity(remaining)}</td>
                        <td className="tabular-nums">{pct}%</td>
                        <td>
                          {canManage ? (
                            <button type="button" className="text-rose-600" onClick={() => setSectors((prev) => prev.filter((_, idx) => idx !== i))} aria-label={t('common.delete')}>
                              <Trash2 size={14} />
                            </button>
                          ) : null}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      )}

      {tab === 'preview' && (
        <Panel>
          <div className="print-sheet rounded-lg border border-black/8 bg-white p-4 shadow-sm">
            <div className="print-area">
              <FtthReportView report={previewReport} />
            </div>
          </div>
        </Panel>
      )}
    </div>
  )
}
