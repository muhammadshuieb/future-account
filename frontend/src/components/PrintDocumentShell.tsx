import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { Button } from '@/components/ui'
import { StampPrintContext } from '@/components/PrintStamp'
import PdfExportButton from '@/components/PdfExportButton'
import WhatsAppSendButton from '@/components/WhatsAppSendButton'
type WhatsAppProps = {
  defaultPhone?: string
  documentLabel: string
  messageDetails: string[]
}

type Props = {
  hint: string
  fileName: string
  children: ReactNode
  whatsapp?: WhatsAppProps | null
}

/** Shared print popup chrome: plain print vs print-with-company-stamp. */
export default function PrintDocumentShell({ hint, fileName, children, whatsapp }: Props) {
  const { t } = useTranslation()
  const [withStamp, setWithStamp] = useState(false)
  const pendingPrint = useRef<'plain' | 'stamp' | null>(null)
  const stampImgReady = useRef(false)

  useEffect(() => {
    if (pendingPrint.current !== 'stamp' || !withStamp) return
    const delay = stampImgReady.current ? 60 : 200
    const timer = window.setTimeout(() => {
      pendingPrint.current = null
      window.print()
    }, delay)
    return () => window.clearTimeout(timer)
  }, [withStamp])

  function printPlain() {
    pendingPrint.current = null
    if (withStamp) {
      setWithStamp(false)
      window.setTimeout(() => window.print(), 60)
      return
    }
    window.print()
  }

  function printWithStamp() {
    if (withStamp) {
      window.print()
      return
    }
    pendingPrint.current = 'stamp'
    setWithStamp(true)
  }

  const stampCtx = useMemo(
    () => ({
      enabled: withStamp,
      onLoad: () => {
        stampImgReady.current = true
      },
    }),
    [withStamp],
  )

  return (
    <div className="print-document min-h-0 p-4 text-black" dir="rtl">
      <div className="print-hide mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-black/10 bg-white p-3">
        <Button variant="primary" onClick={printPlain}>
          <Printer size={16} /> {t('common.printPlain')}
        </Button>
        <Button variant={withStamp ? 'primary' : 'secondary'} onClick={printWithStamp}>
          <Printer size={16} /> {t('common.printWithStamp')}
        </Button>
        <button
          type="button"
          className={`rounded-md border px-2.5 py-1.5 text-xs transition ${
            withStamp
              ? 'border-teal/40 bg-teal/10 font-semibold text-teal'
              : 'border-black/10 text-black/60 hover:border-black/20'
          }`}
          onClick={() => setWithStamp((v) => !v)}
          aria-pressed={withStamp}
        >
          {withStamp ? t('common.stampOn') : t('common.stampOff')}
        </button>
        <PdfExportButton fileName={fileName} />
        {whatsapp ? (
          <WhatsAppSendButton
            defaultPhone={whatsapp.defaultPhone}
            fileName={fileName}
            documentLabel={whatsapp.documentLabel}
            messageDetails={whatsapp.messageDetails}
          />
        ) : null}
        <Button variant="secondary" onClick={() => window.close()}>
          {t('common.close')}
        </Button>
        <p className="text-xs text-black/45">{hint}</p>
      </div>
      <div className="print-sheet">
        <StampPrintContext.Provider value={stampCtx}>
          <div className="print-area" data-print-ready="1">
            {children}
          </div>
        </StampPrintContext.Provider>
      </div>
    </div>
  )
}
