import { useEffect, useState } from 'react'
import { APP_TIMEZONE, formatLiveDate, formatLiveTime } from '@/lib/dates'

/** Live Asia/Damascus date + time for the app top bar. */
export default function HeaderLiveClock() {
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 1000)
    return () => window.clearInterval(id)
  }, [])

  const dateLabel = formatLiveDate(now)
  const timeLabel = formatLiveTime(now)

  return (
    <time
      dateTime={now.toISOString()}
      title={`${dateLabel} ${timeLabel} (${APP_TIMEZONE})`}
      className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-[var(--color-line)] bg-white px-2 py-1.5 text-ink/80 sm:gap-2 sm:px-2.5 sm:py-1.5"
      aria-live="polite"
      aria-atomic="true"
    >
      <span className="text-xs font-medium leading-none tabular-nums text-teal sm:text-sm">
        {dateLabel}
      </span>
      <span className="h-3 w-px shrink-0 bg-[var(--color-line)] sm:h-3.5" aria-hidden="true" />
      <span className="text-xs font-semibold leading-none tabular-nums tracking-wide text-ink sm:text-base sm:font-medium">
        {timeLabel}
      </span>
    </time>
  )
}
