const WEB_TOKEN_KEY = 'fa_token'
const APP_TOKEN_KEY = 'fa_token_app'

function userAgent(): string {
  return typeof navigator === 'undefined' ? '' : navigator.userAgent
}

/** Chrome/Edge install, iOS Add to Home Screen, or the Flutter shell. */
export function isInstalledApp(): boolean {
  if (typeof window === 'undefined') return false
  if (/SynaCoApp/i.test(userAgent())) return true
  if (window.matchMedia('(display-mode: standalone)').matches) return true
  if (window.matchMedia('(display-mode: window-controls-overlay)').matches) return true
  const nav = window.navigator as Navigator & { standalone?: boolean }
  return nav.standalone === true
}

export function authClient(): 'web' | 'android' | 'windows' | 'ios' {
  const ua = userAgent()
  if (/SynaCoApp\/windows/i.test(ua)) return 'windows'
  if (/SynaCoApp\/android/i.test(ua)) return 'android'
  if (/SynaCoApp\/ios/i.test(ua)) return 'ios'
  if (isInstalledApp()) {
    if (/Android/i.test(ua)) return 'android'
    if (/Windows/i.test(ua)) return 'windows'
    if (/iPhone|iPad|iPod/i.test(ua)) return 'ios'
  }
  return 'web'
}

export function authDeviceName(): string {
  const client = authClient()
  if (client === 'web') return 'web'
  const ua = userAgent()
  if (/Edg\//i.test(ua)) return `${client}-edge`
  if (/Chrome/i.test(ua)) return `${client}-chrome`
  return client
}

function tokenKey(): string {
  if (isInstalledApp()) return APP_TOKEN_KEY
  // Print/preview popups from the installed app share origin storage but are not standalone.
  if (typeof window !== 'undefined' && window.opener && localStorage.getItem(APP_TOKEN_KEY)) {
    return APP_TOKEN_KEY
  }
  return WEB_TOKEN_KEY
}

export function getAuthToken(): string | null {
  return localStorage.getItem(tokenKey())
}

export function setAuthToken(token: string): void {
  localStorage.setItem(tokenKey(), token)
}

export function clearAuthToken(): void {
  localStorage.removeItem(tokenKey())
}
