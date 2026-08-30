import { Moon, Sun } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useTheme } from '@/hooks/useTheme'

export default function ThemeSwitcher({ className = '' }: { className?: string }) {
  const { t } = useTranslation()
  const { theme, toggle } = useTheme()
  const isDark = theme === 'dark'
  const label = isDark ? t('common.themeLight') : t('common.themeDark')

  return (
    <button
      type="button"
      onClick={toggle}
      className={`touch-target inline-flex items-center justify-center rounded-lg border border-[var(--color-line)] bg-[var(--color-surface)] p-2.5 text-ink/70 transition hover:bg-mist hover:text-ink ${className}`.trim()}
      aria-label={label}
      title={label}
    >
      {isDark ? <Sun size={18} /> : <Moon size={18} />}
    </button>
  )
}
