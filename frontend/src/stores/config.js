import { computed, ref } from 'vue'
import { api } from '../api/client'
import { defaultSearches, humanizeSearchKey, normalizeSearches } from '../utils/searches'

const config = ref(null)
const loading = ref(false)
const error = ref('')
let pendingRequest = null

function normalizeConfig(value) {
  const nextValue = value && typeof value === 'object' ? value : {}
  const searches = normalizeSearches(nextValue.searches)

  return {
    ...nextValue,
    searches,
  }
}

async function loadConfig({ force = false } = {}) {
  if (!force && config.value) {
    return config.value
  }

  if (!force && pendingRequest) {
    return pendingRequest
  }

  loading.value = true
  error.value = ''

  pendingRequest = api
    .getConfig()
    .then((response) => {
      config.value = normalizeConfig(response.data)
      return config.value
    })
    .catch((err) => {
      error.value = err.message
      throw err
    })
    .finally(() => {
      loading.value = false
      pendingRequest = null
    })

  return pendingRequest
}

function setConfig(value) {
  config.value = normalizeConfig(value)
}

const searches = computed(() => config.value?.searches || defaultSearches)
const searchMap = computed(() =>
  Object.fromEntries(searches.value.map((search) => [search.key, search])),
)

function searchLabel(key) {
  return searchMap.value[key]?.label || humanizeSearchKey(key)
}

export function useConfigState() {
  return {
    config,
    loadingConfig: loading,
    configError: error,
    searches,
    searchMap,
    loadConfig,
    setConfig,
    searchLabel,
  }
}
