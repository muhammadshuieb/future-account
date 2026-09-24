import type { ProductIdentity } from '@/lib/productLabel'

export function ProductIdentityCells({ product }: { product?: ProductIdentity | null }) {
  return (
    <>
      <td>{product?.name?.trim() || '—'}</td>
      <td>{product?.brand?.trim() || '—'}</td>
      <td>{product?.model?.trim() || '—'}</td>
    </>
  )
}

export type ProductIdentityStackedLabels = {
  product: string
  brand: string
  model: string
}

/** Compact stacked identity for single-column tables (statement invoice lines). */
export function ProductIdentityStacked({
  product,
  labels,
  serialNo,
}: {
  product?: ProductIdentity | null
  labels: ProductIdentityStackedLabels
  serialNo?: string | null
}) {
  const name = product?.name?.trim() || '—'
  const brand = product?.brand?.trim() || ''
  const model = product?.model?.trim() || ''

  return (
    <div className="product-identity-stacked">
      <div className="product-identity-stacked__row">
        <span className="product-identity-stacked__label">{labels.product}:</span>
        <span className="product-identity-stacked__value">{name}</span>
      </div>
      {brand ? (
        <div className="product-identity-stacked__row">
          <span className="product-identity-stacked__label">{labels.brand}:</span>
          <span className="product-identity-stacked__value">{brand}</span>
        </div>
      ) : null}
      {model ? (
        <div className="product-identity-stacked__row">
          <span className="product-identity-stacked__label">{labels.model}:</span>
          <span className="product-identity-stacked__value">{model}</span>
        </div>
      ) : null}
      {serialNo ? (
        <span className="product-identity-stacked__serial">{serialNo}</span>
      ) : null}
    </div>
  )
}
