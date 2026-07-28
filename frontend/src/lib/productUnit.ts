export type ProductUnit = { name?: string; symbol?: string }

export function formatProductUnit(unit?: ProductUnit | null): string {
  if (!unit) return '—'
  return unit.symbol?.trim() || unit.name?.trim() || '—'
}

export function unitFromProduct(product?: { unit?: ProductUnit | null } | null): string {
  return formatProductUnit(product?.unit)
}
