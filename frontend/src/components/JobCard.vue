<script setup>
import { computed, ref } from 'vue'
import { api } from '../api/client'
import StatusBadge from './StatusBadge.vue'
import { sourceColorStyle } from '../utils/sourceColors'

const props = defineProps({
  job: {
    type: Object,
    required: true,
  },
  statusLabel: {
    type: String,
    required: true,
  },
  statusTone: {
    type: String,
    default: 'neutral',
  },
  unknownInstitution: {
    type: String,
    default: '',
  },
  unknownLocation: {
    type: String,
    default: '',
  },
  noDate: {
    type: String,
    default: '',
  },
  scoreLabel: {
    type: String,
    default: '',
  },
  postedHistory: {
    type: String,
    default: '',
  },
  sourceLinks: {
    type: Array,
    default: () => [],
  },
  sourcesLabel: {
    type: String,
    default: '',
  },
  reasonLabel: {
    type: String,
    default: '',
  },
  canReject: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['rejected'])

const hasSourceLinks = computed(() => props.sourceLinks.length > 0)
const hasPostedHistory = computed(() => props.postedHistory !== '')
const hasReason = computed(() => props.reasonLabel !== '')
const jobTags = computed(() => (Array.isArray(props.job?.tags) ? props.job.tags : []))
const salary = computed(() => {
  const s = props.job?.raw_meta?.salary
  if (!s || String(s).trim() === '') return null
  const text = String(s).trim()
  // Extract only the first salary range (stop at Job Location, Job Type, or Work Experience)
  const cut = text.search(/\b(job location|job type|work experience|location)\b/i)
  return cut > 0 ? text.slice(0, cut).trim() : text
})

const showRejectConfirm = ref(false)
const rejecting = ref(false)
const rejectError = ref('')

function sourceLinkKey(item) {
  return `${props.job.id}-${item.source}`
}

function formatTagLabel(tag) {
  if (String(tag).startsWith('city:')) {
    const city = String(tag).slice(5).trim()
    return city.replace(/\b\w/g, (c) => c.toUpperCase()) || String(tag)
  }

  return String(tag)
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function tagStyle(tag) {
  if (tag === 'native_required') {
    return {
      background: 'rgba(160, 103, 18, 0.12)',
      borderColor: 'rgba(160, 103, 18, 0.2)',
      color: 'var(--warning)',
    }
  }

  if (tag === 'phd_required') {
    return {
      background: 'rgba(32, 90, 160, 0.12)',
      borderColor: 'rgba(32, 90, 160, 0.2)',
      color: '#205aa0',
    }
  }

  if (String(tag).startsWith('city:')) {
    return {
      background: 'rgba(120, 128, 123, 0.12)',
      borderColor: 'rgba(120, 128, 123, 0.2)',
      color: '#465149',
    }
  }

  if (tag === 'salary_available') {
    return {
      background: 'rgba(29, 107, 73, 0.12)',
      borderColor: 'rgba(29, 107, 73, 0.2)',
      color: 'var(--success)',
    }
  }

  return {
    background: 'rgba(214, 232, 222, 0.38)',
    borderColor: 'rgba(33, 91, 67, 0.12)',
    color: 'var(--accent)',
  }
}

function toggleRejectConfirm() {
  rejectError.value = ''
  showRejectConfirm.value = true
}

function cancelRejectConfirm() {
  rejectError.value = ''
  showRejectConfirm.value = false
}

async function confirmReject() {
  rejectError.value = ''
  rejecting.value = true

  try {
    await api.rejectJob(props.job.id)
    emit('rejected', props.job.id)
    showRejectConfirm.value = false
  } catch {
    rejectError.value = 'No se pudo descartar. Intenta de nuevo.'
  } finally {
    rejecting.value = false
  }
}
</script>

<template>
  <article class="result-card">
    <div class="result-card__head">
      <a :href="job.url" target="_blank" rel="noreferrer">{{ job.title }}</a>
      <div class="result-card__head-actions">
        <StatusBadge :label="statusLabel" :tone="statusTone" />
        <button
          v-if="canReject"
          type="button"
          class="result-card__trash"
          :disabled="rejecting"
          aria-label="Descartar oferta"
          @click="toggleRejectConfirm"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14H6L5 6" /><path d="M10 11v6" /><path d="M14 11v6" /><path d="M9 6V4h6v2" />
          </svg>
        </button>
      </div>
    </div>

    <div v-if="canReject && showRejectConfirm" class="result-card__confirm">
      <span>¿Descartar esta oferta?</span>
      <button type="button" class="secondary-button secondary-button--danger" :disabled="rejecting" @click="confirmReject">
        Sí
      </button>
      <button type="button" class="secondary-button" :disabled="rejecting" @click="cancelRejectConfirm">
        No
      </button>
    </div>

    <p class="table-subtext">
      {{ job.institution || unknownInstitution }} · {{ job.location || unknownLocation }}
    </p>
    <div class="result-card__meta">
      <span>{{ job.posted_date || noDate }}</span>
      <span>{{ scoreLabel }}</span>
    </div>
    <p v-if="salary" class="result-card__salary">{{ salary }}</p>

    <div v-if="jobTags.length > 0" class="source-tags">
      <span
        v-for="tag in jobTags"
        :key="`${job.id}-${tag}`"
        class="source-tag"
        :style="tagStyle(tag)"
      >
        {{ formatTagLabel(tag) }}
      </span>
    </div>

    <div v-if="hasSourceLinks" class="source-tags">
      <span class="source-tags__label">{{ sourcesLabel }}</span>
      <template v-for="item in sourceLinks" :key="sourceLinkKey(item)">
        <a
          v-if="item.url"
          :href="item.url"
          target="_blank"
          rel="noreferrer"
          class="source-tag"
          :style="sourceColorStyle(item.source)"
        >
          {{ item.source }}
        </a>
        <span v-else class="source-tag" :style="sourceColorStyle(item.source)">
          {{ item.source }}
        </span>
      </template>
    </div>

    <p v-if="hasPostedHistory" class="table-subtext">
      {{ postedHistory }}
    </p>

    <p v-if="rejectError" class="result-card__reason">{{ rejectError }}</p>
    <p v-if="hasReason" class="result-card__reason">{{ reasonLabel }}</p>
  </article>
</template>

<style scoped>
.result-card__salary {
  font-size: 0.82rem;
  color: var(--success, #1d6b49);
  margin: 0.1rem 0 0.15rem;
}
</style>
