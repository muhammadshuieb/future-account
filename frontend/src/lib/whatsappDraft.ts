import type { TFunction } from 'i18next'

/** «الفترة: من … حتى …» / until today when `to` is empty. */
export function formatWhatsAppPeriod(
  t: TFunction,
  from?: string | null,
  to?: string | null,
): string | null {
  if (!from && !to) return null
  return t('whatsapp.periodLine', {
    from: from || '…',
    to: to || t('whatsapp.untilToday'),
  })
}

export function statementMessageDetails(
  t: TFunction,
  opts: {
    partnerName?: string | null
    partnerKind?: 'customer' | 'supplier'
    from?: string | null
    to?: string | null
  },
): string[] {
  const lines: string[] = []
  if (opts.partnerName?.trim()) {
    const label =
      opts.partnerKind === 'supplier' ? t('whatsapp.supplierName') : t('whatsapp.customerName')
    lines.push(`${label}: ${opts.partnerName.trim()}`)
  }
  const period = formatWhatsAppPeriod(t, opts.from, opts.to)
  if (period) lines.push(period)
  return lines
}

export function invoiceMessageDetails(
  t: TFunction,
  opts: {
    partnerName?: string | null
    partnerKind?: 'customer' | 'supplier'
    documentNumber?: string | null
    numberLabel?: string
  },
): string[] {
  const lines: string[] = []
  if (opts.partnerName?.trim()) {
    const label =
      opts.partnerKind === 'supplier' ? t('whatsapp.supplierName') : t('whatsapp.customerName')
    lines.push(`${label}: ${opts.partnerName.trim()}`)
  }
  if (opts.documentNumber?.trim()) {
    lines.push(`${opts.numberLabel || t('whatsapp.invoiceNumber')}: ${opts.documentNumber.trim()}`)
  }
  return lines
}
