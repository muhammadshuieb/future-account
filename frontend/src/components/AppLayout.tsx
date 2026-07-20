import { NavLink, Outlet, Navigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { LogOut, Shield, Bell, Menu, X } from 'lucide-react'
import { useState } from 'react'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { APP_VERSION } from '@/version'
import LanguageSwitcher from '@/components/LanguageSwitcher'
import { navGroups, type NavItem } from '@/config/navigation'

function canSeeNavItem(item: NavItem, hasPermission: (p: string) => boolean): boolean {
  if (item.key === 'partners') {
    return hasPermission('customers.view') || hasPermission('suppliers.view')
  }
  if (!item.permission) return true
  return hasPermission(item.permission)
}

export default function AppLayout() {
  const { t } = useTranslation()
  const { user, loading, logout, hasPermission } = useAuth()
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
          <p className="text-teal">{t('common.loading')}</p>
        </div>
      </div>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  const visibleGroups = navGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => canSeeNavItem(item, hasPermission)),
    }))
    .filter((group) => group.items.length > 0)

  const nav = (
    <>
      <div className="shrink-0 border-b border-white/10 px-5 py-5">
        <div className="flex items-center gap-3">
          <div className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-teal to-teal-dark text-xl font-extrabold shadow-lg shadow-black/20">
            س
          </div>
          <div className="min-w-0">
            <p className="truncate text-lg font-bold leading-tight">{t('app.name')}</p>
            <p className="truncate text-xs text-white/55">{t('app.tagline')}</p>
            <p className="text-[10px] text-white/40">v{APP_VERSION}</p>
          </div>
        </div>
      </div>

      <nav className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-3">
        {visibleGroups.map((group) => (
          <div key={group.labelKey} className="mb-4 last:mb-0">
            <p className="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-wider text-white/35">
              {t(group.labelKey)}
            </p>
            <div className="space-y-0.5">
              {group.items.map((item) => {
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
                          ? 'bg-teal text-white shadow-sm ring-1 ring-white/10'
                          : 'text-white/70 hover:bg-white/8 hover:text-white',
                      ].join(' ')
                    }
                  >
                    <Icon size={18} strokeWidth={1.75} className="shrink-0" />
                    <span className="flex-1 leading-snug">{t(`nav.${item.key}`)}</span>
                  </NavLink>
                )
              })}
            </div>
          </div>
        ))}
      </nav>

      <div className="shrink-0 border-t border-white/10 p-4">
        <div className="mb-3 flex items-center gap-2 text-sm text-white/80">
          <Shield size={16} className="shrink-0 text-teal" />
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
          {t('nav.logout')}
        </button>
        <p className="mt-3 text-center text-[10px] text-white/30">© {new Date().getFullYear()} {t('app.name')}</p>
      </div>
    </>
  )

  return (
    <div className="flex h-screen overflow-hidden">
      <aside className="print-hide hidden h-screen w-64 shrink-0 flex-col bg-slate-panel text-white lg:flex">
        {nav}
      </aside>

      {mobileOpen && (
        <div className="print-hide fixed inset-0 z-40 lg:hidden">
          <button type="button" className="absolute inset-0 bg-black/40" aria-label={t('common.close')} onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 right-0 flex h-full w-72 flex-col bg-slate-panel text-white shadow-2xl">
            <button type="button" className="absolute left-3 top-3 rounded-lg p-2 text-white/70 hover:bg-white/10" onClick={() => setMobileOpen(false)}>
              <X size={18} />
            </button>
            {nav}
          </aside>
        </div>
      )}

      <div className="app-shell-main flex min-h-0 min-w-0 flex-1 flex-col">
        <header className="app-topbar print-hide sticky top-0 z-20 shrink-0 border-b border-[var(--color-line)] bg-white/85 backdrop-blur-md">
          <div className="flex items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <div className="flex min-w-0 items-center gap-3">
              <button type="button" className="rounded-lg p-2 hover:bg-mist lg:hidden" onClick={() => setMobileOpen(true)}>
                <Menu size={20} />
              </button>
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-ink">{t('app.welcome', { name: user.name })}</p>
                <p className="truncate text-xs text-black/45">{t('app.headerHint')}</p>
              </div>
            </div>

            <div className="flex shrink-0 items-center gap-2">
              <LanguageSwitcher />
              <div className="relative">
                <button
                  type="button"
                  className="relative rounded-lg border border-[var(--color-line)] bg-white p-2.5 text-ink/70 hover:bg-mist"
                  onClick={() => setNotifOpen((v) => !v)}
                  aria-label={t('nav.notifications')}
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
                      <p className="text-sm font-semibold">{t('nav.notifications')}</p>
                      <button type="button" className="text-xs text-teal" onClick={() => markAll.mutate()}>
                        {t('nav.markAllRead')}
                      </button>
                    </div>
                    <ul className="max-h-72 overflow-y-auto">
                      {(notifications.data?.items || []).length === 0 && (
                        <li className="px-3 py-6 text-center text-sm text-black/45">{t('nav.noNotifications')}</li>
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
          </div>
        </header>

        <main className="min-h-0 flex-1 overflow-y-auto">
          <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
