import { LOGO } from '@/lib/brand'

/** Official circular company stamp for optional invoice print. */
export default function CompanyStamp({
  className = '',
  onLoad,
}: {
  className?: string
  onLoad?: () => void
}) {
  return (
    <div className={`company-stamp print-avoid-break ${className}`.trim()} aria-hidden="true">
      <img
        src={LOGO.stamp}
        alt=""
        className="company-stamp__img"
        draggable={false}
        onLoad={onLoad}
      />
    </div>
  )
}
