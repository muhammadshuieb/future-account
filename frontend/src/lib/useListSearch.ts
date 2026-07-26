import { useEffect, useState } from 'react'

/** Debounce a value (e.g. search input) before triggering API refetch. */
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const id = window.setTimeout(() => setDebounced(value), delayMs)
    return () => window.clearTimeout(id)
  }, [value, delayMs])

  return debounced
}

/** List-page search state: raw input + debounced `q` for API `?q=`. */
export function useListSearch(delayMs = 300) {
  const [q, setQ] = useState('')
  const debouncedQ = useDebouncedValue(q.trim(), delayMs)
  return {
    q,
    setQ,
    debouncedQ,
    /** Pass into axios params — omitted when empty. */
    params: debouncedQ ? { q: debouncedQ } : {},
  }
}
