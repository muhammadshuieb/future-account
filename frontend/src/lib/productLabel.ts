export type ProductIdentity = {
  name?: string | null
  brand?: string | null
  model?: string | null
  sku?: string | null
}

/** Name plus brand/model so items stay distinguishable wherever only one column is available. */
export function productLabel(product?: ProductIdentity | null): string {
  if (!product) return '—'
  const parts = [product.name, product.brand, product.model]
    .map((part) => part?.trim())
    .filter((part): part is string => Boolean(part))
  return parts.length ? parts.join(' — ') : '—'
}

export function productDetails(product?: ProductIdentity | null): string {
  if (!product) return ''
  return [product.brand, product.model]
    .map((part) => part?.trim())
    .filter((part): part is string => Boolean(part))
    .join(' — ')
}
