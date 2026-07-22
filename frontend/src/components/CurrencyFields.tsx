import { useTranslation } from 'react-i18next'
import { Field, NumericInput, inputClass } from '@/components/ui'

export type CurrencyOption = {
  id: number
  code: string
  name: string
  name_en?: string
  symbol?: string
  is_active: boolean
  rate_to_base?: number
}

export type CurrencyFxState = {
  currency: string
  exchange_rate: string
}

export type PaymentFxState = CurrencyFxState & {
  amount: string
  base_amount: string
}

function labelFor(c: CurrencyOption) {
  const sym = c.symbol ? ` ${c.symbol}` : ''
  return `${c.code}${sym} — ${c.name}`
}

function fmt(n: number) {
  return n.toLocaleString('ar-SY', { maximumFractionDigits: 8 })
}

/** Document-level currency + exchange rate (quotes, invoices, returns, …). */
export function DocumentCurrencyFields<T extends CurrencyFxState>({
  state,
  setState,
  currencies,
  baseCurrency = 'SYP',
  showBasePreview,
  documentTotal,
}: {
  state: T
  setState: (next: T | ((prev: T) => T)) => void
  currencies: CurrencyOption[]
  baseCurrency?: string
  showBasePreview?: boolean
  documentTotal?: number
}) {
  const { t } = useTranslation()
  const active = currencies.filter((c) => c.is_active)
  const list = active.length
    ? active
    : currencies.length
      ? currencies
      : [
          { id: 1, code: 'SYP', name: 'الليرة السورية', is_active: true, rate_to_base: 1 },
          { id: 2, code: 'TRY', name: 'الليرة التركية', is_active: true, rate_to_base: 0 },
          { id: 3, code: 'USD', name: 'الدولار الأمريكي', is_active: true, rate_to_base: 0 },
        ]
  const rate = Number(state.exchange_rate) || 0
  const isBase = state.currency === baseCurrency

  const onCurrency = (code: string) => {
    const row = list.find((c) => c.code === code)
    const nextRate = code === baseCurrency ? '1' : String(row?.rate_to_base && row.rate_to_base > 0 ? row.rate_to_base : '')
    setState({ ...state, currency: code, exchange_rate: nextRate })
  }

  const basePreview =
    showBasePreview && documentTotal != null && rate > 0
      ? round2(documentTotal * (isBase ? 1 : rate))
      : null

  return (
    <div className="space-y-2">
      <div className="grid grid-cols-2 gap-2">
        <Field label={t('common.currency')}>
          <select className={inputClass} value={state.currency} onChange={(e) => onCurrency(e.target.value)} required>
            {list.map((c) => (
              <option key={c.code} value={c.code}>{labelFor(c)}</option>
            ))}
          </select>
        </Field>
        <Field label={t('common.exchangeRate')} hint={isBase ? undefined : t('common.exchangeRateHint', { base: baseCurrency })}>
          <NumericInput
            value={isBase ? '1' : state.exchange_rate}
            onChange={(v) => setState({ ...state, exchange_rate: v })}
            disabled={isBase}
            required={!isBase}
          />
        </Field>
      </div>
      {basePreview != null && !isBase && (
        <p className="text-xs text-black/55">
          {t('common.baseEquivalent', { amount: fmt(basePreview), currency: baseCurrency })}
        </p>
      )}
    </div>
  )
}

/**
 * Payment/receipt FX: amount in selected currency + amount in base (SYP),
 * with live bidirectional conversion via exchange rate.
 */
export function PaymentCurrencyFields<T extends PaymentFxState>({
  state,
  setState,
  currencies,
  baseCurrency = 'SYP',
}: {
  state: T
  setState: (next: T | ((prev: T) => T)) => void
  currencies: CurrencyOption[]
  baseCurrency?: string
}) {
  const { t } = useTranslation()
  const active = currencies.filter((c) => c.is_active)
  const list = active.length
    ? active
    : currencies.length
      ? currencies
      : [
          { id: 1, code: 'SYP', name: 'الليرة السورية', is_active: true, rate_to_base: 1 },
          { id: 2, code: 'TRY', name: 'الليرة التركية', is_active: true, rate_to_base: 0 },
          { id: 3, code: 'USD', name: 'الدولار الأمريكي', is_active: true, rate_to_base: 0 },
        ]
  const isBase = state.currency === baseCurrency
  const rate = Number(state.exchange_rate) || 0
  const selected = list.find((c) => c.code === state.currency)

  const applyCurrency = (code: string) => {
    const row = list.find((c) => c.code === code)
    const nextRate = code === baseCurrency ? 1 : (row?.rate_to_base && row.rate_to_base > 0 ? row.rate_to_base : 0)
    const amount = Number(state.amount) || 0
    const base = code === baseCurrency
      ? amount
      : nextRate > 0 && amount > 0
        ? round2(amount * nextRate)
        : Number(state.base_amount) || 0
    setState({
      ...state,
      currency: code,
      exchange_rate: String(nextRate || (code === baseCurrency ? 1 : '')),
      base_amount: base > 0 ? String(base) : state.base_amount,
      ...(code === baseCurrency && amount > 0 ? { base_amount: String(amount) } : {}),
    })
  }

  const onAmount = (v: string) => {
    const amount = Number(v) || 0
    const r = Number(state.exchange_rate) || 0
    const base = state.currency === baseCurrency
      ? amount
      : r > 0
        ? round2(amount * r)
        : Number(state.base_amount) || 0
    setState({ ...state, amount: v, base_amount: amount > 0 || r > 0 ? String(base || '') : state.base_amount })
  }

  const onBase = (v: string) => {
    const base = Number(v) || 0
    const r = Number(state.exchange_rate) || 0
    if (state.currency === baseCurrency) {
      setState({ ...state, base_amount: v, amount: v })
      return
    }
    const amount = r > 0 ? round8(base / r) : Number(state.amount) || 0
    setState({ ...state, base_amount: v, amount: base > 0 && r > 0 ? String(amount) : state.amount })
  }

  const onRate = (v: string) => {
    const r = Number(v) || 0
    const amount = Number(state.amount) || 0
    const base = r > 0 && amount > 0 ? round2(amount * r) : Number(state.base_amount) || 0
    setState({ ...state, exchange_rate: v, base_amount: amount > 0 && r > 0 ? String(base) : state.base_amount })
  }

  const amountN = Number(state.amount) || 0
  const baseN = Number(state.base_amount) || 0
  const preview =
    !isBase && amountN > 0 && baseN > 0
      ? t('common.fxPreview', {
          amount: fmt(amountN),
          currency: state.currency,
          symbol: selected?.symbol || state.currency,
          base: fmt(baseN),
          baseCurrency,
        })
      : null

  return (
    <div className="space-y-2">
      <div className="grid grid-cols-2 gap-2">
        <Field label={t('common.currency')}>
          <select className={inputClass} value={state.currency} onChange={(e) => applyCurrency(e.target.value)} required>
            {list.map((c) => (
              <option key={c.code} value={c.code}>{labelFor(c)}</option>
            ))}
          </select>
        </Field>
        <Field label={t('common.exchangeRate')} hint={isBase ? undefined : t('common.exchangeRateHint', { base: baseCurrency })}>
          <NumericInput value={isBase ? '1' : state.exchange_rate} onChange={onRate} disabled={isBase} required={!isBase} />
        </Field>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <Field label={t('common.amountInCurrency', { currency: state.currency })}>
          <NumericInput value={state.amount} onChange={onAmount} required />
        </Field>
        <Field label={t('common.amountInBase', { currency: baseCurrency })}>
          <NumericInput value={state.base_amount} onChange={onBase} required />
        </Field>
      </div>
      {preview && <p className="rounded-md bg-black/[0.03] px-2.5 py-2 text-xs text-black/65">{preview}</p>}
      {!isBase && rate <= 0 && (
        <p className="text-xs text-amber">{t('common.exchangeRateRequired')}</p>
      )}
    </div>
  )
}

function round2(n: number) {
  return Math.round(n * 100) / 100
}

function round8(n: number) {
  return Math.round(n * 1e8) / 1e8
}
