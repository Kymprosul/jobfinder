<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import JobCard from '../components/JobCard.vue'
import { useAppDataState } from '../stores/appData'
import { useConfigState } from '../stores/config'
import { useI18nState } from '../stores/i18n'
import { jobSources, matchesJobText, matchesSearchScope, publisherKey, publisherLabel } from '../utils/jobData'

const route = useRoute()
const router = useRouter()
const { searches, searchMap } = useConfigState()
const { t, sortLocale } = useI18nState()
const { jobs, previewJobs, loadingAppData: loading, appDataError: error, loadAll } = useAppDataState()

const filters = reactive({
  publisher: '',
  sent: 'pending',
  text: '',
  onlyNew: false,
  category: '',
  source: '',
})

const locallyRejectedJobIds = ref(new Set())

const currentSearchKey = computed(() => String(route.params.searchKey || ''))
const currentSearch = computed(() => searchMap.value[currentSearchKey.value] || null)

const publisherOptions = computed(() => {
  const publishers = new Map()

  for (const job of [...filteredJobs.value, ...filteredPreviewJobs.value]) {
    const key = publisherKey(job, t('common.unknownPublisher'))
    const existing = publishers.get(key)

    if (existing) {
      existing.count += 1
      continue
    }

    publishers.set(key, {
      key,
      label: publisherLabel(job, t('common.unknownPublisher')),
      count: 1,
    })
  }

  return [...publishers.values()].sort(
    (a, b) => b.count - a.count || a.label.localeCompare(b.label, sortLocale.value, { sensitivity: 'base' }),
  )
})

const sourceOptions = computed(() => {
  const scopedSources = jobs.value
    .filter((job) => matchesSearchScope(job, currentSearchKey.value))
    .map((job) => String(job.source || '').trim())
    .filter((source) => source !== '')

  return [...new Set(scopedSources)].sort((a, b) => a.localeCompare(b, sortLocale.value, { sensitivity: 'base' }))
})

function categoryOptionLabel(categoryKey) {
  if (categoryKey === 'spanish') {
    return 'Spanish'
  }

  if (categoryKey === 'international_business' || categoryKey === 'business') {
    return 'Business'
  }

  return String(categoryKey)
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

const categoryOptions = computed(() => {
  const scopedCategories = jobs.value
    .filter((job) => matchesSearchScope(job, currentSearchKey.value))
    .map((job) => String(job.category || '').trim())
    .filter((category) => category !== '')

  return [...new Set(scopedCategories)]
    .map((category) => ({
      value: category,
      label: categoryOptionLabel(category),
    }))
    .sort((a, b) => a.label.localeCompare(b.label, sortLocale.value, { sensitivity: 'base' }))
})

function passesExtraFilters(job) {
  const matchesOnlyNew = !filters.onlyNew || job.is_new === true
  const matchesCategory = !filters.category || String(job.category || '') === filters.category
  const matchesSource = !filters.source || String(job.source || '') === filters.source
  return matchesOnlyNew && matchesCategory && matchesSource
}

const filteredPreviewJobs = computed(() =>
  previewJobs.value.filter((job) => {
    if (!matchesSearchScope(job, currentSearchKey.value)) {
      return false
    }

    if (!passesExtraFilters(job)) {
      return false
    }

    const matchesPublisher = !filters.publisher || publisherKey(job) === filters.publisher

    return matchesJobText(job, filters.text) && matchesPublisher
  }),
)

const filteredJobs = computed(() =>
  jobs.value.filter((job) => {
    if (locallyRejectedJobIds.value.has(String(job.id))) {
      return false
    }

    if (!matchesSearchScope(job, currentSearchKey.value)) {
      return false
    }

    if (!passesExtraFilters(job)) {
      return false
    }

    const matchesPublisher = !filters.publisher || publisherKey(job) === filters.publisher
    const matchesSent =
      filters.sent === '' ||
      (filters.sent === 'sent' && job.sent) ||
      (filters.sent === 'pending' && !job.sent)

    return matchesJobText(job, filters.text) && matchesPublisher && matchesSent
  }),
)

const pendingJobs = computed(() => filteredJobs.value.filter((job) => !job.sent))
const sentJobs = computed(() => filteredJobs.value.filter((job) => job.sent))
const rejectedPreviewJobs = computed(() => filteredPreviewJobs.value.filter((job) => !job.accepted))
const newRejectedPreviewJobs = computed(() => rejectedPreviewJobs.value.filter((job) => job.is_new))

const sourceUrlPatterns = {
  chinajob: ['chinajob.com'],
  chinauniversityjobs: ['chinauniversityjobs.com'],
  echinacities: ['echinacities.com'],
  higheredjobs: ['higheredjobs.com'],
  hiredchina: ['hiredchina.com'],
  jobscina: ['jobscina.com'],
  jooble: ['jooble.org'],
  chinateachjobs: ['chinateachjobs.com'],
  unnc: ['jobs.nottingham.edu.cn', 'nottingham.edu.cn'],
}

function queryValue(value) {
  if (Array.isArray(value)) {
    return String(value[0] || '')
  }

  return typeof value === 'string' ? value : ''
}

function normalizeSentFilter(value) {
  if (value === '' || value === 'sent' || value === 'pending') {
    return value
  }

  return 'pending'
}

function normalizeOnlyNewFilter(value) {
  return value === '1'
}

function hydrateFiltersFromQuery(query) {
  filters.publisher = queryValue(query.publisher)
  filters.sent = normalizeSentFilter(queryValue(query.sent) || 'pending')
  filters.text = queryValue(query.q)
  filters.onlyNew = normalizeOnlyNewFilter(queryValue(query.only_new))
  filters.category = queryValue(query.category)
  filters.source = queryValue(query.source)
}

function filterQueryState(query) {
  return {
    publisher: queryValue(query.publisher),
    sent: normalizeSentFilter(queryValue(query.sent) || 'pending'),
    q: queryValue(query.q),
    only_new: queryValue(query.only_new),
    category: queryValue(query.category),
    source: queryValue(query.source),
  }
}

function syncFiltersToQuery() {
  const nextQuery = { ...route.query }

  delete nextQuery.publisher
  delete nextQuery.sent
  delete nextQuery.q
  delete nextQuery.only_new
  delete nextQuery.category
  delete nextQuery.source

  if (filters.publisher) {
    nextQuery.publisher = filters.publisher
  }

  if (filters.sent !== 'pending') {
    nextQuery.sent = filters.sent
  }

  if (filters.text.trim() !== '') {
    nextQuery.q = filters.text.trim()
  }

  if (filters.onlyNew) {
    nextQuery.only_new = '1'
  }

  if (filters.category) {
    nextQuery.category = filters.category
  }

  if (filters.source) {
    nextQuery.source = filters.source
  }

  const currentState = filterQueryState(route.query)
  const nextState = filterQueryState(nextQuery)

  if (
    currentState.publisher === nextState.publisher &&
    currentState.sent === nextState.sent &&
    currentState.q === nextState.q &&
    currentState.only_new === nextState.only_new &&
    currentState.category === nextState.category &&
    currentState.source === nextState.source
  ) {
    return
  }

  router.replace({ query: nextQuery })
}

function publisherOptionLabel(item) {
  const maxLength = 32
  const label =
    item.label.length > maxLength
      ? `${item.label.slice(0, maxLength - 1).trimEnd()}…`
      : item.label

  return `${label} (${item.count})`
}

function handleJobRejected(jobId) {
  const nextIds = new Set(locallyRejectedJobIds.value)
  nextIds.add(String(jobId))
  locallyRejectedJobIds.value = nextIds
}

function matchesSourceUrl(source, url) {
  const patterns = sourceUrlPatterns[source] || []
  const normalizedUrl = String(url || '').toLowerCase()

  return patterns.some((pattern) => normalizedUrl.includes(pattern))
}

function jobSourceLinks(job) {
  const sources = jobSources(job)
  const currentUrl = String(job?.url || '').trim()
  const alternateUrls = [
    currentUrl,
    ...(Array.isArray(job?.raw_meta?.alternate_urls) ? job.raw_meta.alternate_urls : []),
  ]
    .map((url) => String(url || '').trim())
    .filter((url, index, all) => url !== '' && all.indexOf(url) === index)

  const urlsBySource = new Map()

  for (const source of sources) {
    const matchedUrl = alternateUrls.find((url) => matchesSourceUrl(source, url))
    if (matchedUrl) {
      urlsBySource.set(source, matchedUrl)
      continue
    }

    if (source === job.source && currentUrl !== '') {
      urlsBySource.set(source, currentUrl)
    }
  }

  return sources.map((source) => ({
    source,
    url: urlsBySource.get(source) || (sources.length === 1 ? currentUrl : ''),
  }))
}

function postedDateHistory(job) {
  if (!Array.isArray(job?.posted_date_history)) {
    return []
  }

  return job.posted_date_history
    .map((value) => String(value || '').trim())
    .filter((value, index, all) => value !== '' && all.indexOf(value) === index)
}

function postedDateHistoryLabel(job) {
  const history = postedDateHistory(job)
  return history.length > 1 ? history.join(' · ') : ''
}

function makeJobCardProps(job, statusLabel, statusTone) {
  const links = jobSourceLinks(job)
  const history = postedDateHistoryLabel(job)
  return {
    job,
    statusLabel,
    statusTone,
    unknownInstitution: t('common.unknownInstitution'),
    unknownLocation: t('common.unknownLocation'),
    noDate: t('common.noDate'),
    scoreLabel: t('common.score', { score: job.score }),
    postedHistory: history.length > 1 ? t('resultsSearch.postedHistory', { history }) : '',
    sourceLinks: links,
    sourcesLabel: t('common.sources'),
  }
}

watch(
  () => route.query,
  (query) => {
    hydrateFiltersFromQuery(query)
  },
  { immediate: true },
)

watch(
  () => [filters.publisher, filters.sent, filters.text, filters.onlyNew, filters.category, filters.source],
  () => {
    syncFiltersToQuery()
  },
)

onMounted(() => {
  loadAll().catch(() => {})
})
</script>

<template>
  <section class="page">
    <div class="page-header">
      <div>
        <p class="eyebrow">{{ t('resultsSearch.eyebrow') }}</p>
        <h2>{{ currentSearch?.label || t('resultsSearch.titleNotFound') }}</h2>
      </div>
    </div>

    <p v-if="error" class="notice notice--error">{{ error }}</p>
    <p v-if="loading" class="notice">{{ t('resultsSearch.loading') }}</p>

    <template v-else-if="currentSearch">
      <div class="filters-bar filters-bar--search">
        <input
          id="results-filter-text"
          v-model="filters.text"
          type="search"
          name="results_text"
          :aria-label="t('resultsSearch.filters.textAria')"
          autocomplete="off"
          :placeholder="t('resultsSearch.filters.textPlaceholder')"
        />

        <select
          id="results-filter-publisher"
          v-model="filters.publisher"
          name="results_publisher"
          :aria-label="t('resultsSearch.filters.publisherAria')"
        >
          <option value="">{{ t('resultsSearch.filters.allPublishers') }}</option>
          <option v-for="item in publisherOptions" :key="item.key" :value="item.key">
            {{ publisherOptionLabel(item) }}
          </option>
        </select>

        <select id="results-filter-sent" v-model="filters.sent" name="results_sent" :aria-label="t('resultsSearch.filters.sentAria')">
          <option value="">{{ t('resultsSearch.filters.allStates') }}</option>
          <option value="sent">{{ t('resultsSearch.filters.sent') }}</option>
          <option value="pending">{{ t('resultsSearch.filters.pending') }}</option>
        </select>

        <button
          id="results-filter-only-new"
          type="button"
          class="secondary-button"
          :aria-pressed="filters.onlyNew"
          @click="filters.onlyNew = !filters.onlyNew"
        >
          {{ filters.onlyNew ? 'Solo nuevas: activado' : 'Solo nuevas' }}
        </button>

        <select id="results-filter-category" v-model="filters.category" name="results_category" aria-label="Categoría">
          <option value="">Categoría: All</option>
          <option v-for="item in categoryOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
        </select>

        <select id="results-filter-source" v-model="filters.source" name="results_source" aria-label="Fuente">
          <option value="">Fuente: All</option>
          <option v-for="item in sourceOptions" :key="item" :value="item">{{ item }}</option>
        </select>
      </div>

      <section class="panel">
        <div class="panel__header">
          <div>
            <h3>{{ t('resultsSearch.pendingAccepted') }}</h3>
            <p class="muted">
              {{ t('resultsSearch.pendingAcceptedHelp', { label: currentSearch.label }) }}
            </p>
          </div>
          <span class="results-column__count">{{ pendingJobs.length }}</span>
        </div>

        <p v-if="newRejectedPreviewJobs.length > 0" class="notice">
          {{ t('resultsSearch.newRejectedNotice', { count: newRejectedPreviewJobs.length }) }}
        </p>

        <div v-if="pendingJobs.length === 0" class="results-empty">
          {{ t('resultsSearch.emptyFiltered') }}
        </div>

        <JobCard
          v-for="job in pendingJobs"
          :key="job.id"
          v-bind="makeJobCardProps(job, t('common.pending'), 'warning')"
          :can-reject="true"
          @rejected="handleJobRejected"
        />
      </section>

      <section class="panel">
        <div class="panel__header">
          <div>
            <h3>{{ t('resultsSearch.sentAccepted') }}</h3>
            <p class="muted">
              {{ t('resultsSearch.sentAcceptedHelp') }}
            </p>
          </div>
          <span class="results-column__count">{{ sentJobs.length }}</span>
        </div>

        <div v-if="sentJobs.length === 0" class="results-empty">
          {{ t('resultsSearch.noSavedWithFilters') }}
        </div>

        <JobCard
          v-for="job in sentJobs"
          :key="job.id"
          v-bind="makeJobCardProps(job, t('common.sent'), 'success')"
        />
      </section>

      <section class="panel">
        <details class="collapsible">
          <summary class="collapsible__summary">
            <div>
              <h3>{{ t('resultsSearch.rejectedLastRun') }}</h3>
              <p class="muted">
                {{ t('resultsSearch.rejectedLastRunHelp') }}
              </p>
              <p v-if="newRejectedPreviewJobs.length > 0" class="muted">
                {{ t('resultsSearch.rejectedNewThisRun', { count: newRejectedPreviewJobs.length }) }}
              </p>
            </div>
            <span class="results-column__count">{{ rejectedPreviewJobs.length }}</span>
          </summary>

          <div v-if="rejectedPreviewJobs.length === 0" class="results-empty">
            {{ t('resultsSearch.noRejected') }}
          </div>
          <div v-else class="log-list">
            <JobCard
              v-for="job in rejectedPreviewJobs"
              :key="job.id"
              v-bind="makeJobCardProps(job, t('common.rejected'), 'warning')"
              :reason-label="t('resultsSearch.reason', { reason: job.rejection_reason })"
            />
          </div>
        </details>
      </section>
    </template>

    <template v-else>
      <section class="panel">
        <h3>{{ t('resultsSearch.searchDoesNotExist') }}</h3>
        <p class="muted">
          {{ t('resultsSearch.searchDoesNotExistHelp') }}
        </p>
        <div class="results-overview-grid">
          <RouterLink
            v-for="search in searches"
            :key="search.key"
            :to="`/resultados/${search.key}`"
            class="result-summary-card"
          >
            <strong>{{ search.label }}</strong>
            <span>{{ search.key }}</span>
          </RouterLink>
        </div>
      </section>
    </template>
  </section>
</template>
