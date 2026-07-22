/** Digits only from a phone string. */
export function digitsOnly(phone: string): string {
  return String(phone || '').replace(/\D/g, '')
}

/**
 * Normalize Syrian (and common local) numbers to WhatsApp international form without '+'.
 * Examples: 0944123456 → 963944123456, +963944123456 → 963944123456, 944123456 → 963944123456
 */
export function normalizeWhatsAppPhone(phone: string): string | null {
  let digits = digitsOnly(phone)
  if (!digits) return null

  // Strip leading 00 international prefix
  if (digits.startsWith('00')) digits = digits.slice(2)

  // Already international Syria
  if (digits.startsWith('963') && digits.length >= 12) {
    return digits
  }

  // Local Syria: 09xxxxxxxx or 9xxxxxxxx
  if (digits.startsWith('09') && digits.length === 10) {
    return `963${digits.slice(1)}`
  }
  if (digits.startsWith('9') && digits.length === 9) {
    return `963${digits}`
  }

  // Other international numbers (keep as-is if long enough)
  if (digits.length >= 10 && digits.length <= 15) {
    return digits
  }

  return null
}

export function whatsAppChatUrl(phone: string, text?: string): string | null {
  const normalized = normalizeWhatsAppPhone(phone)
  if (!normalized) return null
  const base = `https://wa.me/${normalized}`
  if (!text) return base
  return `${base}?text=${encodeURIComponent(text)}`
}
