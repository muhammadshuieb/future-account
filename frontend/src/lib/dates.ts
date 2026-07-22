/** Application business timezone (Syria). Use for form defaults and “today”. */
export const APP_TIMEZONE = 'Asia/Damascus'

/** Calendar date YYYY-MM-DD in Asia/Damascus (avoids UTC day-shift from toISOString). */
export function todayYmd(timeZone: string = APP_TIMEZONE): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

/** January 1 of the current year in Asia/Damascus. */
export function yearStartYmd(timeZone: string = APP_TIMEZONE): string {
  const year = new Intl.DateTimeFormat('en-US', { timeZone, year: 'numeric' }).format(new Date())
  return `${year}-01-01`
}

/** Current month as YYYY-MM in Asia/Damascus. */
export function monthYm(timeZone: string = APP_TIMEZONE): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
  }).formatToParts(new Date())
  const y = parts.find((p) => p.type === 'year')?.value ?? '1970'
  const m = parts.find((p) => p.type === 'month')?.value ?? '01'
  return `${y}-${m}`
}

/** Format an API timestamp for display in Asia/Damascus. */
export function formatDateTimeLocal(value: string | Date, timeZone: string = APP_TIMEZONE): string {
  const d = typeof value === 'string' ? new Date(value) : value
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleString('ar-SY', { timeZone })
}
