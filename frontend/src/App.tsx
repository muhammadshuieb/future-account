import { lazy, Suspense, useEffect } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { AuthProvider } from '@/context/AuthContext'
import { applyDefaultLocale } from '@/i18n'
import api from '@/lib/api'
import AppLayout from '@/components/AppLayout'
import LoginPage from '@/pages/LoginPage'

const DashboardPage = lazy(() => import('@/pages/DashboardPage'))
const AccountsPage = lazy(() => import('@/pages/AccountsPage'))
const JournalEntriesPage = lazy(() => import('@/pages/JournalEntriesPage'))
const SettingsPage = lazy(() => import('@/pages/SettingsPage'))
const WarehousePage = lazy(() => import('@/pages/WarehousePage'))
const SalesPage = lazy(() => import('@/pages/SalesPage'))
const PurchasesPage = lazy(() => import('@/pages/PurchasesPage'))
const PartnersPage = lazy(() => import('@/pages/PartnersPage'))
const CashBanksPage = lazy(() => import('@/pages/CashBanksPage'))
const HrPage = lazy(() => import('@/pages/HrPage'))
const ReportsPage = lazy(() => import('@/pages/ReportsPage'))
const BarcodeLabelsPage = lazy(() => import('@/pages/BarcodeLabelsPage'))
const AuditLogPage = lazy(() => import('@/pages/AuditLogPage'))
const CompaniesPage = lazy(() => import('@/pages/CompaniesPage'))
const SalesInvoicePrintPage = lazy(() => import('@/pages/SalesInvoicePrintPage'))
const PurchaseInvoicePrintPage = lazy(() => import('@/pages/PurchaseInvoicePrintPage'))

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false },
  },
})

function RouteFallback() {
  const { t } = useTranslation()
  return (
    <div className="flex min-h-[40vh] flex-col items-center justify-center gap-3 p-8">
      <div className="h-10 w-10 animate-pulse rounded-xl bg-teal/20" />
      <div className="skeleton h-3 w-36" />
      <div className="skeleton h-3 w-24" />
      <p className="text-sm text-teal">{t('common.loading')}</p>
    </div>
  )
}

function BootstrapLocale() {
  useEffect(() => {
    if (localStorage.getItem('fa_lang')) return
    void api
      .get('/public/bootstrap')
      .then((res) => {
        const locale = res.data?.data?.default_locale as string | undefined
        if (locale) applyDefaultLocale(locale)
      })
      .catch(() => {
        /* keep fallback ar */
      })
  }, [])

  return null
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <BootstrapLocale />
        <BrowserRouter>
          <Suspense fallback={<RouteFallback />}>
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
          </Suspense>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )
}
