import html2canvas from 'html2canvas-pro'
import { jsPDF } from 'jspdf'

export type CaptureFormat = 'pdf' | 'png'

export type CapturedFile = {
  blob: Blob
  fileName: string
  mimeType: string
  format: CaptureFormat
}

/**
 * Modern CSS color functions that classic html2canvas cannot parse.
 * We use html2canvas-pro (oklab-aware) AND still sanitize as belt-and-suspenders
 * for CSS variables / shadows / gradients that some parsers still choke on.
 */
const UNSUPPORTED_COLOR_FN =
  /(?:oklab|oklch|lab|lch|color)\s*\(|color-mix\s*\(/i

const COLOR_STYLE_PROPS = [
  'color',
  'background-color',
  'border-top-color',
  'border-right-color',
  'border-bottom-color',
  'border-left-color',
  'outline-color',
  'text-decoration-color',
  'column-rule-color',
  'caret-color',
  'fill',
  'stroke',
  'stop-color',
  'flood-color',
  'lighting-color',
] as const

const COMPLEX_COLOR_PROPS = [
  'box-shadow',
  'text-shadow',
  'background-image',
  'border-image-source',
  'filter',
  'backdrop-filter',
  'outline',
  'border',
  'border-top',
  'border-right',
  'border-bottom',
  'border-left',
  'background',
] as const

/** Resolve any CSS color (including oklab/oklch) to a hex/rgb string via canvas. */
function cssColorToRgb(value: string): string {
  const trimmed = value.trim()
  if (!trimmed || trimmed === 'transparent' || trimmed === 'none' || trimmed === 'currentcolor') {
    return trimmed
  }
  // Already a safe legacy form.
  if (/^(#|rgb\b|rgba\b|hsl\b|hsla\b|gray\b|transparent|none)/i.test(trimmed) && !UNSUPPORTED_COLOR_FN.test(trimmed)) {
    return trimmed
  }
  try {
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = 1
    const ctx = canvas.getContext('2d')
    if (!ctx) return '#000000'
    ctx.fillStyle = '#000000'
    ctx.fillStyle = trimmed
    const resolved = String(ctx.fillStyle)
    // If the browser rejected the color, fillStyle stays black — still safer than oklab.
    return resolved
  } catch {
    return '#000000'
  }
}

function replaceUnsupportedColorsInValue(value: string): string {
  if (!UNSUPPORTED_COLOR_FN.test(value)) return value

  const fnNames = /(?:oklab|oklch|lab|lch|color-mix|color)\s*\(/gi
  let result = ''
  let last = 0
  let match: RegExpExecArray | null
  const re = new RegExp(fnNames.source, 'gi')
  while ((match = re.exec(value)) !== null) {
    const start = match.index
    let i = start + match[0].length
    let depth = 1
    while (i < value.length && depth > 0) {
      const ch = value[i]
      if (ch === '(') depth += 1
      else if (ch === ')') depth -= 1
      i += 1
    }
    const full = value.slice(start, i)
    result += value.slice(last, start)
    try {
      result += cssColorToRgb(full)
    } catch {
      result += '#000000'
    }
    last = i
    re.lastIndex = i
  }
  result += value.slice(last)
  return result
}

function sanitizeStyleDeclaration(style: CSSStyleDeclaration) {
  for (const prop of Array.from(style)) {
    const val = style.getPropertyValue(prop)
    if (!val || !UNSUPPORTED_COLOR_FN.test(val)) continue
    if (COLOR_STYLE_PROPS.includes(prop as (typeof COLOR_STYLE_PROPS)[number])) {
      style.setProperty(prop, cssColorToRgb(val), style.getPropertyPriority(prop) || undefined)
      continue
    }
    const fixed = replaceUnsupportedColorsInValue(val)
    if (UNSUPPORTED_COLOR_FN.test(fixed)) {
      style.removeProperty(prop)
    } else {
      style.setProperty(prop, fixed, style.getPropertyPriority(prop) || undefined)
    }
  }
}

function sanitizeCssCustomProperties(el: HTMLElement, computed: CSSStyleDeclaration) {
  // Tailwind v4 puts oklch into CSS variables; force resolved rgb on the clone.
  for (let i = 0; i < computed.length; i++) {
    const name = computed[i]
    if (!name.startsWith('--')) continue
    const val = computed.getPropertyValue(name)
    if (!val || !UNSUPPORTED_COLOR_FN.test(val)) continue
    const fixed = replaceUnsupportedColorsInValue(val)
    el.style.setProperty(name, UNSUPPORTED_COLOR_FN.test(fixed) ? '#000000' : fixed, 'important')
  }
}

/**
 * Force resolved rgb/hex inline styles on the cloned document before paint.
 * Covers color props, shadows/gradients, CSS variables, and stylesheet rules.
 */
function sanitizeCloneForHtml2Canvas(clonedDoc: Document) {
  const win = clonedDoc.defaultView
  if (!win) return

  const root = clonedDoc.documentElement
  const nodes: Element[] = [root, ...Array.from(clonedDoc.querySelectorAll('*'))]

  for (const node of nodes) {
    const el = node as HTMLElement
    if (!el.style) continue

    let computed: CSSStyleDeclaration
    try {
      computed = win.getComputedStyle(el)
    } catch {
      continue
    }

    sanitizeCssCustomProperties(el, computed)

    for (const prop of COLOR_STYLE_PROPS) {
      const val = computed.getPropertyValue(prop)
      if (!val) continue
      // Always rewrite when modern color fns appear; also rewrite non-rgb forms
      // that some older parsers mishandle (e.g. color(srgb ...)).
      if (UNSUPPORTED_COLOR_FN.test(val) || /^color\s*\(/i.test(val.trim())) {
        el.style.setProperty(prop, cssColorToRgb(val), 'important')
      }
    }

    for (const prop of COMPLEX_COLOR_PROPS) {
      const val = computed.getPropertyValue(prop)
      if (!val || val === 'none' || !UNSUPPORTED_COLOR_FN.test(val)) continue
      const fixed = replaceUnsupportedColorsInValue(val)
      el.style.setProperty(
        prop,
        UNSUPPORTED_COLOR_FN.test(fixed) ? 'none' : fixed,
        'important',
      )
    }

    // Inline style attribute may still contain raw oklab from source markup.
    if (el.getAttribute('style') && UNSUPPORTED_COLOR_FN.test(el.getAttribute('style') || '')) {
      sanitizeStyleDeclaration(el.style)
    }
  }

  // Neutralize stylesheet rules / style tags that still embed modern color functions.
  try {
    for (const sheet of Array.from(clonedDoc.styleSheets)) {
      let rules: CSSRuleList
      try {
        rules = sheet.cssRules
      } catch {
        continue
      }
      for (let i = rules.length - 1; i >= 0; i--) {
        const rule = rules[i]
        if (rule instanceof CSSStyleRule) {
          if (UNSUPPORTED_COLOR_FN.test(rule.cssText)) sanitizeStyleDeclaration(rule.style)
        } else if (typeof CSSSupportsRule !== 'undefined' && rule instanceof CSSSupportsRule) {
          // Drop @supports blocks that gate on modern color functions — they confuse parsers.
          if (/oklab|oklch|color-mix|lab\(|lch\(/i.test(rule.conditionText || '')) {
            try {
              sheet.deleteRule(i)
            } catch {
              /* ignore */
            }
          }
        }
      }
    }
  } catch {
    // Cross-origin or browser quirks — inline overrides above are the primary fix.
  }

  // Rewrite <style> text that still contains unsupported color functions.
  for (const styleEl of Array.from(clonedDoc.querySelectorAll('style'))) {
    const text = styleEl.textContent || ''
    if (!UNSUPPORTED_COLOR_FN.test(text)) continue
    styleEl.textContent = replaceUnsupportedColorsInValue(text)
  }
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

function friendlyCaptureError(err: unknown): Error {
  const msg = err instanceof Error ? err.message : String(err)
  if (/unsupported color function|oklab|oklch|color-mix/i.test(msg)) {
    return new Error(
      'فشل تصدير المستند بسبب ألوان CSS حديثة — حدّث الصفحة (Ctrl+F5) ثم أعد المحاولة',
    )
  }
  return err instanceof Error ? err : new Error(msg || 'فشل التقاط المستند')
}

export async function captureElement(
  element: HTMLElement,
  opts: { format: CaptureFormat; fileName: string },
): Promise<CapturedFile> {
  let canvas: HTMLCanvasElement
  try {
    canvas = await html2canvas(element, {
      scale: 2,
      useCORS: true,
      allowTaint: true,
      backgroundColor: '#ffffff',
      logging: false,
      // Prefer standard canvas path; foreignObject can reintroduce oklab via SVG.
      foreignObjectRendering: false,
      imageTimeout: 15_000,
      onclone: (_clonedDoc, clonedElement) => {
        const doc = clonedElement.ownerDocument || _clonedDoc
        sanitizeCloneForHtml2Canvas(doc)
      },
      ignoreElements: (el) => {
        if (!(el instanceof HTMLElement)) return false
        if (el.classList.contains('print-hide')) return true
        const tag = el.tagName
        return tag === 'SCRIPT' || tag === 'NOSCRIPT' || tag === 'IFRAME'
      },
    })
  } catch (err) {
    throw friendlyCaptureError(err)
  }

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
