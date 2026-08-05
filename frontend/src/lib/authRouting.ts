import type { User } from '@/types'

export function isWarehouseManager(user: User | null | undefined): boolean {
  return !!user?.roles.includes('warehouse_manager') && !user.roles.includes('admin')
}

export function landingPathForUser(user: User | null | undefined): string {
  return isWarehouseManager(user) ? '/warehouse-dashboard' : '/'
}
