import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import {
  DEFAULT_DOCUMENT_BRAND,
  DOCUMENT_BRANDS,
  getDocumentBrand,
  isDocumentBrandId,
  readStoredDocumentBrand,
  storeDocumentBrand,
  type DocumentBrandId,
  type DocumentBrandProfile,
} from '@/lib/brand'

type DocumentBrandContextValue = {
  brandId: DocumentBrandId
  brand: DocumentBrandProfile
  setBrandId: (id: DocumentBrandId) => void
  brands: DocumentBrandProfile[]
}

const DocumentBrandContext = createContext<DocumentBrandContextValue>({
  brandId: DEFAULT_DOCUMENT_BRAND,
  brand: DOCUMENT_BRANDS.syna,
  setBrandId: () => undefined,
  brands: Object.values(DOCUMENT_BRANDS),
})

function initialBrandId(initial?: DocumentBrandId): DocumentBrandId {
  if (initial && isDocumentBrandId(initial)) return initial
  if (typeof window !== 'undefined') {
    const fromUrl = new URLSearchParams(window.location.search).get('brand')
    if (isDocumentBrandId(fromUrl)) return fromUrl
    return readStoredDocumentBrand()
  }
  return DEFAULT_DOCUMENT_BRAND
}

export function DocumentBrandProvider({
  children,
  initialBrandId: initial,
}: {
  children: ReactNode
  initialBrandId?: DocumentBrandId
}) {
  const [brandId, setBrandIdState] = useState<DocumentBrandId>(() => initialBrandId(initial))

  const setBrandId = useCallback((id: DocumentBrandId) => {
    setBrandIdState(id)
    storeDocumentBrand(id)
  }, [])

  const value = useMemo(
    () => ({
      brandId,
      brand: getDocumentBrand(brandId),
      setBrandId,
      brands: Object.values(DOCUMENT_BRANDS),
    }),
    [brandId, setBrandId],
  )

  return <DocumentBrandContext.Provider value={value}>{children}</DocumentBrandContext.Provider>
}

export function useDocumentBrand(): DocumentBrandContextValue {
  return useContext(DocumentBrandContext)
}
