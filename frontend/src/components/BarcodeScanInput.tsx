import { useCallback, useRef, useState } from 'react'
import { ScanLine } from 'lucide-react'
import { inputClass } from '@/components/ui'

type Props = {
  onScan: (barcode: string) => void
  label?: string
  hint?: string
  className?: string
  disabled?: boolean
}

/** Captures USB HID keyboard-wedge barcode scanners (rapid keystrokes + Enter). */
export default function BarcodeScanInput({ onScan, label = 'مسح باركود', hint, className = '', disabled }: Props) {
  const [value, setValue] = useState('')
  const [focused, setFocused] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)

  const submit = useCallback(() => {
    const code = value.trim()
    if (!code) return
    onScan(code)
    setValue('')
  }, [onScan, value])

  return (
    <div className={className}>
      <label className="block text-sm">
        <span className="mb-1.5 flex items-center gap-2 font-medium text-black/65">
          <ScanLine size={16} className="text-teal" />
          {label}
        </span>
        <div className="relative">
          <input
            ref={inputRef}
            type="text"
            value={value}
            disabled={disabled}
            autoComplete="off"
            autoCorrect="off"
            autoCapitalize="off"
            spellCheck={false}
            inputMode="numeric"
            placeholder="انقر هنا ثم امسح الباركود..."
            className={`${inputClass} touch-target pr-10 font-mono text-sm ${focused ? 'border-teal/50 ring-2 ring-teal/20' : ''}`}
            onChange={(e) => setValue(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault()
                submit()
              }
            }}
            onFocus={() => setFocused(true)}
            onBlur={() => setFocused(false)}
          />
          {focused && (
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[10px] text-teal">
              جاهز
            </span>
          )}
        </div>
        {hint && <span className="mt-1 block text-xs text-black/45">{hint}</span>}
      </label>
    </div>
  )
}
