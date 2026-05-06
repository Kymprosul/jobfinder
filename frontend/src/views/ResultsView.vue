<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useAppDataState } from '../stores/appData'
import { useConfigState } from '../stores/config'
import { useI18nState } from '../stores/i18n'
import { matchesSearchScope } from '../utils/jobData'

const { searches } = useConfigState()
const { jobs, previewJobs, loadingAppData: loading, appDataError: error } = useAppDataState()
const { t } = useI18nState()

const summaries = computed(() => {
  const searchKeys = searches.value.map((s) => s.key)
  const counts = Object.fromEntries(searchKeys.map((key) => [key, { pending: 0, sent: 0, rejected: 0 }]))

  for (const job of jobs.value) {
    for (const key of searchKeys) {
      if (matchesSearchScope(job, key)) {
        if (job.sent) {
          counts[key].sent += 1
        } else {
          counts[key].pending += 1
        }
      }
    }
  }

  for (const job of previewJobs.value) {
    for (const key of searchKeys) {
      if (matchesSearchScope(job, key) && !job.accepted) {
        counts[key].rejected += 1
      }
    }
  }

  return searches.value.map((search) => ({
    ...search,
    ...counts[search.key],
  }))
})
</script>

<template>
  <section class="page">
    <div class="page-header">
      <div>
        <p class="eyebrow">{{ t('results.eyebrow') }}</p>
        <h2>{{ t('results.title') }}</h2>
      </div>
    </div>

    <p v-if="error" class="notice notice--error">{{ error }}</p>
    <p v-if="loading" class="notice">{{ t('results.loading') }}</p>

    <template v-else>
      <section class="panel">
        <h3>{{ t('results.configuredSearches') }}</h3>
        <p class="muted">
          {{ t('results.configuredSearchesHelp') }}
        </p>

        <div class="results-overview-grid">
          <RouterLink
            v-for="search in summaries"
            :key="search.key"
            :to="`/resultados/${search.key}`"
            class="result-summary-card"
          >
            <div>
              <strong>{{ search.label }}</strong>
              <p class="muted">{{ search.key }}</p>
            </div>
            <div class="result-summary-card__stats">
              <span>{{ t('results.pending', { count: search.pending }) }}</span>
              <span>{{ t('results.sent', { count: search.sent }) }}</span>
              <span>{{ t('results.rejected', { count: search.rejected }) }}</span>
            </div>
          </RouterLink>
        </div>
      </section>
    </template>
  </section>
</template>
