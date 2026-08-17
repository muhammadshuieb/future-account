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
