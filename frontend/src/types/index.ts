export type User = {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
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
  receivables?: number
  payables?: number
  month_sales?: number
  month_purchases?: number
  customers_count?: number
  suppliers_count?: number
  products_count?: number
  low_stock_count?: number
  alerts?: { type: string; title: string; body: string }[]
}

export type Setting = {
  id: number
  key: string
  value: string | null
  group: string
  type: string
  label: string | null
}
