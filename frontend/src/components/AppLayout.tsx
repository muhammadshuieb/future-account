import { NavLink, Outlet, Navigate, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { LogOut, Shield, Bell, Menu, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useAuth } from '@/context/AuthContext'
import api from '@/lib/api'
import { resolveAlertHref } from '@/lib/alertLinks'
import { formatRoleNames } from '@/lib/rbacLabels'
import { APP_VERSION } from '@/version'
import { LOGO } from '@/lib/brand'
import LanguageSwitcher from '@/components/LanguageSwitcher'
import { navGroups, type NavItem } from '@/config/navigation'

function canSeeNavItem(item: NavItem, hasPermission: (p: string) => boolean): boolean {
  if (item.key === 'partners') {
    return hasPermission('customers.view') || hasPermission('suppliers.view')
  }
  if (!item.permission) return true
  return hasPermission(item.permission)
}

type NotifItem = {
  id: number
  type?: string
  title: string
  body?: string
  read_at?: string | null
  created_at: string
  data?: { href?: string } | null
}

export default function AppLayout() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { user, loading, logout, hasPermission } = useAuth()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [notifOpen, setNotifOpen] = useState(false)
  const qc = useQueryClient()

  useEffect(() => {
    document.body.style.overflow = mobileOpen ? 'hidden' : ''
    return () => {
      document.body.style.overflow = ''
    }
  }, [mobileOpen])

  const notifications = useQuery({
    queryKey: ['notifications'],
    queryFn: async () => (await api.get('/notifications')).data.data as {
      items: NotifItem[]
      unread_count: number
    },
    enabled: !!user,
    refetchInterval: 60_000,
  })

  const markAll = useMutation({
    mutationFn: () => api.post('/notifications/read-all'),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const openNotification = async (n: NotifItem) => {
    const href = resolveAlertHref(n)
    setNotifOpen(false)
    try {
      if (!n.read_at) {
        await api.post(`/notifications/${n.id}/read`)
        void qc.invalidateQueries({ queryKey: ['notifications'] })
      }
    } catch {
      /* still navigate */
    }
    if (href) navigate(href)
  }
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

  const closeMobile = () => setMobileOpen(false)

  const nav = (
    <>
      <div className="app-sidebar-header shrink-0 border-b border-white/10 px-4 py-3">
        <div className="flex items-center gap-3">
          <div className="brand-logo-chip shrink-0">
            <img
              src={LOGO.onLight}
              alt="SYNAMOR TECHNOLOGY"
              className="brand-logo brand-logo--sidebar"
            />
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold leading-tight">{t('app.name')}</p>
            <p className="truncate text-[11px] text-white/55">Syna Co</p>
            <p className="text-[10px] text-white/35">v{APP_VERSION}</p>
          </div>
        </div>
      </div>

      <nav className="app-sidebar-nav min-h-0 flex-1">
        <div className="app-sidebar-nav-inner px-3 py-4">
          {visibleGroups.map((group) => (
            <div key={group.labelKey} className="mb-5 last:mb-0">
              <p className="mb-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-white/35">
                {t(group.labelKey)}
              </p>
              <div className="space-y-1">
                {group.items.map((item) => {
                  const Icon = item.icon
                  return (
                    <NavLink
                      key={item.to}
                      to={item.to}
                      end={item.end}
                      onClick={closeMobile}
                      className={({ isActive }) =>
                        [
                          'app-sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
                          isActive
                            ? 'bg-teal/90 text-white shadow-sm ring-1 ring-white/15'
                            : 'text-white/75 hover:bg-white/10 hover:text-white',
                        ].join(' ')
                      }
                    >
                      <Icon size={18} strokeWidth={1.75} className="shrink-0 opacity-90" />
                      <span className="flex-1 truncate leading-snug">{t(`nav.${item.key}`)}</span>
                    </NavLink>
                  )
                })}
              </div>
            </div>
          ))}
        </div>
      </nav>

      <div className="app-sidebar-footer shrink-0 border-t border-white/10 p-4">
        <div className="mb-3 flex items-center gap-3 text-sm text-white/80">
          <Shield size={16} className="shrink-0 text-teal" />
          <div className="min-w-0 flex-1">
            <p className="truncate font-medium text-white">{user.name}</p>
            <p className="truncate text-xs text-white/45">{formatRoleNames(t, user.roles)}</p>
          </div>
        </div>
        <button
          type="button"
          onClick={() => void logout()}
          className="touch-target flex w-full items-center gap-2 rounded-lg px-3 py-2.5 text-sm text-white/70 transition hover:bg-white/10 hover:text-white"
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
      <aside className="app-sidebar print-hide hidden h-screen w-[260px] shrink-0 flex-col md:flex">
        {nav}
      </aside>

      {mobileOpen && (
        <div className="print-hide fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true">
          <button
            type="button"
            className="absolute inset-0 bg-black/50 backdrop-blur-[1px]"
            aria-label={t('common.close')}
            onClick={closeMobile}
          />
          <aside className="app-sidebar app-sidebar-drawer absolute inset-y-0 right-0 flex h-full w-[min(280px,88vw)] flex-col shadow-2xl">
            <button
              type="button"
              className="touch-target absolute left-3 top-3 z-10 rounded-lg p-2 text-white/70 hover:bg-white/10"
              aria-label={t('common.close')}
              onClick={closeMobile}
            >
              <X size={20} />
            </button>
            {nav}
          </aside>
        </div>
      )}

      <div className="app-shell-main flex min-h-0 min-w-0 flex-1 flex-col">
        <header className="app-topbar print-hide sticky top-0 z-20 shrink-0 border-b border-[var(--color-line)] bg-white/90 backdrop-blur-md">
          <div className="flex items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <div className="flex min-w-0 items-center gap-3">
              <button
                type="button"
                className="touch-target rounded-lg p-2 hover:bg-mist md:hidden"
                aria-label="Menu"
                onClick={() => setMobileOpen(true)}
              >
                <Menu size={22} />
              </button>
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-ink sm:text-base">{t('app.welcome', { name: user.name })}</p>
                <p className="hidden truncate text-xs text-black/45 sm:block">{t('app.headerHint')}</p>
              </div>
            </div>

            <div className="flex shrink-0 items-center gap-2">
              <LanguageSwitcher />
              <div className="relative">
                <button
                  type="button"
                  className="touch-target relative rounded-lg border border-[var(--color-line)] bg-white p-2.5 text-ink/70 hover:bg-mist"
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
                  <div className="absolute left-0 mt-2 w-[min(20rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-[var(--color-line)] bg-white shadow-xl">
                    <div className="flex items-center justify-between border-b border-[var(--color-line)] px-3 py-2">
                      <p className="text-sm font-semibold">{t('nav.notifications')}</p>
                      <button type="button" className="touch-target text-xs text-teal" onClick={() => markAll.mutate()}>
                        {t('nav.markAllRead')}
                      </button>
                    </div>
                    <ul className="max-h-72 overflow-y-auto">
                      {(notifications.data?.items || []).length === 0 && (
                        <li className="px-3 py-6 text-center text-sm text-black/45">{t('nav.noNotifications')}</li>
                      )}
                      {(notifications.data?.items || []).map((n) => (
                        <li key={n.id}>
                          <button
                            type="button"
                            className={`w-full cursor-pointer border-b border-[var(--color-line)] px-3 py-2.5 text-right transition hover:bg-mist ${n.read_at ? 'opacity-60' : ''}`}
                            onClick={() => void openNotification(n)}
                          >
                            <p className="text-sm font-medium">{n.title}</p>
                            {n.body && <p className="mt-0.5 text-xs text-black/55">{n.body}</p>}
                          </button>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            </div>
          </div>
        </header>

        <main className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden">
          <div className="mx-auto max-w-6xl px-4 py-5 sm:px-6 sm:py-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
