export const defaultSearchFilters = {
  score_threshold: 5,
  max_age_days: 90,
  discard_without_posted_date: true,
}

export const defaultSearches = [
  {
    key: 'spanish',
    label: 'Spanish',
    keywords: [],
    positive_support: [],
    excluded: [],
    filters: { ...defaultSearchFilters },
  },
  {
    key: 'international_business',
    label: 'International Business',
    keywords: [],
    positive_support: [],
    excluded: [],
    filters: { ...defaultSearchFilters },
  },
]

const reservedKeys = new Set(['positive_support', 'excluded'])

function normalizeStringList(items) {
  if (!Array.isArray(items)) {
    return []
  }

  return Array.from(
    new Set(
      items
        .map((item) => String(item || '').trim())
        .filter(Boolean),
    ),
  )
}

function normalizeFilters(filters = {}) {
  const nextValue = filters && typeof filters === 'object' ? filters : {}

  return {
    score_threshold: Number(nextValue.score_threshold) > 0 ? Number(nextValue.score_threshold) : defaultSearchFilters.score_threshold,
    max_age_days: Number(nextValue.max_age_days) > 0 ? Number(nextValue.max_age_days) : defaultSearchFilters.max_age_days,
    discard_without_posted_date:
      typeof nextValue.discard_without_posted_date === 'boolean'
        ? nextValue.discard_without_posted_date
        : defaultSearchFilters.discard_without_posted_date,
  }
}

export function humanizeSearchKey(key) {
  const normalized = String(key || '')
    .trim()
    .replace(/_/g, ' ')

  return normalized === '' ? 'Nueva búsqueda' : normalized.replace(/\b\w/g, (char) => char.toUpperCase())
}

export function normalizeSearchKey(value, index = 1) {
  const normalized = String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')

  if (normalized === '' || reservedKeys.has(normalized)) {
    return `search_${index}`
  }

  return normalized
}

export function normalizeSearches(searches) {
  const source = Array.isArray(searches) && searches.length > 0 ? searches : defaultSearches
  const usedKeys = new Set()

  return source
    .map((search, index) => {
      const requestedKey = normalizeSearchKey(search?.key, index + 1)
      let key = requestedKey
      let suffix = 2

      while (usedKeys.has(key)) {
        key = `${requestedKey}_${suffix}`
        suffix += 1
      }

      usedKeys.add(key)

      return {
        ...search,
        key,
        label: String(search?.label || '').trim() || humanizeSearchKey(key),
        keywords: normalizeStringList(search?.keywords),
        positive_support: normalizeStringList(search?.positive_support),
        excluded: normalizeStringList(search?.excluded),
        filters: normalizeFilters(search?.filters),
      }
    })
    .filter((search) => search.key !== '')
}
