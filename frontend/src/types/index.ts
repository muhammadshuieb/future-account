export type User = {
  id: number
  name: string
  username?: string
  first_name?: string | null
  last_name?: string | null
  mobile?: string | null
  email?: string | null
  roles: string[]
  permissions: string[]
  warehouse_ids?: number[]
  warehouses?: { id: number; name: string; code: string }[]
}

export type Account = {
  id: number
  code: string
  name: string
  name_en?: string | null
  parent_id?: number | null
  type: 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'
  nature: 'debit' | 'credit'
  level: number
  is_group: boolean
  is_active: boolean
  description?: string | null
  children?: Account[]
}

export type JournalDetail = {
  id?: number
  account_id: number
  debit: number | string
  credit: number | string
  memo?: string | null
  account?: Account
}

export type JournalEntry = {
  id: number
  entry_number: string
  entry_date: string
  description: string
  reference?: string | null
  status: 'draft' | 'posted' | 'void'
  details?: JournalDetail[]
  creator?: { id: number; name: string }
}

export type DashboardCurrencyStats = {
  currency: string
  revenue: number
  expense: number
  net_income: number
  receivables: number
  payables: number
  month_sales: number
  month_purchases: number
  cash?: number
  bank?: number
  liquidity?: number
}

export type DashboardSummary = {
  company_name: string
  accounts_count: number
  journal_entries_count: number
  posted_entries_count: number
  draft_entries_count: number
  revenue: number
  expense: number
  net_income: number
  currency: string
  base_currency?: string
  filter_branch_id?: number | null
  filter_currency?: string | null
  receivables?: number
  payables?: number
  month_sales?: number
  month_purchases?: number
  cash?: number
  bank?: number
  liquidity?: number
  liquidity_by_currency?: { currency: string; cash: number; bank: number; liquidity: number }[] | null
  cash_boxes?: {
    id: number
    code: string
    name: string
    currency: string
    branch_id?: number | null
    is_shared?: boolean
    balance: number
  }[]
  banks?: {
    id: number
    code: string
    name: string
    currency: string
    branch_id?: number | null
    is_shared?: boolean
    balance: number
  }[]
  base_totals?: DashboardCurrencyStats | null
  by_currency?: DashboardCurrencyStats[] | null
  daily_sales?: { date: string; total: number; count: number }[]
  daily_purchases?: { date: string; total: number; count: number }[]
  customers_count?: number
  suppliers_count?: number
  products_count?: number
  low_stock_count?: number
  alerts?: { type: string; code?: string; title: string; body: string; href?: string }[]
}

export type Setting = {
  id: number
  key: string
  value: string | null
  group: string
  type: string
  label: string | null
}
