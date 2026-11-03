import { useEffect, useMemo, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
import PrintDocumentShell from '@/components/PrintDocumentShell'
import VoucherPrintView, { type VoucherPrintData } from '@/components/VoucherPrintView'
import { buildDocumentBaseName } from '@/lib/documentFileName'
import { readVoucherForPrint } from '@/lib/voucherPrint'

export default function VoucherPrintPage() {
  const { t } = useTranslation()
  const { user, loading } = useAuth()
  const [voucher, setVoucher] = useState<VoucherPrintData | null>(() => readVoucherForPrint())

  useEffect(() => {
    setVoucher(readVoucherForPrint())
  }, [])

  const title = voucher?.kind === 'payment'
    ? t('vouchers.paymentVoucher')
    : t('vouchers.receiptVoucher')

  const fileBaseName = useMemo(
    () => buildDocumentBaseName(title, voucher?.party_name),
    [title, voucher?.party_name],
  )

  useEffect(() => {
    if (!voucher) return undefined
    document.title = fileBaseName
    document.body.classList.add('voucher-half-a4-print')
    return () => document.body.classList.remove('voucher-half-a4-print')
  }, [voucher, fileBaseName])

  if (loading) {
    return <div className="p-8 text-center text-sm text-black/55">{t('common.loading')}</div>
  }
  if (!user) return <Navigate to="/login" replace />

  if (!voucher) {
    return (
      <div className="p-8 text-center text-sm text-danger">
        {t('vouchers.printDataMissing')}
      </div>
    )
  }

  return (
    <PrintDocumentShell
      hint={t('vouchers.printPreviewHint')}
      fileName={fileBaseName}
      documentClassName="print-document--half-a4"
      sheetClassName="print-sheet--half-a4"
      whatsapp={{
        documentLabel: title,
        messageDetails: [
          `${t('common.number')}: ${voucher.voucher_number || '—'}`,
          `${t('common.date')}: ${voucher.voucher_date}`,
          voucher.party_name ? voucher.party_name : '',
        ].filter(Boolean),
      }}
    >
      <VoucherPrintView voucher={voucher} />
    </PrintDocumentShell>
  )
}
