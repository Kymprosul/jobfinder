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

async function runNow() {
  running.value = true
  error.value = ''
  try {
    await api.runNow()
    await loadAll({ force: true })
  } catch (err) {
    error.value = err.message
  } finally {
    running.value = false
  }
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
        <button class="secondary-button" :disabled="running" @click="runNow">
          {{ running ? t('dashboard.updating') : t('dashboard.updateOffers') }}
        </button>
        <button class="primary-button" :disabled="sending || pendingJobsCount === 0" @click="sendReport">
          {{ sending ? t('dashboard.sending') : t('dashboard.sendPending', { count: pendingJobsCount }) }}
        </button>
      </div>
    </div>

    <p v-if="visibleError" class="notice notice--error">{{ visibleError }}</p>
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
