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

/**
 * html2canvas paints Arabic letter-by-letter when letter-spacing ≠ normal
 * (e.g. Tailwind tracking-*), which disconnects glyphs and can reverse runs.
 * Force shaping-safe text CSS on the clone before paint.
 */
function resolveCaptureDirection(source: HTMLElement): 'rtl' | 'ltr' {
  const attrDir = source.closest('[dir]')?.getAttribute('dir')
  if (attrDir === 'rtl' || attrDir === 'ltr') return attrDir
  const docDir = source.ownerDocument?.documentElement.getAttribute('dir')
  if (docDir === 'rtl' || docDir === 'ltr') return docDir
  try {
    const computed = getComputedStyle(source).direction
    if (computed === 'ltr' || computed === 'rtl') return computed
  } catch {
    /* ignore */
  }
  return 'rtl'
}

function applyArabicSafeCaptureStyles(doc: Document, clonedElement: HTMLElement, direction: 'rtl' | 'ltr') {
  const html = doc.documentElement
  const body = doc.body
  if (html) {
    html.setAttribute('dir', direction)
    html.setAttribute('lang', direction === 'rtl' ? 'ar' : html.getAttribute('lang') || 'en')
    html.style.setProperty('direction', direction)
  }
  if (body) {
    body.setAttribute('dir', direction)
    body.style.setProperty('direction', direction)
    body.style.setProperty(
      'font-family',
      '"Cairo", "IBM Plex Sans Arabic", "Segoe UI", Tahoma, Arial, sans-serif',
    )
  }

  clonedElement.setAttribute('dir', direction)
  clonedElement.style.setProperty('direction', direction, 'important')
  clonedElement.style.setProperty('unicode-bidi', 'isolate', 'important')
  clonedElement.style.setProperty(
    'font-family',
    '"Cairo", "IBM Plex Sans Arabic", "Segoe UI", Tahoma, Arial, sans-serif',
    'important',
  )

  if (!doc.getElementById('pdf-capture-arabic-style')) {
    const style = doc.createElement('style')
    style.id = 'pdf-capture-arabic-style'
    style.textContent = `
      .pdf-capture-root {
        direction: ${direction} !important;
        unicode-bidi: isolate !important;
        font-family: "Cairo", "IBM Plex Sans Arabic", "Segoe UI", Tahoma, Arial, sans-serif !important;
      }
      /* Per-glyph painting breaks Arabic joining — keep whole-run shaping. */
      .pdf-capture-root,
      .pdf-capture-root * {
        letter-spacing: normal !important;
        word-spacing: normal !important;
        word-break: normal !important;
        overflow-wrap: break-word !important;
        word-wrap: break-word !important;
        font-kerning: normal !important;
        font-variant-ligatures: common-ligatures contextual !important;
        font-feature-settings: "liga" 1, "calt" 1, "kern" 1 !important;
        text-rendering: optimizeLegibility !important;
      }
      .pdf-capture-root [dir="ltr"],
      .pdf-capture-root .font-mono {
        unicode-bidi: isolate !important;
      }
    `
    doc.head.appendChild(style)
  }
}

async function waitForDocumentFonts(doc: Document) {
  try {
    const fonts = (doc as Document & { fonts?: FontFaceSet }).fonts
    if (fonts?.ready) {
      await Promise.race([fonts.ready, sleep(3_000)])
    }
  } catch {
    /* FontFaceSet unavailable — brief settle is enough. */
  }
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

/**
 * ISO A4 portrait — same geometry as browser @page / print CSS.
 * 210mm ≈ 793.7px, 297mm ≈ 1122.5px at 96dpi.
 */
export const A4_MM = { width: 210, height: 297 } as const
export const A4_PX_96 = { width: 794, height: 1123 } as const

/** Mirrors `@page { margin: 10mm 12mm 12mm 12mm }` so PDF matches Print. */
export const A4_PAGE_MARGIN_MM = { top: 10, right: 12, bottom: 12, left: 12 } as const

const A4_CONTENT_MM = {
  width: A4_MM.width - A4_PAGE_MARGIN_MM.left - A4_PAGE_MARGIN_MM.right,
  height: A4_MM.height - A4_PAGE_MARGIN_MM.top - A4_PAGE_MARGIN_MM.bottom,
} as const

/** Hairline overflow stays on one page; anything taller paginates. */
const SINGLE_PAGE_FIT_MAX = 1.06

function newA4Pdf(): jsPDF {
  return new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' })
}

function canvasToA4Pdf(canvas: HTMLCanvasElement): Blob {
  const pdf = newA4Pdf()
  imageToA4PdfPages(pdf, canvas, false)
  return pdf.output('blob')
}

function imageToA4PdfPages(
  pdf: jsPDF,
  img: HTMLImageElement | HTMLCanvasElement,
  startNewPage: boolean,
): void {
  const contentW = A4_CONTENT_MM.width
  const contentH = A4_CONTENT_MM.height
  const marginX = A4_PAGE_MARGIN_MM.left
  const marginY = A4_PAGE_MARGIN_MM.top
  const srcW = img.width
  const srcH = img.height
  const pageSlicePx = srcW * (contentH / contentW)

  if (startNewPage) pdf.addPage()

  if (srcH <= pageSlicePx * SINGLE_PAGE_FIT_MAX) {
    const drawH = Math.min(contentH, (srcH / srcW) * contentW)
    pdf.addImage(img, 'PNG', marginX, marginY, contentW, drawH)
    return
  }

  const source = document.createElement('canvas')
  source.width = srcW
  source.height = srcH
  const srcCtx = source.getContext('2d')
  if (!srcCtx) throw new Error('PDF capture failed')
  srcCtx.drawImage(img, 0, 0)

  let sourceY = 0
  let firstSlice = true
  while (sourceY < srcH - 1) {
    const sliceHeightPx = Math.min(pageSlicePx, srcH - sourceY)
    const sliceCanvas = document.createElement('canvas')
    sliceCanvas.width = srcW
    sliceCanvas.height = Math.max(1, Math.ceil(sliceHeightPx))
    const ctx = sliceCanvas.getContext('2d')
    if (!ctx) throw new Error('PDF capture failed')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, sliceCanvas.width, sliceCanvas.height)
    ctx.drawImage(source, 0, sourceY, srcW, sliceHeightPx, 0, 0, srcW, sliceHeightPx)
    const sliceH = (sliceHeightPx / srcW) * contentW
    if (!firstSlice) pdf.addPage()
    pdf.addImage(sliceCanvas.toDataURL('image/png'), 'PNG', marginX, marginY, contentW, sliceH)
    firstSlice = false
    sourceY += sliceHeightPx
    if (sliceHeightPx < 2) break
  }
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
  const captureDirection = resolveCaptureDirection(element)
  await waitForDocumentFonts(element.ownerDocument || document)

  /** Content width inside @page margins (186mm) — matches browser print body width. */
  const contentWidthPx = Math.round((A4_CONTENT_MM.width / 25.4) * 96)

  let canvas: HTMLCanvasElement
  try {
    canvas = await html2canvas(element, {
      scale: 2,
      useCORS: true,
      allowTaint: true,
      backgroundColor: '#ffffff',
      logging: false,
      windowWidth: contentWidthPx,
      width: contentWidthPx,
      foreignObjectRendering: false,
      imageTimeout: 15_000,
      onclone: (_clonedDoc, clonedElement) => {
        const doc = clonedElement.ownerDocument || _clonedDoc
        sanitizeCloneForHtml2Canvas(doc)
        clonedElement.classList.add('pdf-capture-root')
        // Capture content only — page margins are applied by jsPDF like @page.
        clonedElement.style.setProperty('overflow', 'visible', 'important')
        clonedElement.style.setProperty('width', `${A4_CONTENT_MM.width}mm`, 'important')
        clonedElement.style.setProperty('max-width', `${A4_CONTENT_MM.width}mm`, 'important')
        clonedElement.style.setProperty('box-sizing', 'border-box', 'important')
        clonedElement.style.setProperty('background', '#ffffff', 'important')
        clonedElement.style.setProperty('padding', '0', 'important')
        clonedElement.style.setProperty('margin', '0', 'important')
        clonedElement.style.setProperty('box-shadow', 'none', 'important')
        clonedElement.style.setProperty('border-radius', '0', 'important')
        applyArabicSafeCaptureStyles(doc, clonedElement, captureDirection)
        if (!doc.getElementById('pdf-capture-compact-style')) {
          const style = doc.createElement('style')
          style.id = 'pdf-capture-compact-style'
          // Mirror @media print rules so PDF matches browser Print dialog.
          style.textContent = `
            .pdf-capture-root {
              width: ${A4_CONTENT_MM.width}mm !important;
              max-width: ${A4_CONTENT_MM.width}mm !important;
              box-sizing: border-box !important;
              margin: 0 !important;
              padding: 0 !important;
              font-size: 10.5pt !important;
              line-height: 1.35 !important;
              overflow: visible !important;
              background: #ffffff !important;
              color: #000 !important;
              box-shadow: none !important;
            }
            .pdf-capture-root table { width: 100% !important; max-width: 100% !important; table-layout: auto !important; }
            .pdf-capture-root th, .pdf-capture-root td { word-break: break-word !important; overflow-wrap: anywhere !important; }
            .pdf-capture-root .space-y-4 > :not([hidden]) ~ :not([hidden]) { margin-top: 0.45rem !important; }
            .pdf-capture-root .space-y-2 > :not([hidden]) ~ :not([hidden]) { margin-top: 0.35rem !important; }
            .pdf-capture-root .print-brand-header,
            .pdf-capture-root > header {
              display: flex !important;
              flex-wrap: nowrap !important;
              align-items: flex-start !important;
              justify-content: space-between !important;
              padding-bottom: 0.35rem !important;
              gap: 0.75rem !important;
            }
            .pdf-capture-root .print-brand-header > :first-child { flex: 1 1 auto !important; min-width: 0 !important; }
            .pdf-capture-root img.brand-logo--print,
            .pdf-capture-root img.print-logo {
              max-height: 56px !important;
              max-width: 45mm !important;
              width: auto !important;
              height: auto !important;
              object-fit: contain !important;
              flex-shrink: 0 !important;
            }
            .pdf-capture-root .data-table th,
            .pdf-capture-root .data-table td { padding: 0.28rem 0.4rem !important; font-size: 10.5px !important; }
            .pdf-capture-root canvas.print-qr,
            .pdf-capture-root canvas { max-width: 72px !important; max-height: 72px !important; width: 72px !important; height: 72px !important; }
            .pdf-capture-root svg.print-barcode,
            .pdf-capture-root .barcode-label svg { max-height: 52px !important; max-width: 100% !important; width: auto !important; height: 48px !important; }
            .pdf-capture-root .p-4 { padding: 0.4rem !important; }
            .pdf-capture-root .p-3 { padding: 0.35rem !important; }
            .pdf-capture-root .text-lg { font-size: 11.5pt !important; }
            .pdf-capture-root .text-base { font-size: 10pt !important; }
          `
          doc.head.appendChild(style)
        }
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

  const blob = canvasToA4Pdf(canvas)
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

function resolvePrintCaptureElement(doc: Document, selector: string): HTMLElement | null {
  // Always capture .print-area (content). Page margins match browser @page via jsPDF.
  return doc.querySelector<HTMLElement>(selector)
}

export async function captureSelectorInDocument(
  doc: Document,
  selector: string,
  opts: { format: CaptureFormat; fileName: string },
): Promise<CapturedFile> {
  const el = resolvePrintCaptureElement(doc, selector)
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
    width: `${A4_PX_96.width}px`,
    height: `${A4_PX_96.height}px`,
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
        await waitForDocumentFonts(doc)
        const target = resolvePrintCaptureElement(doc, selector)
        const contentH = Math.max(
          A4_PX_96.height,
          Math.ceil((target?.scrollHeight || A4_PX_96.height) + 8),
        )
        iframe.style.height = `${contentH}px`
        await sleep(350)
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
  const pdf = newA4Pdf()
  let pageAdded = false

  for (let i = 0; i < paths.length; i++) {
    opts.onProgress?.(i, paths.length)
    const pngCapture = await captureFromPrintPopup(paths[i], {
      format: 'png',
      fileName: `${baseName}-${i + 1}`,
    })
    const pngUrl = URL.createObjectURL(pngCapture.blob)
    try {
      const img = await loadImage(pngUrl)
      imageToA4PdfPages(pdf, img, pageAdded)
      pageAdded = true
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
