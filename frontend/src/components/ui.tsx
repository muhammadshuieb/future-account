import { useState, type FormEvent, type ReactNode, type ButtonHTMLAttributes } from 'react'

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

export function LoadingBlock({ label = 'جاري التحميل...' }: { label?: string }) {
  return (
    <div className="space-y-3 p-4">
      <div className="skeleton h-4 w-40" />
      <div className="skeleton h-24 w-full" />
      <p className="text-sm text-black/45">{label}</p>
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

export function formatMoney(value: number, currency = 'SYP') {
  try {
    return new Intl.NumberFormat('ar', {
      style: 'currency',
      currency: currency || 'SYP',
      maximumFractionDigits: 2,
    }).format(value)
  } catch {
    return `${Number(value).toLocaleString('ar')} ${currency}`
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
