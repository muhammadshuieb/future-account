import { useTranslation } from 'react-i18next'
import { setUserLanguage } from '@/i18n'

const langs = [
  { code: 'ar', label: 'العربية' },
  { code: 'en', label: 'English' },
  { code: 'tr', label: 'Türkçe' },
] as const

export default function LanguageSwitcher() {
  const { i18n } = useTranslation()

  return (
    <select
      className="rounded-lg border border-[var(--color-line)] bg-white px-2 py-1.5 text-xs text-ink/80"
      value={i18n.language?.slice(0, 2) || 'ar'}
      onChange={(e) => setUserLanguage(e.target.value)}
      aria-label="Language"
    >
      {langs.map((l) => (
        <option key={l.code} value={l.code}>{l.label}</option>
      ))}
    </select>
  )
}
