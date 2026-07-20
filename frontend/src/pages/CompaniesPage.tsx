import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import api from '@/lib/api'
import { Button, Field, Modal, Msg, PageHeader, Panel, Tabs, inputClass, useFormMessage } from '@/components/ui'

type CompanyRow = {
  id: number
  code: string
  name: string
  name_en?: string
  tax_number?: string
  currency?: string
  is_active: boolean
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

const emptyCo = { code: '', name: '', name_en: '', tax_number: '', currency: 'SYP' }
const emptyBr = { company_id: '', code: '', name: '', city: '', address: '', is_main: false }

export default function CompaniesPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<'companies' | 'branches'>('companies')
  const qc = useQueryClient()
  const msg = useFormMessage()
  const [modalOpen, setModalOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [coForm, setCoForm] = useState(emptyCo)
  const [brForm, setBrForm] = useState(emptyBr)

  const companies = useQuery({
    queryKey: ['companies'],
    queryFn: async () => (await api.get('/companies')).data.data as CompanyRow[],
  })

  const branches = useQuery({
    queryKey: ['branches'],
    queryFn: async () => (await api.get('/branches')).data.data as BranchRow[],
    enabled: tab === 'branches',
  })

  function closeModal() {
    setModalOpen(false)
    setEditingId(null)
  }

  function openCreate() {
    setEditingId(null)
    if (tab === 'companies') setCoForm(emptyCo)
    else setBrForm(emptyBr)
    msg.setError('')
    setModalOpen(true)
  }

  function openEditCompany(c: CompanyRow) {
    setEditingId(c.id)
    setCoForm({
      code: c.code,
      name: c.name,
      name_en: c.name_en || '',
      tax_number: c.tax_number || '',
      currency: c.currency || 'SYP',
    })
    setModalOpen(true)
  }

  function openEditBranch(b: BranchRow) {
    setEditingId(b.id)
    setBrForm({
      company_id: String(b.company_id),
      code: b.code,
      name: b.name,
      city: b.city || '',
      address: b.address || '',
      is_main: !!b.is_main,
    })
    setModalOpen(true)
  }

  const saveCompany = useMutation({
    mutationFn: () => {
      const payload = { ...coForm, is_active: true }
      if (editingId) return api.put(`/companies/${editingId}`, payload)
      return api.post('/companies', payload)
    },
    onSuccess: () => {
      msg.setMessage(t('companies.saved'))
      closeModal()
      void qc.invalidateQueries({ queryKey: ['companies'] })
    },
    onError: msg.fromErr,
  })

  const saveBranch = useMutation({
    mutationFn: () => {
      const payload = {
        ...brForm,
        company_id: Number(brForm.company_id),
        is_active: true,
      }
      if (editingId) return api.put(`/branches/${editingId}`, payload)
      return api.post('/branches', payload)
    },
    onSuccess: () => {
      msg.setMessage(t('companies.branchSaved'))
      closeModal()
      void qc.invalidateQueries({ queryKey: ['branches', 'companies'] })
    },
    onError: msg.fromErr,
  })

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('companies.title')}
        subtitle={t('companies.subtitle')}
        actions={<Button variant="primary" onClick={openCreate}>{t('common.add')}</Button>}
      />
      <Tabs
        tabs={[
          { id: 'companies', label: t('companies.tabCompanies') },
          { id: 'branches', label: t('companies.tabBranches') },
        ]}
        active={tab}
        onChange={(id) => {
          setTab(id as typeof tab)
          closeModal()
        }}
      />
      <Msg message={msg.message} error={msg.error} />

      {tab === 'companies' && (
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
                  <tr
                    key={c.id}
                    className="row-clickable"
                    onClick={() => openEditCompany(c)}
                    onKeyDown={(e) => e.key === 'Enter' && openEditCompany(c)}
                    tabIndex={0}
                    title={t('common.clickToEdit')}
                  >
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
      )}

      {tab === 'branches' && (
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
                  <tr
                    key={b.id}
                    className="row-clickable"
                    onClick={() => openEditBranch(b)}
                    onKeyDown={(e) => e.key === 'Enter' && openEditBranch(b)}
                    tabIndex={0}
                    title={t('common.clickToEdit')}
                  >
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
      )}

      <Modal
        open={modalOpen && tab === 'companies'}
        onClose={closeModal}
        title={editingId ? t('common.edit') : t('companies.newCompany')}
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button>
            <Button variant="primary" disabled={saveCompany.isPending} onClick={() => saveCompany.mutate()}>
              {t('common.save')}
            </Button>
          </>
        }
      >
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            saveCompany.mutate()
          }}
        >
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
        </form>
      </Modal>

      <Modal
        open={modalOpen && tab === 'branches'}
        onClose={closeModal}
        title={editingId ? t('common.edit') : t('companies.newBranch')}
        footer={
          <>
            <Button variant="secondary" onClick={closeModal}>{t('common.cancel')}</Button>
            <Button variant="primary" disabled={saveBranch.isPending} onClick={() => saveBranch.mutate()}>
              {t('common.save')}
            </Button>
          </>
        }
      >
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            saveBranch.mutate()
          }}
        >
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
        </form>
      </Modal>
    </div>
  )
}
