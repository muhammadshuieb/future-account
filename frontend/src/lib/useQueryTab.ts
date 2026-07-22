import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'

/** Keep page tabs in sync with `?tab=` so deep links from alerts work. */
export function useQueryTab<T extends string>(valid: readonly T[], fallback: T): [T, (id: string) => void] {
  const [params, setParams] = useSearchParams()

  const tab = useMemo(() => {
    const raw = params.get('tab')
    return valid.includes(raw as T) ? (raw as T) : fallback
  }, [params, valid, fallback])

  const setTab = useCallback(
    (id: string) => {
      const nextId = valid.includes(id as T) ? (id as T) : fallback
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          next.set('tab', nextId)
          return next
        },
        { replace: true },
      )
    },
    [setParams, valid, fallback],
  )

  return [tab, setTab]
}
