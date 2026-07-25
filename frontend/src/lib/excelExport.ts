import api from '@/lib/api'
import { downloadBlob } from '@/lib/documentCapture'

/** Download an Excel export from the API (`responseType: 'blob'`). */
export async function downloadExcelExport(
  path: string,
  params?: Record<string, string | number | undefined | null>,
  fallbackFileName = 'syna-export.xlsx',
): Promise<void> {
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
  const fileName = match ? decodeURIComponent(match[1].replace(/"/g, '')) : fallbackFileName

  const blob = res.data instanceof Blob
    ? res.data
    : new Blob([res.data], {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      })

  downloadBlob(blob, fileName)
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
