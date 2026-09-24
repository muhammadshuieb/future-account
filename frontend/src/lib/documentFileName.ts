/**
 * Human-readable download / WhatsApp attachment names:
 * `{docType} — {partnerName}.{ext}`
 */

const ILLEGAL = /[<>:"/\\|?*\u0000-\u001f\u007f]/g

/** Strip illegal filesystem characters and normalize whitespace. */
export function sanitizeFileName(name: string): string {
  const cleaned = String(name ?? '')
    .replace(ILLEGAL, '')
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/[. ]+$/g, '')
  return cleaned || 'document'
}

/**
 * Base name without extension: `{docType} — {partnerName}`.
 * Omits the em dash segment when partner name is empty.
 */
export function buildDocumentBaseName(docType: string, partnerName?: string | null): string {
  const type = sanitizeFileName(docType)
  const partner = partnerName ? sanitizeFileName(partnerName) : ''
  if (!partner) return type
  return `${type} — ${partner}`
}

/** Full filename with optional extension (with or without leading dot). */
export function buildDocumentFileName(
  docType: string,
  partnerName?: string | null,
  extension?: string | null,
): string {
  const base = buildDocumentBaseName(docType, partnerName)
  if (!extension) return base
  const ext = String(extension).replace(/^\./, '').toLowerCase()
  return `${base}.${ext}`
}

/**
 * Ensure a download name is safe for the filesystem while preserving
 * a trailing extension (e.g. `.pdf`, `.xlsx`).
 */
export function safeDownloadFileName(fileName: string, fallbackExt?: string): string {
  const trimmed = String(fileName ?? '').trim()
  const match = /\.([a-z0-9]{1,8})$/i.exec(trimmed)
  if (match) {
    const ext = match[1].toLowerCase()
    const base = sanitizeFileName(trimmed.slice(0, -match[0].length))
    return `${base}.${ext}`
  }
  const base = sanitizeFileName(trimmed)
  if (fallbackExt) {
    return `${base}.${String(fallbackExt).replace(/^\./, '').toLowerCase()}`
  }
  return base
}
