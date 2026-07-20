import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { Field, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

type CompanyRow = {
  id: number
  code: string
  name: string
  name_en?: string
  tax_number?: string
  currency?: string
  is_active: boolean
  branches?: BranchRow[]
}

type BranchRow = {
  id: number
  company_id: number
  code: string
  name: string
  city?: string
  address?: string
  is_main?: boolean
  is_active: boolean
  company?: { name: string }
}

export default function CompaniesPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<'companies' | 'branches'>('companies')
  const qc = useQueryClient()
  const msg = useFormMessage()

  const companies = useQuery({
    queryKey: ['companies'],
    queryFn: async () => (await api.get('/companies')).data.data as CompanyRow[],
  })

  const branches = useQuery({
    queryKey: ['branches'],
    queryFn: async () => (await api.get('/branches')).data.data as BranchRow[],
    enabled: tab === 'branches',
  })

  const [coForm, setCoForm] = useState({ code: '', name: '', name_en: '', tax_number: '', currency: 'SYP' })
  const [brForm, setBrForm] = useState({ company_id: '', code: '', name: '', city: '', address: '', is_main: false })

  const saveCompany = useMutation({
    mutationFn: () => api.post('/companies', { ...coForm, is_active: true }),
    onSuccess: () => {
      msg.setMessage(t('companies.saved'))
      setCoForm({ code: '', name: '', name_en: '', tax_number: '', currency: 'SYP' })
      void qc.invalidateQueries({ queryKey: ['companies'] })
    },
    onError: msg.fromErr,
  })

  const saveBranch = useMutation({
    mutationFn: () =>
      api.post('/branches', {
        ...brForm,
        company_id: Number(brForm.company_id),
        is_active: true,
      }),
    onSuccess: () => {
      msg.setMessage(t('companies.branchSaved'))
      setBrForm({ company_id: '', code: '', name: '', city: '', address: '', is_main: false })
      void qc.invalidateQueries({ queryKey: ['branches', 'companies'] })
    },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-6">
      <PageHeader title={t('companies.title')} subtitle={t('companies.subtitle')} />
      <Tabs
        tabs={[
          { id: 'companies', label: t('companies.tabCompanies') },
          { id: 'branches', label: t('companies.tabBranches') },
        ]}
        active={tab}
        onChange={(id) => setTab(id as typeof tab)}
      />
      <Msg message={msg.message} />

      {tab === 'companies' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('companies.code')}</th>
                    <th>{t('companies.nameAr')}</th>
                    <th>{t('companies.nameEn')}</th>
                    <th>{t('common.currency')}</th>
                  </tr>
                </thead>
                <tbody>
                  {(companies.data || []).map((c) => (
                    <tr key={c.id}>
                      <td className="font-mono">{c.code}</td>
                      <td>{c.name}</td>
                      <td>{c.name_en || '—'}</td>
                      <td>{c.currency || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <form
            className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm"
            onSubmit={(e) => {
              e.preventDefault()
              saveCompany.mutate()
            }}
          >
            <h2 className="font-semibold">{t('companies.newCompany')}</h2>
            <Field label={t('companies.code')}>
              <input className={inputClass} value={coForm.code} onChange={(e) => setCoForm({ ...coForm, code: e.target.value })} required />
            </Field>
            <Field label={t('companies.nameAr')}>
              <input className={inputClass} value={coForm.name} onChange={(e) => setCoForm({ ...coForm, name: e.target.value })} required />
            </Field>
            <Field label={t('companies.nameEn')}>
              <input className={inputClass} value={coForm.name_en} onChange={(e) => setCoForm({ ...coForm, name_en: e.target.value })} />
            </Field>
            <Field label={t('companies.taxNumber')}>
              <input className={inputClass} value={coForm.tax_number} onChange={(e) => setCoForm({ ...coForm, tax_number: e.target.value })} />
            </Field>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">{t('common.save')}</button>
          </form>
        </div>
      )}

      {tab === 'branches' && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <Panel>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('companies.code')}</th>
                    <th>{t('companies.branchName')}</th>
                    <th>{t('companies.company')}</th>
                    <th>{t('companies.city')}</th>
                  </tr>
                </thead>
                <tbody>
                  {(branches.data || []).map((b) => (
                    <tr key={b.id}>
                      <td className="font-mono">{b.code}</td>
                      <td>{b.name}{b.is_main ? ' ★' : ''}</td>
                      <td>{b.company?.name || '—'}</td>
                      <td>{b.city || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <form
            className="space-y-3 rounded-2xl border border-black/5 bg-white p-4 shadow-sm"
            onSubmit={(e) => {
              e.preventDefault()
              saveBranch.mutate()
            }}
          >
            <h2 className="font-semibold">{t('companies.newBranch')}</h2>
            <Field label={t('companies.company')}>
              <select className={inputClass} value={brForm.company_id} onChange={(e) => setBrForm({ ...brForm, company_id: e.target.value })} required>
                <option value="">—</option>
                {(companies.data || []).map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <Field label={t('companies.code')}>
              <input className={inputClass} value={brForm.code} onChange={(e) => setBrForm({ ...brForm, code: e.target.value })} required />
            </Field>
            <Field label={t('companies.branchName')}>
              <input className={inputClass} value={brForm.name} onChange={(e) => setBrForm({ ...brForm, name: e.target.value })} required />
            </Field>
            <Field label={t('companies.city')}>
              <input className={inputClass} value={brForm.city} onChange={(e) => setBrForm({ ...brForm, city: e.target.value })} />
            </Field>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={brForm.is_main} onChange={(e) => setBrForm({ ...brForm, is_main: e.target.checked })} />
              {t('companies.mainBranch')}
            </label>
            <button type="submit" className="rounded-lg bg-teal px-4 py-2 text-white">{t('common.save')}</button>
          </form>
        </div>
      )}
    </div>
  )
}
