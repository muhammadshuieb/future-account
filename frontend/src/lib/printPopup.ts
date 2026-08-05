/** Open a same-origin print document in a browser popup (shares localStorage auth token). */
export function openPrintPopup(path: string): Window | null {
  // ~A4 at 96dpi (210×297mm) plus chrome for toolbar — keeps preview proportions right
  const width = 860
  const height = Math.min(1240, Math.max(900, window.screen.availHeight - 40))
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

  const win = window.open(path, 'fa-invoice-print', features)
  if (!win) {
    // Popup blocked — fall back to a new tab
    return window.open(path, '_blank')
  }
  win.focus()
  return win
}
