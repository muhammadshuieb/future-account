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

function canvasToPngBlob(canvas: HTMLCanvasElement): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('PNG capture failed'))), 'image/png')
  })
}

/** Slice a tall canvas across multiple A4 pages (portrait or landscape by aspect). */
function canvasToMultiPagePdf(canvas: HTMLCanvasElement): Blob {
  const orientation = canvas.width >= canvas.height * 1.15 ? 'landscape' : 'portrait'
  const pdf = new jsPDF({ orientation, unit: 'pt', format: 'a4' })
  const pageWidth = pdf.internal.pageSize.getWidth()
  const pageHeight = pdf.internal.pageSize.getHeight()
  const margin = 18
  const usableW = pageWidth - margin * 2
  const usableH = pageHeight - margin * 2

  const imgData = canvas.toDataURL('image/png')
  const scaledW = usableW
  const scaledH = (canvas.height * scaledW) / canvas.width

  // Single page fits — center vertically a bit from top margin
  if (scaledH <= usableH) {
    const x = margin
    const y = margin
    pdf.addImage(imgData, 'PNG', x, y, scaledW, scaledH)
    return pdf.output('blob')
  }

  // Multi-page: map source canvas slices to each PDF page
  const pxPerPt = canvas.width / scaledW
  const pageSlicePx = usableH * pxPerPt
  let sourceY = 0
  let pageIndex = 0

  while (sourceY < canvas.height - 1) {
    const sliceHeightPx = Math.min(pageSlicePx, canvas.height - sourceY)
    const sliceCanvas = document.createElement('canvas')
    sliceCanvas.width = canvas.width
    sliceCanvas.height = Math.max(1, Math.ceil(sliceHeightPx))
    const ctx = sliceCanvas.getContext('2d')
    if (!ctx) throw new Error('PDF capture failed')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, sliceCanvas.width, sliceCanvas.height)
    ctx.drawImage(
      canvas,
      0,
      sourceY,
      canvas.width,
      sliceHeightPx,
      0,
      0,
      canvas.width,
      sliceHeightPx,
    )

    const sliceData = sliceCanvas.toDataURL('image/png')
    const sliceH = (sliceHeightPx * scaledW) / canvas.width
    if (pageIndex > 0) pdf.addPage()
    pdf.addImage(sliceData, 'PNG', margin, margin, scaledW, sliceH)

    sourceY += sliceHeightPx
    pageIndex += 1
    // Safety: avoid infinite loops on tiny remainders
    if (sliceHeightPx < 2) break
  }

  return pdf.output('blob')
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
    const blob = await canvasToPngBlob(canvas)
    return { blob, fileName: `${baseName}.png`, mimeType: 'image/png', format: 'png' }
  }

  const blob = canvasToMultiPagePdf(canvas)
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

/** Capture local `.print-area` or a print route and download as PDF. */
export async function exportPdf(opts: {
  fileName: string
  captureSelector?: string
  printPath?: string
}): Promise<CapturedFile> {
  const selector = opts.captureSelector || '.print-area'
  const localEl = document.querySelector<HTMLElement>(selector)
  const hasLocalPrint =
    !!localEl &&
    (localEl.getAttribute('data-print-ready') === '1' ||
      localEl.innerText.replace(/\s+/g, ' ').trim().length > 20)

  const captured = hasLocalPrint
    ? await captureSelectorInDocument(document, selector, { format: 'pdf', fileName: opts.fileName })
    : opts.printPath
      ? await captureFromPrintPopup(opts.printPath, { format: 'pdf', fileName: opts.fileName })
      : await captureSelectorInDocument(document, selector, { format: 'pdf', fileName: opts.fileName })

  downloadBlob(captured.blob, captured.fileName)
  return captured
}

/**
 * Capture multiple print routes and merge into one multi-document PDF
 * (each document starts on a new page).
 */
export async function exportMergedPdfFromPrintPaths(
  paths: string[],
  opts: { fileName: string; onProgress?: (done: number, total: number) => void },
): Promise<CapturedFile> {
  if (paths.length === 0) throw new Error('لا توجد مستندات للتصدير')

  const baseName = opts.fileName.replace(/\.(pdf|png)$/i, '') || 'documents'
  const pdf = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' })
  let pageAdded = false

  for (let i = 0; i < paths.length; i++) {
    opts.onProgress?.(i, paths.length)
    // Capture as PNG then slice into A4 pages so multiple documents can be merged.
    const pngCapture = await captureFromPrintPopup(paths[i], {
      format: 'png',
      fileName: `${baseName}-${i + 1}`,
    })
    const pngUrl = URL.createObjectURL(pngCapture.blob)
    try {
      const img = await loadImage(pngUrl)
      const pageWidth = pdf.internal.pageSize.getWidth()
      const pageHeight = pdf.internal.pageSize.getHeight()
      const margin = 18
      const usableW = pageWidth - margin * 2
      const usableH = pageHeight - margin * 2
      const scaledW = usableW
      const scaledH = (img.height * scaledW) / img.width

      if (!pageAdded) {
        pageAdded = true
      } else {
        pdf.addPage()
      }

      if (scaledH <= usableH) {
        pdf.addImage(img, 'PNG', margin, margin, scaledW, scaledH)
      } else {
        // Draw onto temp canvas and slice
        const canvas = document.createElement('canvas')
        canvas.width = img.width
        canvas.height = img.height
        const ctx = canvas.getContext('2d')
        if (!ctx) throw new Error('PDF merge failed')
        ctx.drawImage(img, 0, 0)
        const pxPerPt = canvas.width / scaledW
        const pageSlicePx = usableH * pxPerPt
        let sourceY = 0
        let firstSlice = true
        while (sourceY < canvas.height - 1) {
          const sliceHeightPx = Math.min(pageSlicePx, canvas.height - sourceY)
          const sliceCanvas = document.createElement('canvas')
          sliceCanvas.width = canvas.width
          sliceCanvas.height = Math.max(1, Math.ceil(sliceHeightPx))
          const sctx = sliceCanvas.getContext('2d')
          if (!sctx) throw new Error('PDF merge failed')
          sctx.fillStyle = '#ffffff'
          sctx.fillRect(0, 0, sliceCanvas.width, sliceCanvas.height)
          sctx.drawImage(canvas, 0, sourceY, canvas.width, sliceHeightPx, 0, 0, canvas.width, sliceHeightPx)
          const sliceH = (sliceHeightPx * scaledW) / canvas.width
          if (!firstSlice) pdf.addPage()
          pdf.addImage(sliceCanvas.toDataURL('image/png'), 'PNG', margin, margin, scaledW, sliceH)
          firstSlice = false
          sourceY += sliceHeightPx
          if (sliceHeightPx < 2) break
        }
      }
    } finally {
      URL.revokeObjectURL(pngUrl)
    }
  }

  opts.onProgress?.(paths.length, paths.length)
  const blob = pdf.output('blob')
  const file: CapturedFile = {
    blob,
    fileName: `${baseName}.pdf`,
    mimeType: 'application/pdf',
    format: 'pdf',
  }
  downloadBlob(file.blob, file.fileName)
  return file
}

function loadImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('فشل تحميل صورة المستند'))
    img.src = url
  })
}
