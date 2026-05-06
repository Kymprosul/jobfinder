<script setup>
import { computed, reactive } from 'vue'
import { useAppDataState } from '../stores/appData'
import { useConfigState } from '../stores/config'
import { useI18nState } from '../stores/i18n'
import { jobSources, matchesJobText, matchesSearchScope, publisherKey, publisherLabel } from '../utils/jobData'
import { sourceColorStyle } from '../utils/sourceColors'

const { jobs, loadingAppData: loading, appDataError: error } = useAppDataState()
const filters = reactive({
  source: '',
  category: '',
  sent: 'pending',
  text: '',
})
const { searches, searchLabel } = useConfigState()
const { t, sortLocale } = useI18nState()

const sourceOptions = computed(() => [...new Set(jobs.value.map((job) => job.source))].sort())

function categoryLabel(categoryKey) {
  return categoryKey ? searchLabel(categoryKey) : t('common.noCategory')
}

const filteredJobs = computed(() =>
  jobs.value.filter((job) => {
    const matchesSource = !filters.source || job.source === filters.source
    const matchesCategory = !filters.category || matchesSearchScope(job, filters.category)
    const matchesSent =
      filters.sent === '' ||
      (filters.sent === 'sent' && job.sent) ||
      (filters.sent === 'pending' && !job.sent)

    return matchesJobText(job, filters.text) && matchesSource && matchesCategory && matchesSent
  }),
)

const publisherGroups = computed(() => {
  const groups = new Map()

  for (const job of filteredJobs.value) {
    const key = publisherKey(job, t('common.unknownPublisher'))
    const existing = groups.get(key)

    if (existing) {
      existing.total += 1
      existing.pending += job.sent ? 0 : 1
      existing.sent += job.sent ? 1 : 0
      if (String(job.posted_date || '').localeCompare(String(existing.latestPostedDate || '')) > 0) {
        existing.latestPostedDate = job.posted_date || ''
      }
      if (job.category) {
        existing.categories.add(categoryLabel(job.category))
      }
      for (const source of jobSources(job)) {
        existing.sources.add(source)
      }
      existing.jobs.push({
        id: job.id,
        title: job.title,
        url: job.url,
        postedDate: job.posted_date || '',
      })
      continue
    }

    groups.set(key, {
      key,
      label: publisherLabel(job, t('common.unknownPublisher')),
      total: 1,
      pending: job.sent ? 0 : 1,
      sent: job.sent ? 1 : 0,
      latestPostedDate: job.posted_date || '',
      categories: new Set(job.category ? [categoryLabel(job.category)] : []),
      sources: new Set(jobSources(job)),
      jobs: [
        {
          id: job.id,
          title: job.title,
          url: job.url,
          postedDate: job.posted_date || '',
        },
      ],
    })
  }

  return [...groups.values()]
    .map((group) => ({
      ...group,
      categories: [...group.categories].sort((a, b) => a.localeCompare(b, sortLocale.value, { sensitivity: 'base' })),
      sources: [...group.sources].sort((a, b) => a.localeCompare(b, sortLocale.value, { sensitivity: 'base' })),
      jobs: group.jobs
        .sort(
          (a, b) =>
            String(b.postedDate || '').localeCompare(String(a.postedDate || '')) ||
            a.title.localeCompare(b.title, sortLocale.value, { sensitivity: 'base' }),
        )
        .slice(0, 3),
    }))
    .sort(
      (a, b) =>
        b.total - a.total ||
        String(b.latestPostedDate || '').localeCompare(String(a.latestPostedDate || '')) ||
        a.label.localeCompare(b.label, sortLocale.value, { sensitivity: 'base' }),
    )
})
</script>

<template>
  <section class="page">
    <div class="page-header">
      <div>
        <p class="eyebrow">{{ t('publishers.eyebrow') }}</p>
        <h2>{{ t('publishers.title') }}</h2>
      </div>
    </div>

    <p v-if="error" class="notice notice--error">{{ error }}</p>
    <p v-if="loading" class="notice">{{ t('publishers.loading') }}</p>

    <template v-else>
      <div class="filters-bar filters-bar--publishers">
        <input v-model="filters.text" type="search" :placeholder="t('publishers.filters.textPlaceholder')" />

        <select v-model="filters.source">
          <option value="">{{ t('publishers.filters.allSources') }}</option>
          <option v-for="item in sourceOptions" :key="item" :value="item">{{ item }}</option>
        </select>

        <select v-model="filters.category">
          <option value="">{{ t('publishers.filters.allCategories') }}</option>
          <option v-for="search in searches" :key="search.key" :value="search.key">{{ search.label }}</option>
        </select>

        <select v-model="filters.sent">
          <option value="">{{ t('publishers.filters.allStates') }}</option>
          <option value="sent">{{ t('publishers.filters.sent') }}</option>
          <option value="pending">{{ t('publishers.filters.pending') }}</option>
        </select>
      </div>

      <section class="panel">
        <h3>{{ t('publishers.ranking') }}</h3>
        <p class="muted">
          {{ t('publishers.rankingHelp') }}
        </p>
        <div v-if="publisherGroups.length === 0" class="results-empty publisher-empty">
          {{ t('publishers.empty') }}
        </div>
        <div v-else class="publisher-grid">
          <article v-for="group in publisherGroups" :key="group.key" class="publisher-card">
            <header class="results-column__header">
              <div>
                <h4>{{ group.label }}</h4>
                <p class="table-subtext">
                  {{ t('publishers.pendingSent', { pending: group.pending, sent: group.sent }) }}
                </p>
              </div>
              <span class="results-column__count">{{ group.total }}</span>
            </header>

            <div class="result-card__meta">
              <span>{{ t('publishers.latestPublication', { date: group.latestPostedDate || t('common.noDate') }) }}</span>
              <span>{{ group.categories.join(' · ') || t('common.noCategory') }}</span>
            </div>

            <div v-if="group.sources.length > 0" class="source-tags">
              <span class="source-tags__label">{{ t('common.sources') }}</span>
              <span
                v-for="source in group.sources"
                :key="`${group.key}-${source}`"
                class="source-tag"
                :style="sourceColorStyle(source)"
              >
                {{ source }}
              </span>
            </div>

            <div class="publisher-card__jobs">
              <p class="publisher-card__jobs-label">{{ t('publishers.recentOffers') }}</p>
              <a
                v-for="job in group.jobs"
                :key="job.id"
                :href="job.url"
                target="_blank"
                rel="noreferrer"
                class="publisher-job"
              >
                <span>{{ job.title }}</span>
                <span class="publisher-job__date">{{ job.postedDate || t('common.noDate') }}</span>
              </a>
            </div>
          </article>
        </div>
      </section>
    </template>
  </section>
</template>
