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

function isPrintContentReady(doc: Document, selector: string): boolean {
  const el = doc.querySelector<HTMLElement>(selector)
  if (!el) return false
  if (el.getAttribute('data-print-ready') === '1') return true
  const text = el.innerText.replace(/\s+/g, ' ').trim()
  if (text.length < 20) return false
  // Loading screens replace the whole page (no .print-area); if we have content, we're ready.
  return true
}

function assertNotLoginPage(win: Window) {
  try {
    const path = win.location.pathname || ''
    if (path.includes('/login')) {
      throw new Error('انتهت الجلسة — سجّل الدخول ثم أعد المحاولة')
    }
  } catch (e) {
    if (e instanceof Error && e.message.includes('الجلسة')) throw e
  }
}

/**
 * Load a same-origin print route in a hidden iframe, wait for `.print-area`, capture, then remove.
 * Avoids the visible flickering popup that previously timed out.
 */
export async function captureFromPrintPopup(
  path: string,
  opts: { format: CaptureFormat; fileName: string; selector?: string; timeoutMs?: number },
): Promise<CapturedFile> {
  const selector = opts.selector || '.print-area'
  const timeoutMs = opts.timeoutMs ?? 45_000
  const absoluteUrl = new URL(path, window.location.origin).href

  const iframe = document.createElement('iframe')
  iframe.setAttribute('title', 'syna-document-capture')
  iframe.setAttribute('aria-hidden', 'true')
  iframe.setAttribute('tabindex', '-1')
  // Keep real dimensions in the layout tree so html2canvas can paint; hide visually.
  Object.assign(iframe.style, {
    position: 'fixed',
    left: '0',
    top: '0',
    width: '920px',
    height: '1400px',
    opacity: '0',
    pointerEvents: 'none',
    border: '0',
    zIndex: '-1',
  })

  document.body.appendChild(iframe)

  try {
    await new Promise<void>((resolve, reject) => {
      const timer = window.setTimeout(() => reject(new Error('انتهت مهلة تحميل مستند الطباعة')), timeoutMs)
      iframe.onload = () => {
        window.clearTimeout(timer)
        resolve()
      }
      iframe.onerror = () => {
        window.clearTimeout(timer)
        reject(new Error('فشل تحميل مستند الطباعة'))
      }
      iframe.src = absoluteUrl
    })

    const started = Date.now()
    while (Date.now() - started < timeoutMs) {
      const win = iframe.contentWindow
      const doc = iframe.contentDocument
      if (!win || !doc) {
        await sleep(200)
        continue
      }

      assertNotLoginPage(win)

      if (isPrintContentReady(doc, selector)) {
        // Allow fonts / late images a brief settle before capture.
        await sleep(500)
        if (isPrintContentReady(doc, selector)) {
          return await captureSelectorInDocument(doc, selector, opts)
        }
      }

      await sleep(250)
    }

    throw new Error('انتهت مهلة تحميل مستند الطباعة')
  } finally {
    iframe.remove()
  }
}
