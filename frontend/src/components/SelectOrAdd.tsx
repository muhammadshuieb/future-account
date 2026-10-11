import { useEffect, useState } from 'react'
import { inputClass } from '@/components/ui'

const CUSTOM = '__custom__'

/** Dropdown of presets with an “add custom…” path that shows a free-text input. */
export default function SelectOrAdd({
  value,
  options,
  onChange,
  disabled,
  placeholder = 'اختر…',
  customLabel = 'إضافة بند جديد…',
  className = '',
}: {
  value: string
  options: string[]
  onChange: (value: string) => void
  disabled?: boolean
  placeholder?: string
  customLabel?: string
  className?: string
}) {
  const known = options.includes(value)
  const [mode, setMode] = useState<'select' | 'custom'>(value && !known ? 'custom' : 'select')

  useEffect(() => {
    if (value && !options.includes(value)) setMode('custom')
  }, [value, options])

  if (mode === 'custom') {
    return (
      <div className={`flex min-w-[9rem] flex-col gap-1 ${className}`}>
        <input
          className={inputClass}
          value={value}
          disabled={disabled}
          placeholder={customLabel}
          onChange={(e) => onChange(e.target.value)}
          autoFocus
        />
        {!disabled && (
          <button
            type="button"
            className="text-start text-[11px] text-teal"
            onClick={() => {
              setMode('select')
              if (!options.includes(value)) onChange('')
            }}
          >
            العودة للقائمة
          </button>
        )}
      </div>
    )
  }

  return (
    <select
      className={`${inputClass} min-w-[9rem] ${className}`}
      value={known ? value : ''}
      disabled={disabled}
      onChange={(e) => {
        const v = e.target.value
        if (v === CUSTOM) {
          setMode('custom')
          onChange('')
          return
        }
        onChange(v)
      }}
    >
      <option value="">{placeholder}</option>
      {options.map((opt) => (
        <option key={opt} value={opt}>{opt}</option>
      ))}
      <option value={CUSTOM}>{customLabel}</option>
    </select>
  )
}
