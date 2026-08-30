import { useCallback, useSyncExternalStore } from 'react'
import { applyTheme, resolveTheme, setTheme, THEME_CHANGE_EVENT, type Theme } from '@/lib/theme'

function subscribe(onStoreChange: () => void) {
  const media = window.matchMedia('(prefers-color-scheme: dark)')
  media.addEventListener('change', onStoreChange)
  window.addEventListener('storage', onStoreChange)
  window.addEventListener(THEME_CHANGE_EVENT, onStoreChange)
  return () => {
    media.removeEventListener('change', onStoreChange)
    window.removeEventListener('storage', onStoreChange)
    window.removeEventListener(THEME_CHANGE_EVENT, onStoreChange)
  }
}

function getSnapshot(): Theme {
  return resolveTheme()
}

export function useTheme() {
  const theme = useSyncExternalStore(subscribe, getSnapshot, () => 'light' as Theme)

  const choose = useCallback((next: Theme) => {
    setTheme(next)
  }, [])

  const toggle = useCallback(() => {
    const next: Theme = resolveTheme() === 'dark' ? 'light' : 'dark'
    setTheme(next)
  }, [])

  return { theme, setTheme: choose, toggle }
}

export function initTheme() {
  applyTheme(resolveTheme())
}
