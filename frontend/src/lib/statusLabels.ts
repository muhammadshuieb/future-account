/** Human-readable document status labels (UI). */
const LABELS: Record<string, string> = {
  draft: 'مسودة',
  posted: 'مرحّل',
  void: 'ملغى',
  converted: 'محوّل',
  cancelled: 'ملغى',
  pending: 'قيد الانتظار',
  approved: 'معتمد',
  rejected: 'مرفوض',
  paid: 'مدفوع',
  partial: 'جزئي',
  open: 'مفتوح',
  closed: 'مغلق',
}

export function documentStatusLabel(status: string | null | undefined): string {
  if (!status) return '—'
  const key = String(status).toLowerCase()
  return LABELS[key] || status
}
