import { createContext, useContext } from 'react'
import CompanyStamp from '@/components/CompanyStamp'

type StampPrintContextValue = {
  enabled: boolean
  onLoad?: () => void
}

export const StampPrintContext = createContext<StampPrintContextValue>({ enabled: false })

/** Renders the company stamp when print-with-stamp is active — place under totals. */
export default function PrintStamp({ className = '' }: { className?: string }) {
  const { enabled, onLoad } = useContext(StampPrintContext)
  if (!enabled) return null
  return <CompanyStamp className={className} onLoad={onLoad} />
}
