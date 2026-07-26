import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Field, NumericInput, inputClass } from '@/components/ui'

export type PaymentType = 'cash' | 'credit' | 'partial'

type CashBox = { id: number; name: string; currency?: string; is_default?: boolean; code?: string }

type Props<T extends {
  payment_type: string
  paid_amount: string
  cash_box_id: string
}> = {
  state: T
  setState: (next: T) => void
  cashBoxes: CashBox[]
  estimatedTotal?: number
  showTaxToggle?: boolean
  applyTax?: boolean
  onApplyTaxChange?: (v: boolean) => void
  taxRate?: string
  onTaxRateChange?: (v: string) => void
  /** sales = customer debt; purchases = supplier payable */
  partner?: 'customer' | 'supplier'
}

export function paymentTypeLabel(type?: string | null, t?: (k: string) => string): string {
  const key = type || 'credit'
  if (t) {
    if (key === 'cash') return String(t('common.paymentCash'))
    if (key === 'partial') return String(t('common.paymentPartial'))
    return String(t('common.paymentCredit'))
  }
  if (key === 'cash') return 'نقدي'
  if (key === 'partial') return 'دفعة من المبلغ'
  return 'آجل'
}

function defaultCashBoxId(cashBoxes: CashBox[]): string {
  const flagged = cashBoxes.find((c) => c.is_default)
  if (flagged) return String(flagged.id)
  const cash01 = cashBoxes.find((c) => c.code === 'CASH-01')
  if (cash01) return String(cash01.id)
  return cashBoxes[0] ? String(cashBoxes[0].id) : ''
}

export default function PaymentTypeFields<T extends {
  payment_type: string
  paid_amount: string
  cash_box_id: string
}>({
  state,
  setState,
  cashBoxes,
  estimatedTotal = 0,
  showTaxToggle = false,
  applyTax = true,
  onApplyTaxChange,
  taxRate = '',
  onTaxRateChange,
  partner = 'customer',
}: Props<T>) {
  const { t } = useTranslation()
  const type = (state.payment_type || 'credit') as PaymentType
  const remaining = Math.max(0, estimatedTotal - (Number(state.paid_amount) || 0))
  const hintKey =
    type === 'cash'
      ? (partner === 'supplier' ? 'common.paymentHintCashPurchase' : 'common.paymentHintCash')
      : type === 'partial'
        ? (partner === 'supplier' ? 'common.paymentHintPartialPurchase' : 'common.paymentHintPartial')
        : (partner === 'supplier' ? 'common.paymentHintCreditPurchase' : 'common.paymentHintCredit')

  useEffect(() => {
    if ((type === 'cash' || type === 'partial') && !state.cash_box_id && cashBoxes.length > 0) {
      const id = defaultCashBoxId(cashBoxes)
      if (id) setState({ ...state, cash_box_id: id })
    }
    // Only auto-pick when box list arrives / type needs a box.
    // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional: avoid loops on every state change
  }, [type, cashBoxes])

  return (
    <div className="space-y-3">
      <Field label={t('common.paymentType')}>
        <div className="flex flex-wrap gap-3 text-sm">
          {([
            ['cash', t('common.paymentCash')],
            ['credit', t('common.paymentCredit')],
            ['partial', t('common.paymentPartial')],
          ] as const).map(([value, label]) => (
            <label key={value} className="inline-flex items-center gap-1.5">
              <input
                type="radio"
                name="payment_type"
                checked={type === value}
                onChange={() => setState({
                  ...state,
                  payment_type: value,
                  paid_amount: value === 'partial' ? state.paid_amount : '',
                  cash_box_id: value === 'credit'
                    ? ''
                    : (state.cash_box_id || defaultCashBoxId(cashBoxes)),
                })}
              />
              {label}
            </label>
          ))}
        </div>
        <p className="mt-2 text-xs leading-relaxed text-black/55">{t(hintKey)}</p>
      </Field>

      {(type === 'cash' || type === 'partial') && (
        <Field label={t('common.cashBox')}>
          <select
            className={inputClass}
            value={state.cash_box_id}
            onChange={(e) => setState({ ...state, cash_box_id: e.target.value })}
            required
          >
            <option value="">—</option>
            {cashBoxes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}{c.currency ? ` (${c.currency})` : ''}{c.is_default ? ` — ${t('common.mainCashBox')}` : ''}
              </option>
            ))}
          </select>
        </Field>
      )}

      {type === 'partial' && (
        <>
          <Field label={t('common.paidAmount')}>
            <NumericInput
              value={state.paid_amount}
              onChange={(v) => setState({ ...state, paid_amount: v })}
              required
            />
          </Field>
          {estimatedTotal > 0 && (
            <p className="text-xs text-black/60">
              {t('common.remainingAmount')}:{' '}
              <span className="font-mono tabular-nums">
                {remaining.toLocaleString('ar-SY-u-nu-latn', { maximumFractionDigits: 2 })}
              </span>
            </p>
          )}
        </>
      )}

      {showTaxToggle && onApplyTaxChange && (
        <Field label={t('common.tax')}>
          <label className="inline-flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={applyTax}
              onChange={(e) => onApplyTaxChange(e.target.checked)}
            />
            {t('common.applyPurchaseTax')}
          </label>
          {applyTax && onTaxRateChange && (
            <div className="mt-2">
              <NumericInput value={taxRate} onChange={onTaxRateChange} />
            </div>
          )}
        </Field>
      )}
    </div>
  )
}
