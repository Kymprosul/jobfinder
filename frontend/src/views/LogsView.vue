<script setup>
import { computed, onMounted, reactive, shallowRef, watch } from 'vue'
import { useAppDataState } from '../stores/appData'
import StatusBadge from '../components/StatusBadge.vue'
import { useI18nState } from '../stores/i18n'

const LOG_PAGE_SIZE = 100
const { runs, logs, loadingAppData: loading, appDataError: error } = useAppDataState()
const { t, dateLocale, statusLabel } = useI18nState()
const filters = reactive({
  level: '',
  source: '',
  runId: '',
  text: '',
})
const visibleLogLimit = shallowRef(LOG_PAGE_SIZE)

const lastRun = computed(() => runs.value[0] || null)
const sourceOptions = computed(() => [...new Set(logs.value.map((entry) => entry.source).filter(Boolean))].sort())
const runOptions = computed(() => [...new Set(logs.value.map((entry) => entry.run_id).filter(Boolean))].slice(0, 20))

const visibleLogs = computed(() => {
  const text = filters.text.trim().toLowerCase()
  return logs.value.filter((entry) => {
    const matchesText =
      text === '' ||
      [entry.message, entry.source, entry.stage, entry.context ? JSON.stringify(entry.context) : '']
        .join(' ')
        .toLowerCase()
        .includes(text)

    const matchesLevel = !filters.level || entry.level === filters.level
    const matchesSource = !filters.source || entry.source === filters.source
    const matchesRun = !filters.runId || entry.run_id === filters.runId

    return matchesText && matchesLevel && matchesSource && matchesRun
  })
})

const renderedLogs = computed(() => visibleLogs.value.slice(0, visibleLogLimit.value))
const hasMoreLogs = computed(() => visibleLogs.value.length > renderedLogs.value.length)

const stats = computed(() => {
  const result = { total: 0, info: 0, warnings: 0, errors: 0 }
  for (const entry of logs.value) {
    result.total += 1
    if (entry.level === 'info') result.info += 1
    else if (entry.level === 'warning') result.warnings += 1
    else if (entry.level === 'error') result.errors += 1
  }
  return result
})

function formatDate(value) {
  return value ? new Date(value).toLocaleString(dateLocale.value) : t('common.noDate')
}

function formatDuration(durationMs) {
  if (durationMs == null) {
    return t('common.noValue')
  }

  if (durationMs < 1000) {
    return `${durationMs} ms`
  }

  return `${(durationMs / 1000).toFixed(1)} s`
}

function loadMoreLogs() {
  visibleLogLimit.value = Math.min(visibleLogLimit.value + LOG_PAGE_SIZE, visibleLogs.value.length)
}

watch(
  () => [filters.level, filters.source, filters.runId, filters.text],
  () => {
    visibleLogLimit.value = LOG_PAGE_SIZE
  },
)
</script>

<template>
  <section class="page">
    <div class="page-header">
      <div>
        <p class="eyebrow">{{ t('logs.eyebrow') }}</p>
        <h2>{{ t('logs.title') }}</h2>
      </div>
    </div>

    <p v-if="error" class="notice notice--error">{{ error }}</p>
    <p v-if="loading" class="notice">{{ t('logs.loading') }}</p>

    <template v-else>
      <div class="stat-grid">
        <article class="stat-card">
          <p class="stat-card__title">{{ t('logs.stats.lastRun') }}</p>
          <strong class="stat-card__value">{{ lastRun ? formatDate(lastRun.started_at) : t('common.noData') }}</strong>
          <p class="stat-card__helper">{{ t('logs.stats.runId', { id: lastRun?.id || 'n/d' }) }}</p>
        </article>
        <article class="stat-card">
          <p class="stat-card__title">{{ t('logs.stats.loadedEvents') }}</p>
          <strong class="stat-card__value">{{ stats.total }}</strong>
          <p class="stat-card__helper">{{ t('logs.stats.loadedEventsHelp', { info: stats.info, warnings: stats.warnings, errors: stats.errors }) }}</p>
        </article>
        <article class="stat-card">
          <p class="stat-card__title">{{ t('logs.stats.runDuration') }}</p>
          <strong class="stat-card__value">{{ formatDuration(lastRun?.duration_ms) }}</strong>
          <p class="stat-card__helper">{{ t('logs.stats.runDurationHelp', { count: lastRun?.sources_processed ?? 0 }) }}</p>
        </article>
        <article class="stat-card">
          <p class="stat-card__title">{{ t('logs.stats.newDetected') }}</p>
          <strong class="stat-card__value">{{ lastRun?.new_jobs_count ?? 0 }}</strong>
          <p class="stat-card__helper">{{ t('logs.stats.newDetectedHelp', { count: lastRun?.pending_jobs_count ?? 0 }) }}</p>
        </article>
      </div>

      <section class="panel">
        <h3>{{ t('logs.latestRuns') }}</h3>
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('logs.table.start') }}</th>
              <th>{{ t('logs.table.trigger') }}</th>
              <th>{{ t('logs.table.duration') }}</th>
              <th>{{ t('logs.table.newItems') }}</th>
              <th>{{ t('logs.table.errors') }}</th>
              <th>{{ t('logs.table.sourceStatus') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="run in runs" :key="run.id">
              <td>
                <div>{{ formatDate(run.started_at) }}</div>
                <div class="table-subtext">{{ run.id }}</div>
              </td>
              <td>{{ run.trigger }}</td>
              <td>{{ formatDuration(run.duration_ms) }}</td>
              <td>
                <div>{{ run.new_jobs_count }}</div>
                <div class="table-subtext">{{ t('logs.table.acceptedSuffix', { count: run.accepted_jobs }) }}</div>
              </td>
              <td>{{ run.errors }}</td>
              <td>
                <div class="log-chip-list">
                  <span
                    v-for="result in run.source_results || []"
                    :key="`${run.id}-${result.source}`"
                    class="log-chip"
                    :class="{
                      'log-chip--danger': !['ok', 'empty', 'partial', 'disabled'].includes(result.status),
                      'log-chip--warning': result.status === 'partial',
                    }"
                  >
                    {{ result.source }} · {{ statusLabel(result.status) }} · {{ result.jobs_count }}
                  </span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="panel">
        <h3>{{ t('logs.recentEvents') }}</h3>
        <div class="filters-bar filters-bar--logs">
          <input
            id="logs-filter-text"
            v-model="filters.text"
            type="search"
            name="logs_text"
            :aria-label="t('logs.filters.searchAria')"
            autocomplete="off"
            :placeholder="t('logs.filters.searchPlaceholder')"
          />

          <select id="logs-filter-level" v-model="filters.level" name="logs_level" :aria-label="t('logs.filters.levelAria')">
            <option value="">{{ t('logs.filters.allLevels') }}</option>
            <option value="info">{{ statusLabel('info') }}</option>
            <option value="warning">{{ statusLabel('warning') }}</option>
            <option value="error">{{ statusLabel('error') }}</option>
          </select>

          <select id="logs-filter-source" v-model="filters.source" name="logs_source" :aria-label="t('logs.filters.sourceAria')">
            <option value="">{{ t('common.allSources') }}</option>
            <option v-for="source in sourceOptions" :key="source" :value="source">{{ source }}</option>
          </select>

          <select id="logs-filter-run" v-model="filters.runId" name="logs_run_id" :aria-label="t('logs.filters.runAria')">
            <option value="">{{ t('logs.filters.allRuns') }}</option>
            <option v-for="runId in runOptions" :key="runId" :value="runId">{{ runId }}</option>
          </select>
        </div>

        <p class="muted">{{ t('logs.showing', { shown: renderedLogs.length, total: visibleLogs.length }) }}</p>
        <div class="log-list">
          <article v-for="entry in renderedLogs" :key="`${entry.timestamp}-${entry.message}-${entry.source || ''}-${entry.level}`" class="log-item">
            <div class="log-item__head">
              <div class="log-item__badges">
                <StatusBadge
                  :label="statusLabel(entry.level)"
                  :tone="entry.level === 'error' ? 'danger' : entry.level === 'warning' ? 'warning' : 'neutral'"
                />
                <span v-if="entry.source" class="log-chip">{{ entry.source }}</span>
                <span v-if="entry.stage" class="log-chip">{{ entry.stage }}</span>
                <span v-if="entry.run_id" class="log-chip">{{ entry.run_id }}</span>
              </div>
              <time>{{ formatDate(entry.timestamp) }}</time>
            </div>
            <p>{{ entry.message }}</p>
            <pre v-if="entry.context && Object.keys(entry.context).length">{{ JSON.stringify(entry.context, null, 2) }}</pre>
          </article>
        </div>
        <button v-if="hasMoreLogs" class="secondary-button" type="button" @click="loadMoreLogs">
          {{ t('logs.loadMore') }}
        </button>
      </section>
    </template>
  </section>
</template>
