import { useEffect, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Field, inputClass, Button } from '@/components/ui'
import { PaymentCurrencyFields, type CurrencyOption } from '@/components/CurrencyFields'
import { defaultCashBoxId, type PaymentType } from '@/components/PaymentTypeFields'

export type PaymentSplitDraft = {
  amount: string
  base_amount: string
  currency: string
  exchange_rate: string
  cash_box_id: string
}

type CashBox = { id: number; name: string; currency?: string; is_default?: boolean; code?: string }

type State = {
  payment_type: string
  paid_amount: string
  cash_box_id: string
  payments: PaymentSplitDraft[]
}

type Props<T extends State> = {
  state: T
  setState: (next: T) => void
  cashBoxes: CashBox[]
  currencies: CurrencyOption[]
  documentCurrency: string
  documentExchangeRate?: string
  baseCurrency?: string
  estimatedTotal?: number
  partner?: 'customer' | 'supplier'
}

function emptySplit(currency: string, rate: string, cashBoxId = ''): PaymentSplitDraft {
  return {
    amount: '',
    base_amount: '',
    currency,
    exchange_rate: currency === 'USD' || rate === '1' ? (currency === 'USD' ? '1' : rate) : rate,
    cash_box_id: cashBoxId,
  }
}

function round2(n: number) {
  return Math.round(n * 100) / 100
}

/** Convert a payment split into document (invoice) currency via base. */
export function splitInDocumentCurrency(
  split: PaymentSplitDraft,
  documentCurrency: string,
  documentExchangeRate: number,
  baseCurrency = 'USD',
): number {
  const amount = Number(split.amount) || 0
  if (amount <= 0) return 0
  const payCur = (split.currency || baseCurrency).toUpperCase()
  const docCur = (documentCurrency || baseCurrency).toUpperCase()
  if (payCur === docCur) return round2(amount)

  const base =
    Number(split.base_amount) > 0
      ? Number(split.base_amount)
      : payCur === baseCurrency
        ? amount
        : (Number(split.exchange_rate) || 0) > 0
          ? amount * Number(split.exchange_rate)
          : 0
  if (base <= 0) return 0
  if (docCur === baseCurrency) return round2(base)
  const invRate = documentExchangeRate > 0 ? documentExchangeRate : 1
  return round2(base / invRate)
}

export default function MultiCurrencyPaymentFields<T extends State>({
  state,
  setState,
  cashBoxes,
  currencies,
  documentCurrency,
  documentExchangeRate = '1',
  baseCurrency = 'USD',
  estimatedTotal = 0,
  partner = 'customer',
}: Props<T>) {
  const { t } = useTranslation()
  const type = (state.payment_type || 'credit') as PaymentType
  const invRate = Number(documentExchangeRate) || 1
  const docCur = (documentCurrency || baseCurrency).toUpperCase()

  const paidInDoc = useMemo(
    () => round2(state.payments.reduce((sum, p) => sum + splitInDocumentCurrency(p, docCur, invRate, baseCurrency), 0)),
    [state.payments, docCur, invRate, baseCurrency],
  )
  const remaining = Math.max(0, round2(estimatedTotal - paidInDoc))

  const hintKey =
    type === 'cash'
      ? (partner === 'supplier' ? 'common.paymentHintCashPurchase' : 'common.paymentHintCash')
      : type === 'partial'
        ? (partner === 'supplier' ? 'common.paymentHintPartialPurchase' : 'common.paymentHintPartial')
        : (partner === 'supplier' ? 'common.paymentHintCreditPurchase' : 'common.paymentHintCredit')

  const ensureDefaultLine = (payments: PaymentSplitDraft[], currency: string) => {
    if (payments.length > 0) return payments
    const rate = currency.toUpperCase() === baseCurrency.toUpperCase()
      ? '1'
      : (documentExchangeRate || '')
    const box = defaultCashBoxId(cashBoxes, currency)
    return [emptySplit(currency, rate, box)]
  }

  useEffect(() => {
    if (type !== 'cash' && type !== 'partial') return
    if (state.payments.length === 0) {
      const rate = docCur === baseCurrency.toUpperCase() ? '1' : (documentExchangeRate || '')
      const box = defaultCashBoxId(cashBoxes, docCur)
      const amount = type === 'cash' && estimatedTotal > 0 ? String(estimatedTotal) : ''
      const baseAmt =
        type === 'cash' && estimatedTotal > 0
          ? String(
            docCur === baseCurrency.toUpperCase()
              ? estimatedTotal
              : invRate > 0
                ? round2(estimatedTotal * invRate)
                : '',
          )
          : ''
      setState({
        ...state,
        payments: [{
          amount,
          base_amount: baseAmt,
          currency: docCur,
          exchange_rate: rate,
          cash_box_id: box,
        }],
        cash_box_id: box,
      })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type, docCur])

  // Keep single cash line amount aligned with invoice total when still in document currency.
  useEffect(() => {
    if (type !== 'cash' || estimatedTotal <= 0) return
    if (state.payments.length !== 1) return
    const only = state.payments[0]
    if (only.currency.toUpperCase() !== docCur) return
    const current = Number(only.amount) || 0
    // Only auto-sync when empty or previously matched a different total estimate.
    if (current > 0 && Math.abs(current - estimatedTotal) > 0.001 && only.amount !== '') {
      // User edited — leave alone unless amount looks like a prior auto-fill of a different total
      // (skip aggressive overwrite).
      return
    }
    if (Math.abs(current - estimatedTotal) < 0.001) return
    if (current > 0) return
    const baseAmt = docCur === baseCurrency.toUpperCase()
      ? String(estimatedTotal)
      : invRate > 0
        ? String(round2(estimatedTotal * invRate))
        : only.base_amount
    setState({
      ...state,
      payments: [{ ...only, amount: String(estimatedTotal), base_amount: baseAmt }],
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [estimatedTotal, type, docCur])

  // Keep legacy paid_amount / cash_box_id in sync for API fallback.
  useEffect(() => {
    if (type !== 'cash' && type !== 'partial') return
    const firstBox = state.payments[0]?.cash_box_id || ''
    const nextPaid = type === 'partial' ? String(paidInDoc || '') : state.paid_amount
    if (firstBox !== state.cash_box_id || (type === 'partial' && nextPaid !== state.paid_amount)) {
      setState({
        ...state,
        cash_box_id: firstBox || state.cash_box_id,
        paid_amount: type === 'partial' ? nextPaid : state.paid_amount,
      })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paidInDoc, state.payments, type])

  const setPaymentType = (value: PaymentType) => {
    if (value === 'credit') {
      setState({ ...state, payment_type: value, paid_amount: '', cash_box_id: '', payments: [] })
      return
    }
    let payments = ensureDefaultLine(state.payments, docCur)
    if (value === 'cash' && payments.length === 1 && !(Number(payments[0].amount) > 0) && estimatedTotal > 0) {
      const baseAmt = docCur === baseCurrency.toUpperCase()
        ? String(estimatedTotal)
        : invRate > 0
          ? String(round2(estimatedTotal * invRate))
          : ''
      payments = [{ ...payments[0], amount: String(estimatedTotal), base_amount: baseAmt }]
    }
    setState({
      ...state,
      payment_type: value,
      paid_amount: value === 'partial' ? state.paid_amount : '',
      cash_box_id: payments[0]?.cash_box_id || defaultCashBoxId(cashBoxes, docCur),
      payments,
    })
  }

  const updateSplit = (index: number, next: PaymentSplitDraft) => {
    const payments = state.payments.map((p, i) => (i === index ? next : p))
    // Auto-pick cash box when currency changes
    const prev = state.payments[index]
    if (prev && prev.currency !== next.currency) {
      const box = defaultCashBoxId(cashBoxes, next.currency)
      payments[index] = { ...next, cash_box_id: box }
    }
    setState({ ...state, payments, cash_box_id: payments[0]?.cash_box_id || state.cash_box_id })
  }

  const addSplit = () => {
    // Prefer a different active currency than the first line (SYP when invoice is USD).
    const used = new Set(state.payments.map((p) => p.currency.toUpperCase()))
    const alt = currencies.find((c) => c.is_active && !used.has(c.code.toUpperCase()))
    const code = alt?.code || docCur
    const rate = code.toUpperCase() === baseCurrency.toUpperCase()
      ? '1'
      : String(alt?.rate_to_base && alt.rate_to_base > 0 ? alt.rate_to_base : '')
    setState({
      ...state,
      payments: [...state.payments, emptySplit(code, rate, defaultCashBoxId(cashBoxes, code))],
    })
  }

  const removeSplit = (index: number) => {
    if (state.payments.length <= 1) return
    const payments = state.payments.filter((_, i) => i !== index)
    setState({ ...state, payments, cash_box_id: payments[0]?.cash_box_id || '' })
  }

  const boxesFor = (currency: string) =>
    cashBoxes.filter((c) => (c.currency || 'USD').toUpperCase() === currency.toUpperCase())

  return (
    <div className="form-stack">
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
                onChange={() => setPaymentType(value)}
              />
              {label}
            </label>
          ))}
        </div>
        <p className="mt-2 text-xs leading-relaxed text-black/55">{t(hintKey)}</p>
        {(type === 'cash' || type === 'partial') && (
          <p className="mt-1 text-xs leading-relaxed text-black/55">{t('common.multiCurrencyPaymentHint')}</p>
        )}
      </Field>

      {(type === 'cash' || type === 'partial') && (
        <div className="space-y-3">
          {state.payments.map((split, index) => {
            const boxes = boxesFor(split.currency)
            return (
              <div
                key={index}
                className="rounded-lg border border-black/10 bg-black/[0.015] p-3 space-y-2"
              >
                <div className="flex items-center justify-between gap-2">
                  <p className="text-xs font-medium text-black/70">
                    {t('common.paymentLine', { n: index + 1 })}
                  </p>
                  {state.payments.length > 1 && (
                    <button
                      type="button"
                      className="text-xs text-red-700 hover:underline"
                      onClick={() => removeSplit(index)}
                    >
                      {t('common.delete')}
                    </button>
                  )}
                </div>
                <PaymentCurrencyFields
                  state={split}
                  setState={(next) => {
                    const value = typeof next === 'function' ? next(split) : next
                    updateSplit(index, value)
                  }}
                  currencies={currencies}
                  baseCurrency={baseCurrency}
                />
                <Field label={t('common.cashBox')}>
                  <select
                    className={inputClass}
                    value={split.cash_box_id}
                    onChange={(e) => updateSplit(index, { ...split, cash_box_id: e.target.value })}
                    required
                  >
                    <option value="">—</option>
                    {boxes.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}{c.currency ? ` (${c.currency})` : ''}{c.is_default ? ` — ${t('common.mainCashBox')}` : ''}
                      </option>
                    ))}
                  </select>
                </Field>
                {docCur !== split.currency.toUpperCase() && Number(split.amount) > 0 && (
                  <p className="text-xs text-black/55">
                    {t('common.paidInInvoiceCurrency', {
                      amount: splitInDocumentCurrency(split, docCur, invRate, baseCurrency).toLocaleString('ar-SY-u-nu-latn', { maximumFractionDigits: 2 }),
                      currency: docCur,
                    })}
                  </p>
                )}
              </div>
            )
          })}

          <div className="flex flex-wrap items-center gap-3">
            <Button type="button" variant="secondary" onClick={addSplit}>
              {t('common.addPaymentLine')}
            </Button>
            {estimatedTotal > 0 && (
              <p className="text-xs text-black/60">
                {t('common.paidAmount')}:{' '}
                <span className="font-mono tabular-nums">
                  {paidInDoc.toLocaleString('ar-SY-u-nu-latn', { maximumFractionDigits: 2 })} {docCur}
                </span>
                {' — '}
                {t('common.remainingAmount')}:{' '}
                <span className="font-mono tabular-nums">
                  {remaining.toLocaleString('ar-SY-u-nu-latn', { maximumFractionDigits: 2 })} {docCur}
                </span>
              </p>
            )}
          </div>

        </div>
      )}
    </div>
  )
}
