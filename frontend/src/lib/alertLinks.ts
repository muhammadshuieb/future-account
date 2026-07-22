/** Resolve where a dashboard/header alert should navigate. */
export function resolveAlertHref(alert: {
  type?: string
  code?: string
  title?: string
  body?: string
  href?: string | null
  data?: { href?: string } | null
}): string | null {
  if (alert.href) return alert.href
  if (alert.data?.href) return alert.data.href

  const code = (alert.code || alert.type || '').toLowerCase()
  const text = `${alert.title || ''} ${alert.body || ''}`

  if (code === 'low_stock' || code.includes('stock') || text.includes('مخزون') || text.includes('إعادة الطلب')) {
    return '/warehouse?tab=alerts'
  }
  if (code === 'receivables' || code.includes('receivable') || text.includes('ذمم مدينة') || text.includes('مستحق')) {
    return '/partners?tab=customers'
  }
  if (code === 'payables' || text.includes('ذمم دائنة')) {
    return '/partners?tab=suppliers'
  }
  if (code === 'draft_journals' || code.includes('journal') || text.includes('قيود') || text.includes('مسودة')) {
    return '/journal-entries'
  }
  if (
    code.includes('backup')
    || code.includes('drive')
    || text.includes('Google Drive')
    || text.includes('نسخ احتياط')
    || text.includes('النسخ')
  ) {
    return '/settings?tab=backup'
  }

  return null
}
