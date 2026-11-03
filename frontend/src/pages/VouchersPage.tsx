import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { todayYmd } from '@/lib/dates'
import { openPrintPopup } from '@/lib/printPopup'
import { useDocumentBrand, DocumentBrandProvider } from '@/context/DocumentBrandContext'
import {
  Button,
  Field,
  FormStack,
  NumericInput,
  PageHeader,
  Panel,
  Tabs,
  formatMoney,
  inputClass,
} from '@/components/ui'
import VoucherPrintView, { type VoucherKind, type VoucherPrintData } from '@/components/VoucherPrintView'
import { storeVoucherForPrint } from '@/lib/voucherPrint'

const METHODS = ['cash', 'cheque', 'transfer'] as const

function suggestNumber(kind: VoucherKind) {
  const prefix = kind === 'receipt' ? 'RV' : 'PV'
  const stamp = new Date()
  const y = stamp.getFullYear()
  const m = String(stamp.getMonth() + 1).padStart(2, '0')
  const d = String(stamp.getDate()).padStart(2, '0')
  const h = String(stamp.getHours()).padStart(2, '0')
  const min = String(stamp.getMinutes()).padStart(2, '0')
  return `${prefix}-${y}${m}${d}-${h}${min}`
}

function VouchersPageInner() {
  const { t } = useTranslation()
  const { brandId } = useDocumentBrand()
  const [kind, setKind] = useState<VoucherKind>('receipt')
  const [form, setForm] = useState({
    voucher_number: suggestNumber('receipt'),
    voucher_date: todayYmd(),
    party_name: '',
    amount: '',
    currency: 'USD',
    method: 'cash',
    description: '',
    notes: '',
  })

  const voucher: VoucherPrintData = useMemo(
    () => ({
      kind,
      voucher_number: form.voucher_number.trim(),
      voucher_date: form.voucher_date,
      party_name: form.party_name.trim(),
      amount: Number(form.amount) || 0,
      currency: form.currency || 'USD',
      method: form.method
        ? t(`vouchers.methods.${form.method}` as 'vouchers.methods.cash')
        : undefined,
      description: form.description.trim() || undefined,
      notes: form.notes.trim() || undefined,
    }),
    [kind, form, t],
  )

  const switchKind = (next: VoucherKind) => {
    setKind(next)
    setForm((prev) => ({
      ...prev,
      voucher_number: suggestNumber(next),
    }))
  }

  const openPrint = () => {
    storeVoucherForPrint(voucher)
    openPrintPopup('/print/vouchers')
  }

  return (
    <div className="space-y-4">
      <PageHeader
        title={t('nav.vouchers')}
        subtitle={t('vouchers.subtitle')}
        actions={
          <Button variant="primary" onClick={openPrint}>
            <Printer size={16} /> {t('common.print')}
          </Button>
        }
      />

      <Tabs
        tabs={[
          { id: 'receipt', label: t('vouchers.receiptVoucher') },
          { id: 'payment', label: t('vouchers.paymentVoucher') },
        ]}
        active={kind}
        onChange={(id) => switchKind(id as VoucherKind)}
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel>
          <FormStack>
            <p className="text-xs leading-relaxed text-black/55">{t('vouchers.printOnlyHint')}</p>
            <div className="form-grid-2">
              <Field label={t('common.number')}>
                <input
                  className={inputClass}
                  value={form.voucher_number}
                  onChange={(e) => setForm({ ...form, voucher_number: e.target.value })}
                />
              </Field>
              <Field label={t('common.date')}>
                <input
                  type="date"
                  className={inputClass}
                  value={form.voucher_date}
                  onChange={(e) => setForm({ ...form, voucher_date: e.target.value })}
                />
              </Field>
              <Field label={kind === 'receipt' ? t('vouchers.receivedFrom') : t('vouchers.paidTo')}>
                <input
                  className={inputClass}
                  value={form.party_name}
                  onChange={(e) => setForm({ ...form, party_name: e.target.value })}
                  placeholder={t('vouchers.partyPlaceholder')}
                />
              </Field>
              <Field label={t('vouchers.method')}>
                <select
                  className={inputClass}
                  value={form.method}
                  onChange={(e) => setForm({ ...form, method: e.target.value })}
                >
                  {METHODS.map((m) => (
                    <option key={m} value={m}>{t(`vouchers.methods.${m}`)}</option>
                  ))}
                </select>
              </Field>
              <Field label={t('common.amount')}>
                <NumericInput
                  value={form.amount}
                  onChange={(v) => setForm({ ...form, amount: v })}
                />
              </Field>
              <Field label={t('common.currency')}>
                <select
                  className={inputClass}
                  value={form.currency}
                  onChange={(e) => setForm({ ...form, currency: e.target.value })}
                >
                  {['USD', 'SYP', 'TRY', 'EUR', 'SAR'].map((c) => (
                    <option key={c} value={c}>{c}</option>
                  ))}
                </select>
              </Field>
            </div>
            <Field label={t('vouchers.description')}>
              <input
                className={inputClass}
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
                placeholder={t('vouchers.descriptionPlaceholder')}
              />
            </Field>
            <Field label={t('common.notes')}>
              <textarea
                className={inputClass}
                rows={2}
                value={form.notes}
                onChange={(e) => setForm({ ...form, notes: e.target.value })}
              />
            </Field>
            <p className="text-sm text-black/60">
              {t('common.total')}:{' '}
              <strong className="tabular-nums text-ink">
                {formatMoney(Number(form.amount) || 0, form.currency)}
              </strong>
            </p>
            <div className="flex justify-end">
              <Button variant="primary" onClick={openPrint}>
                <Printer size={16} /> {t('common.print')}
              </Button>
            </div>
          </FormStack>
        </Panel>

        <Panel>
          <p className="mb-3 text-xs font-semibold text-black/55">{t('vouchers.previewHint')}</p>
          <div className="voucher-preview-frame" key={brandId}>
            <div className="print-sheet print-sheet--half-a4 voucher-preview-sheet">
              <div className="print-area">
                <VoucherPrintView voucher={voucher} />
              </div>
            </div>
          </div>
        </Panel>
      </div>
    </div>
  )
}

export default function VouchersPage() {
  return (
    <DocumentBrandProvider>
      <VouchersPageInner />
    </DocumentBrandProvider>
  )
}
