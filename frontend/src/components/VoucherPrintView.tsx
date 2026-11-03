import { useTranslation } from 'react-i18next'
import { useDocumentBrand } from '@/context/DocumentBrandContext'
import { formatMoney } from '@/components/ui'
import { formatDateLocal } from '@/lib/dates'
import PrintStamp from '@/components/PrintStamp'

export type VoucherKind = 'receipt' | 'payment'

export type VoucherPrintData = {
  kind: VoucherKind
  voucher_number: string
  voucher_date: string
  party_name: string
  amount: number | string
  currency: string
  method?: string
  description?: string
  notes?: string
}

function BrandLogo() {
  const { brand } = useDocumentBrand()
  return (
    <img
      key={brand.logoPrint}
      src={brand.logoPrint}
      alt={brand.brandMark}
      className={`brand-logo brand-logo--print print-logo voucher-print__logo${brand.id === 'albairaq' ? ' brand-logo--print-albairaq' : ''}`}
      onError={(e) => {
        const img = e.currentTarget
        if (img.dataset.fallback === '1') return
        img.dataset.fallback = '1'
        img.src = brand.logoFallback
      }}
    />
  )
}

export default function VoucherPrintView({ voucher }: { voucher: VoucherPrintData }) {
  const { t } = useTranslation()
  const { brand } = useDocumentBrand()
  const isReceipt = voucher.kind === 'receipt'
  const title = isReceipt ? t('vouchers.receiptVoucher') : t('vouchers.paymentVoucher')
  const partyLabel = isReceipt ? t('vouchers.receivedFrom') : t('vouchers.paidTo')
  const amount = Number(voucher.amount) || 0

  return (
    <div className={`voucher-print voucher-print--${voucher.kind}`} dir="rtl">
      <header className="voucher-print__header">
        <div className="voucher-print__identity">
          <p className="voucher-print__company">{brand.companyName}</p>
          <p className="voucher-print__company-en">{brand.companyNameEn}</p>
          {brand.tagline ? <p className="voucher-print__tagline">{brand.tagline}</p> : null}
        </div>
        <BrandLogo />
      </header>

      <div className="voucher-print__title-row">
        <h1 className="voucher-print__title">{title}</h1>
        <div className="voucher-print__meta">
          <p>
            <span>{t('common.number')}: </span>
            <strong className="font-mono">{voucher.voucher_number || '—'}</strong>
          </p>
          <p>
            <span>{t('common.date')}: </span>
            <strong>{formatDateLocal(voucher.voucher_date)}</strong>
          </p>
        </div>
      </div>

      <div className="voucher-print__body">
        <p className="voucher-print__party">
          <span className="voucher-print__label">{partyLabel}: </span>
          <strong>{voucher.party_name || '—'}</strong>
        </p>

        <p className="voucher-print__amount">
          <span className="voucher-print__label">{t('common.amount')}: </span>
          <strong className="tabular-nums">{formatMoney(amount, voucher.currency || 'USD')}</strong>
        </p>

        {voucher.method ? (
          <p>
            <span className="voucher-print__label">{t('vouchers.method')}: </span>
            {voucher.method}
          </p>
        ) : null}

        {voucher.description ? (
          <p>
            <span className="voucher-print__label">{t('vouchers.description')}: </span>
            {voucher.description}
          </p>
        ) : null}

        {voucher.notes ? (
          <p>
            <span className="voucher-print__label">{t('common.notes')}: </span>
            {voucher.notes}
          </p>
        ) : null}
      </div>

      <footer className="voucher-print__footer">
        <div className="voucher-print__sign">
          <p>{isReceipt ? t('vouchers.receiverSign') : t('vouchers.payerSign')}</p>
          <div className="voucher-print__sign-line" />
        </div>
        <PrintStamp className="voucher-print__stamp" />
        <div className="voucher-print__sign">
          <p>{t('vouchers.accountantSign')}</p>
          <div className="voucher-print__sign-line" />
        </div>
      </footer>
    </div>
  )
}
