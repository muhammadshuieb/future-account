declare module 'jsbarcode' {
  const JsBarcode: (
    element: string | HTMLElement | SVGSVGElement,
    data: string,
    options?: Record<string, unknown>,
  ) => void
  export default JsBarcode
}
