import { computed, ref, watch } from 'vue'
import esMessages from '../locales/es.json'
import enMessages from '../locales/en.json'
import cnMessages from '../locales/cn.json'

const STORAGE_KEY = 'jobfinder_ui_locale'
const FALLBACK_LOCALE = 'es'
const SUPPORTED_LOCALES = new Set(['es', 'en', 'cn'])

const htmlLangByLocale = {
  es: 'es-ES',
  en: 'en-US',
  cn: 'zh-CN',
}

const messages = {
  es: esMessages,
  en: enMessages,
  cn: cnMessages,
}

function getInitialLocale() {
  try {
    const stored = String(localStorage.getItem(STORAGE_KEY) || '').trim().toLowerCase()
    if (SUPPORTED_LOCALES.has(stored)) {
      return stored
    }
  } catch {}

  const browserLocale = String(navigator.language || '').toLowerCase()
  if (browserLocale.startsWith('zh')) {
    return 'cn'
  }
  if (browserLocale.startsWith('en')) {
    return 'en'
  }

  return FALLBACK_LOCALE
}

function resolveMessage(localeCode, path) {
  const segments = String(path || '').split('.').filter(Boolean)
  let current = messages[localeCode]

  for (const segment of segments) {
    if (!current || typeof current !== 'object') {
      return null
    }

    current = current[segment]
  }

  return typeof current === 'string' ? current : null
}

const locale = ref(getInitialLocale())

function setLocale(nextLocale) {
  const normalized = String(nextLocale || '').trim().toLowerCase()
  if (!SUPPORTED_LOCALES.has(normalized)) {
    return
  }

  locale.value = normalized
}

watch(
  locale,
  (value) => {
    const htmlLang = htmlLangByLocale[value] || htmlLangByLocale[FALLBACK_LOCALE]
    document.documentElement.lang = htmlLang

    try {
      localStorage.setItem(STORAGE_KEY, value)
    } catch {}
  },
  { immediate: true },
)

function t(path, params = {}) {
  const template =
    resolveMessage(locale.value, path)
    || resolveMessage(FALLBACK_LOCALE, path)
    || String(path)

  return template.replace(/\{(\w+)\}/g, (_, key) => String(params[key] ?? ''))
}

function sendModeLabel(mode) {
  const key = String(mode || '')
  const label = resolveMessage(locale.value, `sendMode.${key}`) || resolveMessage(FALLBACK_LOCALE, `sendMode.${key}`)
  return label || key
}

function statusLabel(status) {
  const key = String(status || '')
  const label = resolveMessage(locale.value, `status.${key}`) || resolveMessage(FALLBACK_LOCALE, `status.${key}`)
  return label || key
}

const dateLocale = computed(() => htmlLangByLocale[locale.value] || htmlLangByLocale[FALLBACK_LOCALE])
const sortLocale = computed(() => dateLocale.value)
const languageOptions = [
  { value: 'es', label: 'ES' },
  { value: 'en', label: 'EN' },
  { value: 'cn', label: 'CN' },
]

export function useI18nState() {
  return {
    locale,
    setLocale,
    t,
    sendModeLabel,
    statusLabel,
    dateLocale,
    sortLocale,
    languageOptions,
  }
}
