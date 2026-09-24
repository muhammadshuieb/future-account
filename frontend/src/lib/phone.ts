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

/** Classic wa.me link (app / web redirect). */
export function whatsAppChatUrl(phone: string, text?: string): string | null {
  const normalized = normalizeWhatsAppPhone(phone)
  if (!normalized) return null
  const base = `https://wa.me/${normalized}`
  if (!text) return base
  return `${base}?text=${encodeURIComponent(text)}`
}

/** WhatsApp Desktop / mobile app deep link. */
export function whatsAppDesktopUrl(phone: string, text?: string): string | null {
  const normalized = normalizeWhatsAppPhone(phone)
  if (!normalized) return null
  const base = `whatsapp://send?phone=${normalized}`
  if (!text) return base
  return `${base}&text=${encodeURIComponent(text)}`
}

/** WhatsApp Web send URL (fallback when Desktop is not installed). */
export function whatsAppWebUrl(phone: string, text?: string): string | null {
  const normalized = normalizeWhatsAppPhone(phone)
  if (!normalized) return null
  const base = `https://web.whatsapp.com/send?phone=${normalized}`
  if (!text) return base
  return `${base}&text=${encodeURIComponent(text)}`
}

/**
 * Open WhatsApp Desktop when possible; if the protocol does not take focus,
 * fall back to WhatsApp Web (then wa.me).
 * Pure web apps cannot attach files into WhatsApp — caller should download first.
 */
export function openWhatsAppChat(phone: string, text?: string): 'desktop' | 'web' | 'wa.me' | null {
  const desktop = whatsAppDesktopUrl(phone, text)
  const web = whatsAppWebUrl(phone, text)
  const waMe = whatsAppChatUrl(phone, text)
  if (!desktop && !web && !waMe) return null

  let opened: 'desktop' | 'web' | 'wa.me' | null = null

  if (desktop) {
    try {
      const a = document.createElement('a')
      a.href = desktop
      a.rel = 'noopener'
      a.style.display = 'none'
      document.body.appendChild(a)
      a.click()
      a.remove()
      opened = 'desktop'
    } catch {
      opened = null
    }
  }

  // If Desktop protocol did not steal focus, open Web (or wa.me) after a short wait.
  window.setTimeout(() => {
    if (document.hidden || !document.hasFocus()) return
    const fallback = web || waMe
    if (!fallback) return
    window.open(fallback, '_blank', 'noopener,noreferrer')
  }, 1200)

  return opened ?? (web ? 'web' : waMe ? 'wa.me' : null)
}
