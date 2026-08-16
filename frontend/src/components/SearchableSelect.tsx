import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { inputClass } from '@/components/ui'

export type SearchableOption = { value: string; label: string }

type Props = {
  options: SearchableOption[]
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  required?: boolean
  placeholder?: string
  /** Caps rendered rows so catalogs with thousands of items stay responsive. */
  maxVisible?: number
}

/**
 * Type-to-filter dropdown. The visible input holds the selected label so native
 * form validation still blocks empty required fields, and a stray search term is
 * cleared on close to keep input and selection in sync.
 */
export default function SearchableSelect({
  options,
  value,
  onChange,
  disabled = false,
  required = false,
  placeholder,
  maxVisible = 50,
}: Props) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const [dropUp, setDropUp] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const listRef = useRef<HTMLUListElement>(null)

  const selectedLabel = options.find((option) => option.value === value)?.label || ''

  const matches = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (!needle) return options
    return options.filter((option) => option.label.toLowerCase().includes(needle))
  }, [options, query])

  const visible = matches.slice(0, maxVisible)

  useEffect(() => {
    if (!open) return
    const onPointerDown = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false)
        setQuery('')
      }
    }
    document.addEventListener('mousedown', onPointerDown)
    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [open])

  useEffect(() => {
    if (!open) return
    listRef.current?.querySelector('[data-active="1"]')?.scrollIntoView({ block: 'nearest' })
  }, [activeIndex, open])

  // Modal bodies scroll, so a long list opening downwards near the bottom gets clipped.
  useEffect(() => {
    if (!open) return
    const rect = inputRef.current?.getBoundingClientRect()
    if (!rect) return
    const below = window.innerHeight - rect.bottom
    setDropUp(below < 260 && rect.top > below)
  }, [open])

  const commit = (option: SearchableOption) => {
    onChange(option.value)
    setQuery('')
    setOpen(false)
  }

  const close = () => {
    setOpen(false)
    setQuery('')
  }

  return (
    <div ref={containerRef} className="relative">
      <input
        ref={inputRef}
        type="text"
        className={inputClass}
        value={open ? query : selectedLabel}
        placeholder={placeholder ?? t('common.typeToSearch')}
        disabled={disabled}
        required={required}
        autoComplete="off"
        role="combobox"
        aria-expanded={open}
        onChange={(event) => {
          setQuery(event.target.value)
          setActiveIndex(0)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onBlur={close}
        onKeyDown={(event) => {
          if (event.key === 'ArrowDown') {
            event.preventDefault()
            if (!open) { setOpen(true); return }
            setActiveIndex((prev) => Math.min(prev + 1, visible.length - 1))
          } else if (event.key === 'ArrowUp') {
            event.preventDefault()
            setActiveIndex((prev) => Math.max(prev - 1, 0))
          } else if (event.key === 'Enter') {
            if (open && visible[activeIndex]) {
              event.preventDefault()
              commit(visible[activeIndex])
            }
          } else if (event.key === 'Escape' && open) {
            // Keep the surrounding modal open while only the list collapses.
            event.stopPropagation()
            close()
          }
        }}
      />

      {value && !disabled && (
        <button
          type="button"
          className="absolute inset-y-0 left-2 my-auto h-5 w-5 rounded text-black/35 transition hover:text-black/70"
          aria-label={t('common.clearSearch')}
          onMouseDown={(event) => {
            event.preventDefault()
            event.stopPropagation()
            onChange('')
            setQuery('')
          }}
          onClick={(event) => event.stopPropagation()}
        >
          ×
        </button>
      )}

      {open && (
        <ul
          ref={listRef}
          role="listbox"
          className={`absolute z-30 max-h-60 w-full overflow-auto rounded-lg border border-black/10 bg-white py-1 shadow-lg ${dropUp ? 'bottom-full mb-1' : 'mt-1'}`}
          // The field lives inside a <label>, which would otherwise refocus the input and reopen the list.
          onClick={(event) => event.stopPropagation()}
        >
          {visible.length === 0 && (
            <li className="px-3 py-2 text-xs text-black/45">{t('common.noSearchResults')}</li>
          )}
          {visible.map((option, index) => (
            <li
              key={option.value}
              role="option"
              aria-selected={option.value === value}
              data-active={index === activeIndex ? '1' : '0'}
              className={`cursor-pointer px-3 py-2 text-sm ${index === activeIndex ? 'bg-teal/10' : ''} ${option.value === value ? 'font-semibold' : ''}`}
              onMouseEnter={() => setActiveIndex(index)}
              onMouseDown={(event) => {
                event.preventDefault()
                commit(option)
              }}
            >
              {option.label}
            </li>
          ))}
          {matches.length > visible.length && (
            <li className="border-t border-black/5 px-3 py-2 text-xs text-black/45">
              {t('common.refineSearch', { count: matches.length - visible.length })}
            </li>
          )}
        </ul>
      )}
    </div>
  )
}
