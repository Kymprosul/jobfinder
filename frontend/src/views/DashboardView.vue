<script setup>
import { computed, ref } from 'vue'
import { api } from '../api/client'
import StatCard from '../components/StatCard.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { useAppDataState } from '../stores/appData'
import { useConfigState } from '../stores/config'
import { useI18nState } from '../stores/i18n'

const running = ref(false)
const sending = ref(false)
const error = ref('')
const { config } = useConfigState()
const { t, dateLocale, sendModeLabel: translateSendMode, statusLabel } = useI18nState()
const {
  status,
  runs,
  pendingJobsCount,
  loadingAppData,
  appDataError,
  loadAll,
} = useAppDataState()

const lastRun = computed(() => status.value?.last_run || runs.value[0] || null)
const lastEmailSentAt = computed(() => status.value?.last_email_sent_at || null)
const computedSendModeLabel = computed(() => {
  const mode = config.value?.email?.send_mode || 'manual_and_automatic'
  return translateSendMode(mode)
})
const sourceUrlMap = computed(() => Object.fromEntries(
  Object.entries(config.value?.sources || {}).map(([key, source]) => [key, source.public_url || source.search_url || '']),
))
const sourceErrors = computed(
  () => lastRun.value?.source_results?.filter((item) => !['ok', 'empty', 'partial', 'disabled'].includes(item.status)).length || 0,
)
const loading = computed(() => loadingAppData.value)
const visibleError = computed(() => error.value || appDataError.value)

// --- Progress tracking ---
const progressActive = ref(false)
const progressTotal = ref(0)
const progressCurrent = ref(0)
const progressSource = ref('')
const progressResults = ref([])
const progressErrors = ref([])

const progressPercent = computed(() => {
  if (progressTotal.value === 0) return 0
  return Math.round((progressCurrent.value / progressTotal.value) * 100)
})

const progressSummary = computed(() => {
  if (!progressActive.value && progressResults.value.length === 0) return ''
  const totalNew = progressResults.value.reduce((sum, r) => sum + (r.data?.new || 0), 0)
  const totalAccepted = progressResults.value.reduce((sum, r) => sum + (r.data?.accepted || 0), 0)
  return t('dashboard.updatingSummary', {
    current: progressCurrent.value,
    total: progressTotal.value,
    accepted: totalAccepted,
    new: totalNew,
  })
})

const enabledSources = computed(() => {
  const sources = config.value?.sources || {}
  return Object.entries(sources)
    .filter(([, src]) => src.enabled)
    .map(([key]) => key)
})

async function updateAllSources() {
  running.value = true
  error.value = ''
  progressActive.value = true
  progressResults.value = []
  progressErrors.value = []

  const sources = enabledSources.value
  progressTotal.value = sources.length
  progressCurrent.value = 0

  for (let i = 0; i < sources.length; i++) {
    const source = sources[i]
    progressSource.value = source
    progressCurrent.value = i

    try {
      const result = await api.runSource(source)
      progressResults.value.push({ source, data: result.data })
    } catch (err) {
      progressErrors.value.push({ source, error: err.message })
    }
  }

  progressCurrent.value = sources.length
  progressSource.value = ''

  // Build source results for run summary
  const sourceResults = []
  for (const r of progressResults.value) {
    sourceResults.push({
      source: r.source,
      status: r.data?.status || 'ok',
      jobs_count: r.data?.jobs_count || 0,
      accepted: r.data?.accepted || 0,
      new: r.data?.new || 0,
      duplicates: r.data?.duplicates || 0,
      message: r.data?.message || null,
      duration_ms: r.data?.duration_ms || 0,
    })
  }
  for (const e of progressErrors.value) {
    sourceResults.push({
      source: e.source,
      status: 'error',
      jobs_count: 0,
      accepted: 0,
      new: 0,
      duplicates: 0,
      message: e.error,
      duration_ms: 0,
    })
  }

  // Save run summary and refresh dashboard
  try {
    await api.completeRun(sourceResults)
    await loadAll({ force: true })
  } catch {}

  // Keep summary visible for a moment, then reset
  setTimeout(() => {
    progressActive.value = false
    running.value = false
  }, 3000)
}

async function sendReport() {
  sending.value = true
  error.value = ''
  try {
    await api.sendReport()
    await loadAll({ force: true })
  } catch (err) {
    error.value = err.message
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section class="page">
    <div class="page-header">
      <div>
        <p class="eyebrow">{{ t('dashboard.eyebrow') }}</p>
        <h2>{{ t('dashboard.title') }}</h2>
      </div>
      <div class="button-row">
        <button class="secondary-button" :disabled="running" @click="updateAllSources">
          {{ running ? t('dashboard.updating') : t('dashboard.updateOffers') }}
        </button>
        <button class="primary-button" :disabled="sending || pendingJobsCount === 0" @click="sendReport">
          {{ sending ? t('dashboard.sending') : t('dashboard.sendPending', { count: pendingJobsCount }) }}
        </button>
      </div>
    </div>

    <p v-if="visibleError" class="notice notice--error">{{ visibleError }}</p>

    <!-- Progress bar -->
    <div v-if="progressActive || progressResults.length > 0" class="progress-container">
      <div class="progress-header">
        <span class="progress-label">
          {{ progressActive
            ? t('dashboard.updatingSource', { source: progressSource, current: progressCurrent + 1, total: progressTotal })
            : progressSummary
          }}
        </span>
        <span class="progress-percent">{{ progressPercent }}%</span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" :style="{ width: progressPercent + '%' }"></div>
      </div>
      <div v-if="progressErrors.length > 0" class="progress-errors">
        <span v-for="err in progressErrors" :key="err.source" class="progress-error-tag">
          {{ err.source }}: {{ err.error }}
        </span>
      </div>
      <div v-if="!progressActive && progressResults.length > 0" class="progress-done">
        {{ t('dashboard.updatingDone', {
          total: progressResults.length,
          errors: progressErrors.length,
          new: progressResults.reduce((s, r) => s + (r.data?.new || 0), 0),
        }) }}
      </div>
    </div>

    <p v-if="loading" class="notice">{{ t('dashboard.loading') }}</p>

    <template v-else>
      <div class="stat-grid">
        <StatCard
          :title="t('dashboard.cards.lastRun')"
          :value="lastRun?.started_at ? new Date(lastRun.started_at).toLocaleString(dateLocale) : t('common.noData')"
          :helper="t('dashboard.cards.lastRunValue')"
        />
        <StatCard
          :title="t('dashboard.cards.sourcesProcessed')"
          :value="lastRun?.sources_processed ?? status?.active_sources ?? 0"
          :helper="t('dashboard.cards.sourcesProcessedHelp')"
        />
        <StatCard
          :title="t('dashboard.cards.pending')"
          :value="pendingJobsCount"
          :helper="t('dashboard.cards.pendingHelp')"
        />
        <StatCard
          :title="t('dashboard.cards.newlyDetected')"
          :value="lastRun?.new_jobs_count ?? 0"
          :helper="t('dashboard.cards.newlyDetectedHelp')"
        />
        <StatCard
          :title="t('dashboard.cards.errors')"
          :value="sourceErrors"
          :helper="t('dashboard.cards.errorsHelp')"
        />
      </div>

      <section class="panel">
        <div class="panel__header">
          <h3>{{ t('dashboard.currentState') }}</h3>
          <StatusBadge
            :label="status?.smtp_configured ? t('status.smtpConfigured') : t('status.smtpPending')"
            :tone="status?.smtp_configured ? 'success' : 'warning'"
          />
        </div>

        <p v-if="status && !status.dependencies_available" class="notice">
          {{ t('dashboard.degradedMode') }}
        </p>

        <dl class="detail-grid">
          <div>
            <dt>{{ t('dashboard.details.lastSend') }}</dt>
            <dd>{{ lastEmailSentAt ? new Date(lastEmailSentAt).toLocaleString(dateLocale) : t('common.noSends') }}</dd>
          </div>
          <div>
            <dt>{{ t('dashboard.details.sendMode') }}</dt>
            <dd>{{ computedSendModeLabel }}</dd>
          </div>
          <div>
            <dt>{{ t('dashboard.details.acceptedOffers') }}</dt>
            <dd>{{ lastRun?.accepted_jobs ?? 0 }}</dd>
          </div>
          <div>
            <dt>{{ t('dashboard.details.duplicatesDiscarded') }}</dt>
            <dd>{{ lastRun?.duplicates_discarded ?? 0 }}</dd>
          </div>
          <div>
            <dt>{{ t('dashboard.details.tooOld') }}</dt>
            <dd>{{ lastRun?.discarded?.too_old ?? 0 }}</dd>
          </div>
        </dl>
      </section>

      <section class="panel">
        <div class="panel__header">
          <h3>{{ t('dashboard.sources') }}</h3>
        </div>

        <table class="table">
          <thead>
            <tr>
              <th>{{ t('common.source') }}</th>
              <th>{{ t('common.status') }}</th>
              <th>{{ t('common.offers') }}</th>
              <th>{{ t('common.detail') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in lastRun?.source_results || []" :key="item.source">
              <td>
                <a v-if="sourceUrlMap[item.source]" :href="sourceUrlMap[item.source]" target="_blank" rel="noreferrer">
                  {{ item.source }}
                </a>
                <span v-else>{{ item.source }}</span>
              </td>
              <td>
                <StatusBadge
                  :label="statusLabel(item.status)"
                  :tone="item.status === 'ok' ? 'success' : item.status === 'partial' || item.status === 'empty' ? 'neutral' : 'danger'"
                />
              </td>
              <td>{{ item.jobs_count }}</td>
              <td>{{ item.message || t('common.noDetails') }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </section>
</template>

<style scoped>
.progress-container {
  margin: 16px 0;
  padding: 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}

.progress-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.progress-label {
  font-size: 0.875rem;
  font-weight: 500;
  color: #334155;
}

.progress-percent {
  font-size: 0.875rem;
  font-weight: 600;
  color: #3b82f6;
}

.progress-bar {
  width: 100%;
  height: 8px;
  background: #e2e8f0;
  border-radius: 4px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #3b82f6, #60a5fa);
  border-radius: 4px;
  transition: width 0.4s ease;
}

.progress-errors {
  margin-top: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.progress-error-tag {
  font-size: 0.75rem;
  padding: 2px 8px;
  background: #fef2f2;
  color: #dc2626;
  border-radius: 4px;
  border: 1px solid #fecaca;
}

.progress-done {
  margin-top: 8px;
  font-size: 0.875rem;
  font-weight: 500;
  color: #16a34a;
}
</style>
