import { useEffect, useRef } from 'react'

/**
 * After "Add line", call markPending() then update lines.
 * Attach setLastLineRef to the last line card/row so it scrolls into view (and focuses).
 */
export function useScrollToLastLine(lineCount: number) {
  const lastLineRef = useRef<HTMLElement | null>(null)
  const pending = useRef(false)

  const markPending = () => {
    pending.current = true
  }

  const setLastLineRef = (el: HTMLElement | null) => {
    lastLineRef.current = el
  }

  useEffect(() => {
    if (!pending.current) return
    pending.current = false
    const el = lastLineRef.current
    if (!el) return

    // Defer one frame so the new DOM node is laid out inside modal scroll areas.
    requestAnimationFrame(() => {
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
      const focusable = el.querySelector<HTMLElement>(
        'input:not([readonly]):not([disabled]), select:not([disabled]), textarea:not([disabled])',
      )
      focusable?.focus({ preventScroll: true })
    })
  }, [lineCount])

  return { setLastLineRef, markPending }
}
