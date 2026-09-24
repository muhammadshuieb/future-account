import api from '@/lib/api'
import { downloadBlob } from '@/lib/documentCapture'
import { safeDownloadFileName } from '@/lib/documentFileName'

const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

export type ExcelFile = {
  blob: Blob
  fileName: string
  mimeType: string
}

function withXlsxExt(name: string): string {
  return /\.xlsx$/i.test(name) ? name : `${name}.xlsx`
}

/** Fetch an Excel export blob from the API without triggering a download. */
export async function fetchExcelExport(
  path: string,
  params?: Record<string, string | number | undefined | null>,
  /** When set, used as the saved filename (preferred over Content-Disposition). */
  preferredFileName?: string,
): Promise<ExcelFile> {
  const cleanParams: Record<string, string> = {}
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null && String(v) !== '') {
        cleanParams[k] = String(v)
      }
    }
  }

  const res = await api.get(path, {
    params: cleanParams,
    responseType: 'blob',
  })

  const disposition = String(res.headers['content-disposition'] || '')
  const match = /filename\*?=(?:UTF-8''|")?([^\";]+)/i.exec(disposition)
  const fromHeader = match ? decodeURIComponent(match[1].replace(/"/g, '')) : null

  const fileName = preferredFileName
    ? safeDownloadFileName(withXlsxExt(preferredFileName), 'xlsx')
    : safeDownloadFileName(fromHeader || 'syna-export.xlsx', 'xlsx')

  const blob = res.data instanceof Blob
    ? res.data
    : new Blob([res.data], { type: XLSX_MIME })

  return {
    blob: blob.type ? blob : new Blob([blob], { type: XLSX_MIME }),
    fileName,
    mimeType: blob.type || XLSX_MIME,
  }
}

/** Download an Excel export from the API (`responseType: 'blob'`). */
export async function downloadExcelExport(
  path: string,
  params?: Record<string, string | number | undefined | null>,
  preferredFileName?: string,
): Promise<void> {
  const file = await fetchExcelExport(path, params, preferredFileName)
  downloadBlob(file.blob, file.fileName)
}

export function excelModuleForSalesTab(tab: string): string {
  const map: Record<string, string> = {
    quotes: 'sales-quotes',
    orders: 'sales-orders',
    invoices: 'sales-invoices',
    returns: 'sales-returns',
    receipts: 'receipts',
  }
  return map[tab] || 'sales-invoices'
}

export function excelModuleForPurchasesTab(tab: string): string {
  const map: Record<string, string> = {
    requests: 'purchase-requests',
    orders: 'purchase-orders',
    invoices: 'purchase-invoices',
    returns: 'purchase-returns',
    payments: 'supplier-payments',
  }
  return map[tab] || 'purchase-invoices'
}

export function excelModuleForWarehouseTab(tab: string): string {
  const map: Record<string, string> = {
    warehouses: 'warehouses',
    products: 'products',
    categories: 'categories',
    units: 'units',
    stock: 'stock-levels',
    movements: 'stock-movements',
    transfers: 'warehouse-transfers',
  }
  return map[tab] || 'products'
}

export function excelModuleForCashTab(tab: string): string {
  const map: Record<string, string> = {
    boxes: 'cash-boxes',
    banks: 'banks',
    transfers: 'cash-transfers',
    exchange: 'currency-exchanges',
  }
  return map[tab] || 'cash-boxes'
}

export function excelModuleForHrTab(tab: string): string {
  const map: Record<string, string> = {
    employees: 'employees',
    attendance: 'attendances',
    leaves: 'leave-requests',
    salaries: 'salary-records',
  }
  return map[tab] || 'employees'
}

export function excelModuleForPartnersTab(tab: string): string {
  return tab === 'suppliers' ? 'suppliers' : 'customers'
}
