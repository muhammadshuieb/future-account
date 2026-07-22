import { useEffect, useId, useRef, useState, type ButtonHTMLAttributes, type FormEvent, type InputHTMLAttributes, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

const DECIMAL_INPUT_RE = /^-?\d*\.?\d*$/

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <header className="print-hide flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="page-title">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-black/55">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </header>
  )
}

export function Tabs({
  tabs,
  active,
  onChange,
}: {
  tabs: { id: string; label: string }[]
  active: string
  onChange: (id: string) => void
}) {
  return (
    <div className="print-hide flex flex-wrap gap-1 border-b border-[var(--color-line)] pb-2">
      {tabs.map((t) => (
        <button
          key={t.id}
          type="button"
          onClick={() => onChange(t.id)}
          className={[
            'rounded-lg px-3 py-2 text-sm font-medium transition',
            active === t.id ? 'bg-teal text-white' : 'text-black/65 hover:bg-mist hover:text-ink',
          ].join(' ')}
        >
          {t.label}
        </button>
      ))}
    </div>
  )
}

export function Panel({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div className={`overflow-hidden rounded-xl border border-[var(--color-line)] bg-white shadow-[0_1px_2px_rgba(12,26,34,0.04)] ${className}`}>
      {children}
    </div>
  )
}

export function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
  return (
    <label className="block text-sm">
      <span className="mb-1.5 block font-medium text-black/65">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-black/45">{hint}</span>}
    </label>
  )
}

export const inputClass =
  'w-full rounded-lg border border-black/10 bg-white px-3 py-2 outline-none transition focus:border-teal/40 focus:ring-2 focus:ring-teal/20'

/** Text input that keeps partial decimals (e.g. "12.") while typing. */
export function NumericInput({
  value,
  onChange,
  className = inputClass,
  ...props
}: {
  value: string
  onChange: (value: string) => void
} & Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'type'>) {
  return (
    <input
      type="text"
      inputMode="decimal"
      autoComplete="off"
      className={className}
      value={value}
      onChange={(e) => {
        const next = e.target.value.replace(/,/g, '')
        if (next === '' || DECIMAL_INPUT_RE.test(next)) onChange(next)
      }}
      {...props}
    />
  )
}

export function Button({
  variant = 'primary',
  className = '',
  type = 'button',
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'danger' | 'ghost' }) {
  return <button type={type} className={`btn btn-${variant} ${className}`} {...props} />
}

export function Msg({ message, error }: { message?: string; error?: string }) {
  if (!message && !error) return null
  return (
    <p className={error ? 'rounded-lg bg-red-50 px-3 py-2 text-sm text-danger' : 'rounded-lg bg-teal-soft/60 px-3 py-2 text-sm text-teal-dark'}>
      {error || message}
    </p>
  )
}

export function EmptyState({ title, description }: { title: string; description?: string }) {
  return (
    <div className="empty-state">
      <p className="font-semibold text-ink/80">{title}</p>
      {description && <p className="mt-1 text-sm">{description}</p>}
    </div>
  )
}

export function LoadingBlock({ label }: { label?: string }) {
  const { t } = useTranslation()
  return (
    <div className="space-y-3 p-4">
      <div className="skeleton h-4 w-40" />
      <div className="skeleton h-24 w-full" />
      <p className="text-sm text-black/45">{label ?? t('common.loading')}</p>
    </div>
  )
}

export function StatTile({
  label,
  value,
  hint,
  tone = 'default',
}: {
  label: string
  value: string
  hint?: string
  tone?: 'default' | 'teal' | 'amber' | 'success' | 'danger'
}) {
  const tones = {
    default: 'text-ink',
    teal: 'text-teal',
    amber: 'text-amber',
    success: 'text-success',
    danger: 'text-danger',
  }
  return (
    <div className="rounded-xl border border-[var(--color-line)] bg-white/95 p-4 shadow-[0_1px_2px_rgba(12,26,34,0.03)]">
      <p className="text-xs font-medium tracking-wide text-black/45">{label}</p>
      <p className={`mt-2 text-xl font-bold tabular-nums ${tones[tone]}`}>{value}</p>
      {hint && <p className="mt-1 text-xs text-black/40">{hint}</p>}
    </div>
  )
}

const NUMBER_LOCALE = 'ar-SY'

const quantityFormatter = new Intl.NumberFormat(NUMBER_LOCALE, {
  maximumFractionDigits: 3,
  minimumFractionDigits: 0,
})

/** Display stock/line quantities without misleading trailing decimals (e.g. 1 not 1.000). */
export function formatQuantity(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = typeof value === 'number' ? value : Number(value)
  if (!Number.isFinite(n)) return String(value)
  return quantityFormatter.format(n)
}

export function formatMoney(value: number, currency = 'SYP') {
  try {
    return new Intl.NumberFormat(NUMBER_LOCALE, {
      style: 'currency',
      currency: currency || 'SYP',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value)
  } catch {
    return `${Number(value).toLocaleString(NUMBER_LOCALE)} ${currency}`
  }
}

export function useFormMessage() {
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const fromErr = (err: { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }) => {
    const first = err.response?.data?.errors ? Object.values(err.response.data.errors)[0]?.[0] : undefined
    setError(first || err.response?.data?.message || 'حدث خطأ')
    setMessage('')
  }
  return { message, error, setMessage, setError, fromErr }
}

export type SubmitHandler = (e: FormEvent) => void

/** Overlay dialog — full-screen on mobile, centered panel on desktop. RTL-friendly. */
export function Modal({
  open,
  onClose,
  title,
  children,
  footer,
  size = 'md',
}: {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg' | 'xl'
}) {
  const titleId = useId()
  const panelRef = useRef<HTMLDivElement>(null)
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!open) return
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    panelRef.current?.focus()
    return () => {
      document.body.style.overflow = prev
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onCloseRef.current()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  if (!open) return null

  const sizeClass =
    size === 'sm' ? 'max-w-md' : size === 'lg' ? 'max-w-3xl' : size === 'xl' ? 'max-w-5xl' : 'max-w-xl'

  return (
    <div className="modal-root" role="presentation">
      <button type="button" className="modal-backdrop" aria-label="إغلاق" onClick={onClose} />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className={`modal-panel ${sizeClass}`}
      >
        <header className="modal-header">
          <h2 id={titleId} className="modal-title">
            {title}
          </h2>
          <button type="button" className="modal-close" onClick={onClose} aria-label="إغلاق">
            ×
          </button>
        </header>
        <div className="modal-body">{children}</div>
        {footer && <footer className="modal-footer">{footer}</footer>}
      </div>
    </div>
  )
}
