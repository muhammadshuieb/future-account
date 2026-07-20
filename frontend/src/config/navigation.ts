import type { LucideIcon } from 'lucide-react'
import {
  LayoutDashboard,
  BookOpen,
  FileText,
  Settings,
  ShoppingCart,
  Truck,
  Warehouse,
  Users,
  Landmark,
  UserCog,
  BarChart3,
  Barcode,
  ScrollText,
  Building2,
} from 'lucide-react'

export type NavItem = {
  to: string
  key: string
  icon: LucideIcon
  end?: boolean
  permission?: string
}

export type NavGroup = {
  labelKey: string
  items: NavItem[]
}

export const navGroups: NavGroup[] = [
  {
    labelKey: 'nav.group.main',
    items: [
      { to: '/', key: 'dashboard', icon: LayoutDashboard, end: true, permission: 'dashboard.view' },
    ],
  },
  {
    labelKey: 'nav.group.accounting',
    items: [
      { to: '/accounts', key: 'accounts', icon: BookOpen, permission: 'accounts.view' },
      { to: '/journal-entries', key: 'journal', icon: FileText, permission: 'journals.view' },
      { to: '/reports', key: 'reports', icon: BarChart3, permission: 'reports.view' },
    ],
  },
  {
    labelKey: 'nav.group.operations',
    items: [
      { to: '/sales', key: 'sales', icon: ShoppingCart, permission: 'sales.view' },
      { to: '/purchases', key: 'purchases', icon: Truck, permission: 'purchases.view' },
      { to: '/warehouse', key: 'warehouse', icon: Warehouse, permission: 'warehouse.view' },
      { to: '/partners', key: 'partners', icon: Users, permission: 'customers.view' },
      { to: '/cash-banks', key: 'cash', icon: Landmark, permission: 'cash.view' },
      { to: '/hr', key: 'hr', icon: UserCog, permission: 'hr.view' },
      { to: '/barcodes', key: 'barcodes', icon: Barcode, permission: 'warehouse.view' },
    ],
  },
  {
    labelKey: 'nav.group.admin',
    items: [
      { to: '/settings', key: 'settings', icon: Settings, permission: 'settings.manage' },
      { to: '/audit-log', key: 'auditLog', icon: ScrollText, permission: 'settings.manage' },
      { to: '/companies', key: 'companies', icon: Building2, permission: 'settings.manage' },
    ],
  },
]
