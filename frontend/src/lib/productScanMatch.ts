import { productLabel, type ProductIdentity } from '@/lib/productLabel'

type ScanCandidate = ProductIdentity & { id: number; barcode?: string | null }

/**
 * Scan/lookup fields accept a barcode, a SKU, or free text. Free text may match
 * several products (same name and brand, different model), so the caller must
 * disambiguate instead of silently taking the first row.
 */
export function matchScannedProduct<T extends ScanCandidate>(rows: T[], code: string): {
  product?: T
  ambiguous: T[]
} {
  const needle = code.trim().toLowerCase()
  const exact = rows.find((row) => (row.barcode || '').trim().toLowerCase() === needle
    || (row.sku || '').trim().toLowerCase() === needle)

  if (exact) return { product: exact, ambiguous: [] }
  if (rows.length === 1) return { product: rows[0], ambiguous: [] }

  return { ambiguous: rows }
}

/** Short list of matches for an on-screen hint, e.g. "name — brand — model". */
export function describeMatches(rows: ProductIdentity[], limit = 3): string {
  const labels = rows.slice(0, limit).map((row) => productLabel(row))
  return rows.length > limit ? `${labels.join(' | ')} …` : labels.join(' | ')
}
