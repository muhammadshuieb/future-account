export type Theme = 'light' | 'dark'

export const THEME_STORAGE_KEY = 'fa_theme'
export const THEME_CHANGE_EVENT = 'fa-theme-change'

function notifyThemeChange() {
  window.dispatchEvent(new Event(THEME_CHANGE_EVENT))
}

export function getStoredTheme(): Theme | null {
  const value = localStorage.getItem(THEME_STORAGE_KEY)
  if (value === 'light' || value === 'dark') return value
  return null
}

export function getSystemTheme(): Theme {
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export function resolveTheme(): Theme {
  return getStoredTheme() ?? getSystemTheme()
}

export function applyTheme(theme: Theme) {
  document.documentElement.setAttribute('data-theme', theme)
  document.documentElement.style.colorScheme = theme
  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.setAttribute('content', theme === 'dark' ? '#0a1f2a' : '#0d7377')
}

export function setTheme(theme: Theme) {
  localStorage.setItem(THEME_STORAGE_KEY, theme)
  applyTheme(theme)
  notifyThemeChange()
}

export function toggleTheme(): Theme {
  const next: Theme = resolveTheme() === 'dark' ? 'light' : 'dark'
  setTheme(next)
  return next
}
