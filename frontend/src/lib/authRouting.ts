import type { User } from '@/types'

export function isWarehouseManager(user: User | null | undefined): boolean {
  return !!user?.roles.includes('warehouse_manager') && !user.roles.includes('admin')
}

/** Price-list-only users (view role) — no main dashboard. */
export function isPriceViewerOnly(user: User | null | undefined): boolean {
  if (!user || user.roles.includes('admin')) return false
  if (user.roles.includes('price_viewer')) return true
  const perms = user.permissions ?? []
  const canSeePrices = perms.includes('price_lists.view') || perms.includes('price_lists.manage')
  const canSeeDashboard = perms.includes('dashboard.view')
  return canSeePrices && !canSeeDashboard
}

export function landingPathForUser(user: User | null | undefined): string {
  if (isWarehouseManager(user)) return '/warehouse-dashboard'
  if (isPriceViewerOnly(user)) return '/price-lists'
  return '/'
}
