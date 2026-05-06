import { computed, ref } from 'vue'
import { api } from '../api/client'

const initialized = ref(false)
const loading = ref(false)
const error = ref('')
const status = ref(null)
const runs = ref([])
const jobs = ref([])
const previewJobs = ref([])
const logs = ref([])
let pendingRequest = null

async function loadAll({ force = false } = {}) {
  if (!force && initialized.value) {
    return
  }

  if (!force && pendingRequest) {
    return pendingRequest
  }

  loading.value = true
  error.value = ''

  pendingRequest = Promise.all([
    api.getStatus(),
    api.getRuns(),
    api.getJobs(),
    api.getPreviewJobs(),
    api.getLogs(),
  ])
    .then(([statusResponse, runsResponse, jobsResponse, previewResponse, logsResponse]) => {
      status.value = statusResponse.data
      runs.value = runsResponse.data
      jobs.value = jobsResponse.data
      previewJobs.value = previewResponse.data
      logs.value = logsResponse.data
      initialized.value = true
    })
    .catch((requestError) => {
      error.value = requestError instanceof Error ? requestError.message : 'Error inesperado'
      throw requestError
    })
    .finally(() => {
      loading.value = false
      pendingRequest = null
    })

  return pendingRequest
}

const pendingJobsCount = computed(() => jobs.value.filter((job) => !job.sent).length)

export function useAppDataState() {
  return {
    initialized,
    loadingAppData: loading,
    appDataError: error,
    status,
    runs,
    jobs,
    previewJobs,
    logs,
    pendingJobsCount,
    loadAll,
  }
}
