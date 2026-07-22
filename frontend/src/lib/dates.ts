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
  return d.toLocaleString('ar-SY-u-nu-latn', { timeZone })
}

const LIVE_CLOCK_LOCALE = 'ar-SY-u-nu-latn'

/** Calendar date for header clock (Latin digits), e.g. 23/07/2026. */
export function formatLiveDate(date: Date = new Date(), timeZone: string = APP_TIMEZONE): string {
  return new Intl.DateTimeFormat(LIVE_CLOCK_LOCALE, {
    timeZone,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}

/** Wall-clock time for header (Latin digits, 24h), e.g. 12:55:01. */
export function formatLiveTime(date: Date = new Date(), timeZone: string = APP_TIMEZONE): string {
  return new Intl.DateTimeFormat(LIVE_CLOCK_LOCALE, {
    timeZone,
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  }).format(date)
}
