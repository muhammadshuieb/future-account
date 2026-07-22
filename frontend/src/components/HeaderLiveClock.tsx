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
      className="inline-flex max-w-[9.5rem] shrink-0 flex-col items-end justify-center rounded-lg border border-[var(--color-line)] bg-white px-2 py-1 text-ink/75 sm:max-w-none sm:px-2.5 sm:py-1.5"
      aria-live="polite"
      aria-atomic="true"
    >
      <span className="hidden text-[10px] font-medium leading-tight text-teal tabular-nums sm:inline">
        {dateLabel}
      </span>
      <span className="text-[11px] font-semibold leading-tight tabular-nums tracking-wide text-ink sm:text-xs">
        {timeLabel}
      </span>
    </time>
  )
}
