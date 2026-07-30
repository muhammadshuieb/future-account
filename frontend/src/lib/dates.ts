/** Application business timezone (Syria). Use for form defaults and “today”. */
export const APP_TIMEZONE = 'Asia/Damascus'

const LIVE_CLOCK_LOCALE = 'ar-SY-u-nu-latn'

function ymdInZone(date: Date, timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(date)
}

/** Calendar date YYYY-MM-DD in Asia/Damascus (avoids UTC day-shift from toISOString). */
export function todayYmd(timeZone: string = APP_TIMEZONE): string {
  return ymdInZone(new Date(), timeZone)
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

/**
 * Safe YYYY-MM-DD for `<input type="date">` from API values.
 * Handles date-only strings and ISO datetimes shifted by UTC midnight serialization.
 */
export function toDateInputValue(value: string | null | undefined, timeZone: string = APP_TIMEZONE): string {
  if (!value) return ''
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) {
    const m = String(value).match(/^(\d{4}-\d{2}-\d{2})/)
    return m?.[1] ?? ''
  }
  return ymdInZone(d, timeZone)
}

/**
 * Calendar date for display in Asia/Damascus (e.g. 28/07/2026).
 * Never use String(iso).slice(0, 10) — Laravel date casts serialize as previous-day UTC.
 */
export function formatDateLocal(value: string | Date | null | undefined, timeZone: string = APP_TIMEZONE): string {
  if (value == null || value === '') return '—'
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [y, m, d] = value.split('-')
    return `${d}/${m}/${y}`
  }
  const d = typeof value === 'string' ? new Date(value) : value
  if (Number.isNaN(d.getTime())) return String(value)
  return new Intl.DateTimeFormat(LIVE_CLOCK_LOCALE, {
    timeZone,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(d)
}

/** Wall-clock HH:mm in Asia/Damascus (Latin digits). */
export function formatTimeLocal(value: string | Date | null | undefined, timeZone: string = APP_TIMEZONE): string {
  if (value == null || value === '') return ''
  const d = typeof value === 'string' ? new Date(value) : value
  if (Number.isNaN(d.getTime())) return ''
  return new Intl.DateTimeFormat(LIVE_CLOCK_LOCALE, {
    timeZone,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(d)
}

/**
 * Invoice date + creation time for list/detail/print.
 * Date from invoice_date; time from created_at (or posted_at) when available.
 */
export function formatInvoiceDateTime(
  invoiceDate: string | null | undefined,
  createdAt?: string | null,
  timeZone: string = APP_TIMEZONE,
): string {
  const datePart = formatDateLocal(invoiceDate, timeZone)
  const timePart = formatTimeLocal(createdAt, timeZone)
  return timePart ? `${datePart} ${timePart}` : datePart
}

/** Format an API timestamp for display in Asia/Damascus. */
export function formatDateTimeLocal(value: string | Date, timeZone: string = APP_TIMEZONE): string {
  const d = typeof value === 'string' ? new Date(value) : value
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleString(LIVE_CLOCK_LOCALE, { timeZone })
}

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
