import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from '@/context/AuthContext'
import AppLayout from '@/components/AppLayout'
import LoginPage from '@/pages/LoginPage'
import DashboardPage from '@/pages/DashboardPage'
import AccountsPage from '@/pages/AccountsPage'
import JournalEntriesPage from '@/pages/JournalEntriesPage'
import SettingsPage from '@/pages/SettingsPage'
import WarehousePage from '@/pages/WarehousePage'
import SalesPage from '@/pages/SalesPage'
import PurchasesPage from '@/pages/PurchasesPage'
import PartnersPage from '@/pages/PartnersPage'
import CashBanksPage from '@/pages/CashBanksPage'
import HrPage from '@/pages/HrPage'
import ReportsPage from '@/pages/ReportsPage'
import BarcodeLabelsPage from '@/pages/BarcodeLabelsPage'
import AuditLogPage from '@/pages/AuditLogPage'
import CompaniesPage from '@/pages/CompaniesPage'
import SalesInvoicePrintPage from '@/pages/SalesInvoicePrintPage'
import PurchaseInvoicePrintPage from '@/pages/PurchaseInvoicePrintPage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false },
  },
})

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/print/sales-invoices/:id" element={<SalesInvoicePrintPage />} />
            <Route path="/print/purchase-invoices/:id" element={<PurchaseInvoicePrintPage />} />
            <Route element={<AppLayout />}>
              <Route index element={<DashboardPage />} />
              <Route path="accounts" element={<AccountsPage />} />
              <Route path="journal-entries" element={<JournalEntriesPage />} />
              <Route path="settings" element={<SettingsPage />} />
              <Route path="sales" element={<SalesPage />} />
              <Route path="purchases" element={<PurchasesPage />} />
              <Route path="warehouse" element={<WarehousePage />} />
              <Route path="partners" element={<PartnersPage />} />
              <Route path="cash-banks" element={<CashBanksPage />} />
              <Route path="hr" element={<HrPage />} />
              <Route path="reports" element={<ReportsPage />} />
              <Route path="barcodes" element={<BarcodeLabelsPage />} />
              <Route path="audit-log" element={<AuditLogPage />} />
              <Route path="companies" element={<CompaniesPage />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )
}
