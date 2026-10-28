import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { Button } from '@/components/ui'
import { StampPrintContext } from '@/components/PrintStamp'
import PdfExportButton from '@/components/PdfExportButton'
import WhatsAppSendButton from '@/components/WhatsAppSendButton'
import { DocumentBrandProvider, useDocumentBrand } from '@/context/DocumentBrandContext'
import { isDocumentBrandId, type DocumentBrandId } from '@/lib/brand'

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
  /** When false, hide brand picker (e.g. internal reports that stay Syna-only). Default true. */
  brandSelectable?: boolean
}

function PrintDocumentToolbar({
  hint,
  fileName,
  whatsapp,
  brandSelectable,
  withStamp,
  setWithStamp,
  printPlain,
  printWithStamp,
}: {
  hint: string
  fileName: string
  whatsapp?: WhatsAppProps | null
  brandSelectable: boolean
  withStamp: boolean
  setWithStamp: (v: boolean | ((prev: boolean) => boolean)) => void
  printPlain: () => void
  printWithStamp: () => void
}) {
  const { t } = useTranslation()
  const { brandId, brand, setBrandId, brands } = useDocumentBrand()

  useEffect(() => {
    const base = document.title.replace(/\s—\s(?:Syna Co|البيرق تك|Al-Bairaq Tech)\s*$/i, '').trim()
    if (base) document.title = `${base} — ${brand.titleSuffix}`
  }, [brand.titleSuffix])

  return (
    <div className="print-hide mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-black/10 bg-white p-3">
      {brandSelectable ? (
        <label className="flex items-center gap-1.5 text-xs text-black/70">
          <span className="whitespace-nowrap font-semibold">{t('common.documentBrand')}</span>
          <select
            className="rounded-md border border-black/15 bg-white px-2 py-1.5 text-xs font-semibold text-ink"
            value={brandId}
            onChange={(e) => {
              const next = e.target.value
              if (isDocumentBrandId(next)) setBrandId(next)
            }}
            aria-label={t('common.documentBrand')}
          >
            {brands.map((b) => (
              <option key={b.id} value={b.id}>
                {b.label}
              </option>
            ))}
          </select>
        </label>
      ) : null}
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
  )
}

function PrintDocumentShellInner({ hint, fileName, children, whatsapp, brandSelectable }: Props & { brandSelectable: boolean }) {
  const [withStamp, setWithStamp] = useState(false)
  const pendingPrint = useRef<'plain' | 'stamp' | null>(null)
  const stampImgReady = useRef(false)
  const { brandId } = useDocumentBrand()

  useEffect(() => {
    stampImgReady.current = false
  }, [brandId])

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
      <PrintDocumentToolbar
        hint={hint}
        fileName={fileName}
        whatsapp={whatsapp}
        brandSelectable={brandSelectable}
        withStamp={withStamp}
        setWithStamp={setWithStamp}
        printPlain={printPlain}
        printWithStamp={printWithStamp}
      />
      <div className="print-sheet">
        <StampPrintContext.Provider value={stampCtx}>
          <div className="print-area" data-print-ready="1" data-document-brand={brandId}>
            {children}
          </div>
        </StampPrintContext.Provider>
      </div>
    </div>
  )
}

/** Shared print popup chrome: brand identity + plain print vs print-with-company-stamp. */
export default function PrintDocumentShell({
  hint,
  fileName,
  children,
  whatsapp,
  brandSelectable = true,
}: Props) {
  const initialFromUrl = (() => {
    if (typeof window === 'undefined') return undefined
    const raw = new URLSearchParams(window.location.search).get('brand')
    return isDocumentBrandId(raw) ? (raw as DocumentBrandId) : undefined
  })()

  return (
    <DocumentBrandProvider initialBrandId={initialFromUrl}>
      <PrintDocumentShellInner
        hint={hint}
        fileName={fileName}
        whatsapp={whatsapp}
        brandSelectable={brandSelectable}
      >
        {children}
      </PrintDocumentShellInner>
    </DocumentBrandProvider>
  )
}
