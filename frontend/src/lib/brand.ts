/** Brand logo assets (SYNAMOR TECHNOLOGY visual identity for Syna Co).
 * Prefer colored (teal+gold) assets. On dark UI, place them on a light chip
 * rather than using inverted white-only marks when the full-color logo exists.
 */
export const LOGO = {
  /** Horizontal teal+gold — light backgrounds / general */
  default: '/logo.png',
  /** Horizontal teal+gold — preferred for UI & login */
  onLight: '/logo-on-light.png',
  /** White horizontal — legacy dark-bg only; prefer chip + onLight */
  onDark: '/logo-on-dark.png',
  /** Vertical stacked teal+gold */
  vertical: '/logo-vertical.png',
  /** High-res horizontal teal+gold for print headers */
  print: '/logo-print.png',
  /** Colored gear mark (teal + gold) */
  mark: '/logo-mark.png',
  /** White gear mark — legacy; prefer chip + mark */
  markOnDark: '/logo-mark-on-dark.png',
  /** Official circular company stamp (optional on invoice print) */
  stamp: '/company-stamp.png',
  svg: '/logo.svg',
} as const

/** Document identity for invoices / quotes print & PDF (UI app chrome stays Syna). */
export type DocumentBrandId = 'syna' | 'albairaq'

export const DEFAULT_DOCUMENT_BRAND: DocumentBrandId = 'syna'

const DOCUMENT_BRAND_STORAGE_KEY = 'fa-document-brand'

export type DocumentBrandProfile = {
  id: DocumentBrandId
  /** Selector label (Arabic UI) */
  label: string
  companyName: string
  companyNameEn: string
  /** Secondary brand line under company name */
  brandMark: string
  tagline?: string
  commercialRegister?: string
  logoPrint: string
  logoFallback: string
  stamp: string
  titleSuffix: string
}

export const DOCUMENT_BRANDS: Record<DocumentBrandId, DocumentBrandProfile> = {
  syna: {
    id: 'syna',
    label: 'ساينا',
    companyName: 'شركة ساينا',
    companyNameEn: 'Syna Co',
    brandMark: 'SYNAMOR TECHNOLOGY',
    logoPrint: LOGO.print,
    logoFallback: LOGO.default,
    stamp: LOGO.stamp,
    titleSuffix: 'Syna Co',
  },
  albairaq: {
    id: 'albairaq',
    label: 'البيرق تك',
    companyName: 'شركة البيرق تك',
    companyNameEn: 'Al-Bairaq Tech',
    brandMark: 'Al-Bairaq Tech',
    tagline: 'تجارة المقاولات واستيراد وتصدير الأدوات الإلكترونية',
    commercialRegister: '20258',
    logoPrint: '/logo-albairaq.jpg',
    logoFallback: '/logo-albairaq.jpg',
    stamp: '/company-stamp-albairaq.jpg',
    titleSuffix: 'البيرق تك',
  },
}

export function isDocumentBrandId(value: string | null | undefined): value is DocumentBrandId {
  return value === 'syna' || value === 'albairaq'
}

export function getDocumentBrand(id: DocumentBrandId = DEFAULT_DOCUMENT_BRAND): DocumentBrandProfile {
  return DOCUMENT_BRANDS[id] ?? DOCUMENT_BRANDS.syna
}

export function readStoredDocumentBrand(): DocumentBrandId {
  try {
    const raw = localStorage.getItem(DOCUMENT_BRAND_STORAGE_KEY)
    if (isDocumentBrandId(raw)) return raw
  } catch {
    /* private mode */
  }
  return DEFAULT_DOCUMENT_BRAND
}

export function storeDocumentBrand(id: DocumentBrandId): void {
  try {
    localStorage.setItem(DOCUMENT_BRAND_STORAGE_KEY, id)
  } catch {
    /* private mode */
  }
}

/** Append `?brand=` so hidden iframe / popup PDF captures use the chosen identity. */
export function withDocumentBrandQuery(
  path: string,
  brand: DocumentBrandId = readStoredDocumentBrand(),
): string {
  if (!path) return path
  try {
    const url = new URL(path, window.location.origin)
    url.searchParams.set('brand', brand)
    return `${url.pathname}${url.search}${url.hash}`
  } catch {
    const sep = path.includes('?') ? '&' : '?'
    return `${path}${sep}brand=${encodeURIComponent(brand)}`
  }
}
