import { LOGO } from '@/lib/brand'
import { useDocumentBrand } from '@/context/DocumentBrandContext'

/** Official circular company stamp — transparent PNG for ink/multiply print. */
export default function CompanyStamp({
  className = '',
  onLoad,
}: {
  className?: string
  onLoad?: () => void
}) {
  const { brand } = useDocumentBrand()
  const src = brand.stamp || LOGO.stamp

  return (
    <div
      className={`company-stamp company-stamp--${brand.id} print-avoid-break ${className}`.trim()}
      aria-hidden="true"
    >
      <img
        key={src}
        src={src}
        alt=""
        className="company-stamp__img"
        draggable={false}
        onLoad={onLoad}
      />
    </div>
  )
}
