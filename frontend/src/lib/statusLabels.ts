import i18n from '@/i18n'

const STATUS_KEYS = [
  'draft',
  'posted',
  'void',
  'converted',
  'cancelled',
  'pending',
  'approved',
  'rejected',
  'paid',
  'partial',
  'open',
  'closed',
] as const

/** Human-readable document status labels via i18n (ar / en / tr). */
export function documentStatusLabel(status: string | null | undefined): string {
  if (!status) return '—'
  const key = String(status).toLowerCase()
  if ((STATUS_KEYS as readonly string[]).includes(key)) {
    return i18n.t(`status.${key}`)
  }
  return status
}
