import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import ar from '@/locales/ar.json'
import en from '@/locales/en.json'
import tr from '@/locales/tr.json'

const saved = localStorage.getItem('fa_lang')

i18n.use(initReactI18next).init({
  resources: {
    ar: { translation: ar },
    en: { translation: en },
    tr: { translation: tr },
  },
  lng: saved || 'ar',
  fallbackLng: 'ar',
  interpolation: { escapeValue: false },
})

function applyDocumentLang(lng: string) {
  document.documentElement.lang = lng
  document.documentElement.dir = lng === 'ar' ? 'rtl' : 'ltr'
}

i18n.on('languageChanged', (lng) => {
  applyDocumentLang(lng)
})

applyDocumentLang(saved || 'ar')

/** Persist an explicit user language choice. */
export function setUserLanguage(lng: string) {
  localStorage.setItem('fa_lang', lng)
  void i18n.changeLanguage(lng)
}

/**
 * Apply company default_locale only when the user has not chosen a language.
 */
export function applyDefaultLocale(locale: string) {
  if (localStorage.getItem('fa_lang')) return
  const next = ['ar', 'en', 'tr'].includes(locale) ? locale : 'ar'
  void i18n.changeLanguage(next)
}

export default i18n
