import type { VoucherPrintData } from '@/components/VoucherPrintView'

export const VOUCHER_PRINT_STORAGE_KEY = 'fa-voucher-print'

export function storeVoucherForPrint(voucher: VoucherPrintData): void {
  sessionStorage.setItem(VOUCHER_PRINT_STORAGE_KEY, JSON.stringify(voucher))
}

export function readVoucherForPrint(): VoucherPrintData | null {
  try {
    const raw = sessionStorage.getItem(VOUCHER_PRINT_STORAGE_KEY)
    if (!raw) return null
    const data = JSON.parse(raw) as VoucherPrintData
    if (!data || (data.kind !== 'receipt' && data.kind !== 'payment')) return null
    return data
  } catch {
    return null
  }
}
