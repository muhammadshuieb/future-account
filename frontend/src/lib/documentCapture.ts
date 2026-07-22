import html2canvas from 'html2canvas'
import { jsPDF } from 'jspdf'

export type CaptureFormat = 'pdf' | 'png'

export type CapturedFile = {
  blob: Blob
  fileName: string
  mimeType: string
  format: CaptureFormat
}

function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/** Hide elements that should not appear in the capture (toolbar, etc.). */
async function withHiddenPrintChrome<T>(root: Document, run: () => Promise<T>): Promise<T> {
  const hide = Array.from(root.querySelectorAll<HTMLElement>('.print-hide'))
  const prev = hide.map((el) => el.style.visibility)
  hide.forEach((el) => {
    el.style.visibility = 'hidden'
  })
  try {
    return await run()
  } finally {
    hide.forEach((el, i) => {
      el.style.visibility = prev[i] || ''
    })
  }
}

export async function captureElement(
  element: HTMLElement,
  opts: { format: CaptureFormat; fileName: string },
): Promise<CapturedFile> {
  const canvas = await html2canvas(element, {
    scale: 2,
    useCORS: true,
    allowTaint: true,
    backgroundColor: '#ffffff',
    logging: false,
  })

  const baseName = opts.fileName.replace(/\.(pdf|png)$/i, '') || 'document'

  if (opts.format === 'png') {
    const blob = await new Promise<Blob>((resolve, reject) => {
      canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('PNG capture failed'))), 'image/png')
    })
    return { blob, fileName: `${baseName}.png`, mimeType: 'image/png', format: 'png' }
  }

  const imgData = canvas.toDataURL('image/png')
  const pdf = new jsPDF({
    orientation: canvas.width >= canvas.height ? 'landscape' : 'portrait',
    unit: 'pt',
    format: 'a4',
  })
  const pageWidth = pdf.internal.pageSize.getWidth()
  const pageHeight = pdf.internal.pageSize.getHeight()
  const margin = 24
  const maxW = pageWidth - margin * 2
  const maxH = pageHeight - margin * 2
  const ratio = Math.min(maxW / canvas.width, maxH / canvas.height)
  const w = canvas.width * ratio
  const h = canvas.height * ratio
  const x = (pageWidth - w) / 2
  const y = margin
  pdf.addImage(imgData, 'PNG', x, y, w, h)
  const blob = pdf.output('blob')
  return { blob, fileName: `${baseName}.pdf`, mimeType: 'application/pdf', format: 'pdf' }
}

export function downloadBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 30_000)
}

export async function captureSelectorInDocument(
  doc: Document,
  selector: string,
  opts: { format: CaptureFormat; fileName: string },
): Promise<CapturedFile> {
  const el = doc.querySelector<HTMLElement>(selector)
  if (!el) throw new Error(`لم يُعثر على منطقة الطباعة (${selector})`)
  return withHiddenPrintChrome(doc, () => captureElement(el, opts))
}

/** Open a same-origin print path, wait for `.print-area`, capture, then close the popup. */
export async function captureFromPrintPopup(
  path: string,
  opts: { format: CaptureFormat; fileName: string; selector?: string; timeoutMs?: number },
): Promise<CapturedFile> {
  const selector = opts.selector || '.print-area'
  const timeoutMs = opts.timeoutMs ?? 25_000
  const width = 920
  const height = 1100
  const left = Math.max(0, Math.round((window.screen.availWidth - width) / 2))
  const top = Math.max(0, Math.round((window.screen.availHeight - height) / 2))
  const features = [
    'popup=yes',
    `width=${width}`,
    `height=${height}`,
    `left=${left}`,
    `top=${top}`,
    'scrollbars=yes',
    'resizable=yes',
  ].join(',')

  const win = window.open(path, 'fa-whatsapp-capture', features)
  if (!win) {
    throw new Error('تم حظر النافذة المنبثقة — اسمح بالنوافذ المنبثقة ثم أعد المحاولة')
  }

  const started = Date.now()
  try {
    while (Date.now() - started < timeoutMs) {
      try {
        const doc = win.document
        const el = doc.querySelector<HTMLElement>(selector)
        const ready = el && el.innerText.trim().length > 20 && !doc.body.innerText.includes('جاري التحميل')
        if (ready) {
          await sleep(400)
          return await captureSelectorInDocument(doc, selector, opts)
        }
      } catch {
        // cross-origin or not ready
      }
      await sleep(250)
    }
    throw new Error('انتهت مهلة تحميل مستند الطباعة')
  } finally {
    try {
      win.close()
    } catch {
      /* ignore */
    }
  }
}
