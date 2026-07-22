import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/context/AuthContext'
import { LOGO } from '@/lib/brand'

export default function LoginPage() {
  const { t } = useTranslation()
  const { user, loading, login } = useAuth()
  const [email, setEmail] = useState('admin@future-account.test')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  if (!loading && user) {
    return <Navigate to="/" replace />
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      await login(email, password)
    } catch (err: unknown) {
      const axiosErr = err as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } }
      }
      setError(
        axiosErr.response?.data?.errors?.email?.[0] ||
          axiosErr.response?.data?.message ||
          'تعذر تسجيل الدخول',
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="relative grid min-h-screen place-items-center overflow-hidden px-4">
      <div
        className="pointer-events-none absolute inset-0"
        style={{
          background:
            'radial-gradient(ellipse 70% 50% at 90% 10%, rgba(13,115,119,.18), transparent 55%), radial-gradient(ellipse 50% 40% at 0% 90%, rgba(18,48,64,.12), transparent 50%), linear-gradient(165deg, #0a1f2a 0%, #123040 45%, #0d7377 100%)',
        }}
      />
      <div className="relative w-full max-w-md overflow-hidden rounded-2xl border border-white/10 bg-white shadow-2xl shadow-black/20">
        <div className="bg-slate-panel px-8 py-10 text-white">
          <img
            src={LOGO.onDark}
            alt="SYNAMOR TECHNOLOGY"
            className="brand-logo brand-logo--login mb-5"
          />
          <p className="text-3xl font-extrabold tracking-tight">{t('app.name')}</p>
          <p className="mt-1 text-sm font-medium text-teal-soft/90">Syna Co</p>
          <p className="mt-2 text-sm leading-6 text-white/65">{t('login.subtitle')}</p>
        </div>
        <form onSubmit={onSubmit} className="space-y-4 px-8 py-8">
          <div>
            <label className="mb-1.5 block text-sm font-medium">البريد الإلكتروني</label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full rounded-lg border border-black/10 bg-paper px-3 py-2.5 outline-none ring-teal focus:ring-2"
              required
            />
          </div>
          <div>
            <label className="mb-1.5 block text-sm font-medium">كلمة المرور</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full rounded-lg border border-black/10 bg-paper px-3 py-2.5 outline-none ring-teal focus:ring-2"
              required
            />
          </div>
          {error && (
            <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-danger">{error}</p>
          )}
          <button
            type="submit"
            disabled={submitting}
            className="w-full rounded-lg bg-teal px-4 py-2.5 font-semibold text-white transition hover:bg-teal-dark disabled:opacity-60"
          >
            {submitting ? 'جاري الدخول...' : 'تسجيل الدخول'}
          </button>
          <p className="text-center text-xs text-black/45">{t('login.demoHint')}</p>
        </form>
      </div>
    </div>
  )
}
