import { NavLink, Outlet, Navigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
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
  LogOut,
  Shield,
  Bell,
  Barcode,
  Menu,
  X,
} from 'lucide-react'
import { useState } from 'react'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { APP_VERSION } from '@/version'

const navItems = [
  { to: '/', label: 'لوحة التحكم', icon: LayoutDashboard, end: true },
  { to: '/accounts', label: 'دليل الحسابات', icon: BookOpen },
  { to: '/journal-entries', label: 'القيود اليومية', icon: FileText },
  { to: '/sales', label: 'المبيعات', icon: ShoppingCart },
  { to: '/purchases', label: 'المشتريات', icon: Truck },
  { to: '/warehouse', label: 'المخازن', icon: Warehouse },
  { to: '/partners', label: 'العملاء والموردون', icon: Users },
  { to: '/cash-banks', label: 'الصناديق والبنوك', icon: Landmark },
  { to: '/hr', label: 'الموارد البشرية', icon: UserCog },
  { to: '/reports', label: 'التقارير', icon: BarChart3 },
  { to: '/barcodes', label: 'الباركود والملصقات', icon: Barcode },
  { to: '/settings', label: 'الإعدادات', icon: Settings },
]

export default function AppLayout() {
  const { user, loading, logout } = useAuth()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [notifOpen, setNotifOpen] = useState(false)
  const qc = useQueryClient()

  const notifications = useQuery({
    queryKey: ['notifications'],
    queryFn: async () => (await api.get('/notifications')).data.data as {
      items: { id: number; title: string; body?: string; read_at?: string | null; created_at: string }[]
      unread_count: number
    },
    enabled: !!user,
    refetchInterval: 60_000,
  })

  const markAll = useMutation({
    mutationFn: () => api.post('/notifications/read-all'),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['notifications'] }),
  })

  if (loading) {
    return (
      <div className="grid min-h-screen place-items-center">
        <div className="text-center">
          <div className="mx-auto mb-3 h-10 w-10 animate-pulse rounded-xl bg-teal/20" />
          <p className="text-teal">جاري التحميل...</p>
        </div>
      </div>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  const nav = (
    <>
      <div className="border-b border-white/10 px-5 py-6">
        <div className="flex items-center gap-3">
          <div className="grid h-11 w-11 place-items-center rounded-xl bg-teal text-lg font-extrabold shadow-inner">
            ف
          </div>
          <div>
            <p className="text-lg font-bold leading-tight">فيوتشر أكونت</p>
            <p className="text-xs text-white/55">نظام محاسبة متكامل</p>
            <p className="text-[10px] text-white/40">v{APP_VERSION}</p>
          </div>
        </div>
      </div>

      <nav className="flex-1 space-y-0.5 overflow-y-auto p-3">
        {navItems.map((item) => {
          const Icon = item.icon
          return (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              onClick={() => setMobileOpen(false)}
              className={({ isActive }) =>
                [
                  'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition',
                  isActive
                    ? 'bg-teal text-white shadow-sm'
                    : 'text-white/70 hover:bg-white/8 hover:text-white',
                ].join(' ')
              }
            >
              <Icon size={18} strokeWidth={1.75} />
              <span className="flex-1">{item.label}</span>
            </NavLink>
          )
        })}
      </nav>

      <div className="border-t border-white/10 p-4">
        <div className="mb-3 flex items-center gap-2 text-sm text-white/80">
          <Shield size={16} className="text-teal" />
          <div className="min-w-0">
            <p className="truncate font-medium text-white">{user.name}</p>
            <p className="truncate text-xs text-white/45">{user.roles.join(' · ')}</p>
          </div>
        </div>
        <button
          type="button"
          onClick={() => void logout()}
          className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/65 transition hover:bg-white/10 hover:text-white"
        >
          <LogOut size={16} />
          تسجيل الخروج
        </button>
      </div>
    </>
  )

  return (
    <div className="flex min-h-screen">
      <aside className="print-hide hidden w-64 shrink-0 flex-col bg-slate-panel text-white lg:flex">
        {nav}
      </aside>

      {mobileOpen && (
        <div className="print-hide fixed inset-0 z-40 lg:hidden">
          <button type="button" className="absolute inset-0 bg-black/40" aria-label="إغلاق" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 right-0 flex w-72 flex-col bg-slate-panel text-white shadow-2xl">
            <button type="button" className="absolute left-3 top-3 rounded-lg p-2 text-white/70 hover:bg-white/10" onClick={() => setMobileOpen(false)}>
              <X size={18} />
            </button>
            {nav}
          </aside>
        </div>
      )}

      <div className="app-shell-main flex min-w-0 flex-1 flex-col">
        <header className="app-topbar print-hide sticky top-0 z-20 border-b border-[var(--color-line)] bg-white/85 backdrop-blur-md">
          <div className="flex items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <div className="flex items-center gap-3">
              <button type="button" className="rounded-lg p-2 hover:bg-mist lg:hidden" onClick={() => setMobileOpen(true)}>
                <Menu size={20} />
              </button>
              <div>
                <p className="text-sm font-semibold text-ink">مرحباً، {user.name}</p>
                <p className="text-xs text-black/45">العملة الأساسية تظهر في التقارير والإعدادات</p>
              </div>
            </div>

            <div className="relative">
              <button
                type="button"
                className="relative rounded-lg border border-[var(--color-line)] bg-white p-2.5 text-ink/70 hover:bg-mist"
                onClick={() => setNotifOpen((v) => !v)}
                aria-label="الإشعارات"
              >
                <Bell size={18} />
                {(notifications.data?.unread_count || 0) > 0 && (
                  <span className="absolute -left-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full bg-teal px-1 text-[10px] font-bold text-white">
                    {notifications.data?.unread_count}
                  </span>
                )}
              </button>
              {notifOpen && (
                <div className="absolute left-0 mt-2 w-80 overflow-hidden rounded-xl border border-[var(--color-line)] bg-white shadow-xl">
                  <div className="flex items-center justify-between border-b border-[var(--color-line)] px-3 py-2">
                    <p className="text-sm font-semibold">الإشعارات</p>
                    <button type="button" className="text-xs text-teal" onClick={() => markAll.mutate()}>
                      تعليم الكل كمقروء
                    </button>
                  </div>
                  <ul className="max-h-72 overflow-y-auto">
                    {(notifications.data?.items || []).length === 0 && (
                      <li className="px-3 py-6 text-center text-sm text-black/45">لا توجد إشعارات</li>
                    )}
                    {(notifications.data?.items || []).map((n) => (
                      <li key={n.id} className={`border-b border-[var(--color-line)] px-3 py-2.5 ${n.read_at ? 'opacity-60' : ''}`}>
                        <p className="text-sm font-medium">{n.title}</p>
                        {n.body && <p className="mt-0.5 text-xs text-black/55">{n.body}</p>}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          </div>
        </header>

        <main className="flex-1 overflow-auto">
          <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
