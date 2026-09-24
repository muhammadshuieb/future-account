import { useTranslation } from 'react-i18next'
import type { ProductIdentity } from '@/lib/productLabel'

export function ProductIdentityHeaders({ className }: { className?: string }) {
  const { t } = useTranslation()
  return (
    <>
      <th className={className}>{t('common.product')}</th>
      <th className={className}>{t('warehouse.brand')}</th>
      <th className={className}>{t('warehouse.model')}</th>
    </>
  )
}

export function ProductIdentityCells({
  product,
  className,
  serialNo,
}: {
  product?: ProductIdentity | null
  className?: string
  serialNo?: string | null
}) {
  return (
    <>
      <td className={className}>
        {product?.name?.trim() || '—'}
        {serialNo ? <span className="product-identity-serial">{serialNo}</span> : null}
      </td>
      <td className={className}>{product?.brand?.trim() || '—'}</td>
      <td className={className}>{product?.model?.trim() || '—'}</td>
    </>
  )
}
