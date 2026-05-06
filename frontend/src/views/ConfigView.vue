<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import { useConfigState } from '../stores/config'
import { useI18nState } from '../stores/i18n'
import { defaultSearchFilters, humanizeSearchKey, normalizeSearchKey } from '../utils/searches'

const { setConfig } = useConfigState()
const { t, sendModeLabel } = useI18nState()

const loading = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')
const selectedSearchId = ref('')
const form = reactive({
  email: {
    enabled: true,
    to: '',
    daily_send_time: '08:00',
    send_mode: 'manual_and_automatic',
  },
  searches: [],
  sources: {},
  meta: {
    smtp_configured: false,
  },
})

let nextSearchId = 1

function textAreaValue(items) {
  return Array.isArray(items) ? items.join('\n') : ''
}

function listFromText(value) {
  return Array.from(
    new Set(
      value
        .split('\n')
        .map((item) => item.trim())
        .filter(Boolean),
    ),
  )
}

function createSearchModel(search = {}) {
  nextSearchId += 1

  return {
    id: `search-${nextSearchId}`,
    key: String(search.key || '').trim(),
    label: String(search.label || '').trim(),
    keywords: Array.isArray(search.keywords) ? [...search.keywords] : [],
    positive_support: Array.isArray(search.positive_support) ? [...search.positive_support] : [],
    excluded: Array.isArray(search.excluded) ? [...search.excluded] : [],
    filters: {
      score_threshold: Number(search.filters?.score_threshold) || defaultSearchFilters.score_threshold,
      max_age_days: Number(search.filters?.max_age_days) || defaultSearchFilters.max_age_days,
      discard_without_posted_date:
        typeof search.filters?.discard_without_posted_date === 'boolean'
          ? search.filters.discard_without_posted_date
          : defaultSearchFilters.discard_without_posted_date,
    },
    draftKeyword: '',
    draftPositiveSupport: '',
    draftExcluded: '',
  }
}

const activeSearch = computed(
  () => form.searches.find((search) => search.id === selectedSearchId.value) || form.searches[0] || null,
)

function selectSearch(searchId) {
  selectedSearchId.value = searchId
}

function syncSearchKey(search, index) {
  const label = String(search.label || '').trim()

  if (String(search.key || '').trim() === '' && label !== '') {
    search.key = normalizeSearchKey(label, index + 1)
  }
}

function addSearch() {
  const search = createSearchModel()
  form.searches.push(search)
  selectedSearchId.value = search.id
}

function removeSearch(index) {
  if (form.searches.length <= 1 || index < 0) {
    return
  }

  const search = form.searches[index]
  const searchName = search?.label || search?.key || t('config.searchDefaultName', { index: index + 1 })
  const confirmed = window.confirm(t('config.deleteConfirm', { name: searchName }))

  if (!confirmed) {
    return
  }

  const [removed] = form.searches.splice(index, 1)

  if (removed?.id === selectedSearchId.value) {
    selectedSearchId.value = form.searches[Math.max(0, index - 1)]?.id || form.searches[0]?.id || ''
  }
}

function addSearchListItem(search, field, draftField) {
  const value = String(search[draftField] || '').trim()

  if (value === '') {
    return
  }

  if (!search[field].includes(value)) {
    search[field] = [...search[field], value]
  }

  search[draftField] = ''
}

function removeSearchListItem(search, field, value) {
  search[field] = search[field].filter((item) => item !== value)
}

function normalizeLocalSearches() {
  const normalizedSearches = []
  const usedKeys = new Set()

  for (const [index, search] of form.searches.entries()) {
    const baseKey = normalizeSearchKey(search.key || search.label, index + 1)
    let key = baseKey
    let suffix = 2

    while (usedKeys.has(key)) {
      key = `${baseKey}_${suffix}`
      suffix += 1
    }

    usedKeys.add(key)
    normalizedSearches.push({
      id: search.id,
      key,
      label: String(search.label || '').trim() || humanizeSearchKey(key),
      keywords: [...search.keywords],
      positive_support: [...search.positive_support],
      excluded: [...search.excluded],
      filters: {
        score_threshold: Number(search.filters.score_threshold) || defaultSearchFilters.score_threshold,
        max_age_days: Number(search.filters.max_age_days) || defaultSearchFilters.max_age_days,
        discard_without_posted_date: !!search.filters.discard_without_posted_date,
      },
    })
  }

  form.searches.splice(0, form.searches.length, ...normalizedSearches.map((search) => createSearchModel(search)))

  if (activeSearch.value) {
    selectedSearchId.value = activeSearch.value.id
  }

  return normalizedSearches
}

function populateForm(configData) {
  Object.assign(form.email, configData.email)
  Object.assign(form.sources, configData.sources)
  form.meta = configData.meta || { smtp_configured: false }
  form.searches.splice(0, form.searches.length, ...(configData.searches || []).map((search) => createSearchModel(search)))
  selectedSearchId.value = form.searches[0]?.id || ''
}

async function loadConfig() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.getConfig()
    populateForm(response.data)
    setConfig(response.data)
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

async function saveConfig() {
  saving.value = true
  error.value = ''
  message.value = ''

  try {
    const normalizedSearches = normalizeLocalSearches()
    const payload = {
      email: form.email,
      searches: normalizedSearches.map(({ key, label, keywords, positive_support, excluded, filters }) => ({
        key,
        label,
        keywords,
        positive_support,
        excluded,
        filters,
      })),
      sources: form.sources,
    }

    await api.saveConfig(payload)
    const refreshed = await api.getConfig()
    populateForm(refreshed.data)
    setConfig(refreshed.data)
    message.value = t('config.saved')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

onMounted(loadConfig)
</script>

<template>
  <section class="page">
    <div class="page-header">
      <div>
        <p class="eyebrow">{{ t('config.eyebrow') }}</p>
        <h2>{{ t('config.title') }}</h2>
      </div>
      <button class="primary-button" type="button" :disabled="saving" @click="saveConfig">
        {{ saving ? t('config.saving') : t('config.save') }}
      </button>
    </div>

    <p v-if="message" class="notice notice--success">{{ message }}</p>
    <p v-if="error" class="notice notice--error">{{ error }}</p>
    <p v-if="loading" class="notice">{{ t('config.loading') }}</p>

    <template v-else>
      <div class="form-grid">
        <section class="panel">
          <h3>{{ t('config.emailAndSend') }}</h3>
          <label class="field">
            <span>{{ t('config.recipient') }}</span>
            <input
              v-model="form.email.to"
              type="email"
              name="email_to"
              autocomplete="email"
              spellcheck="false"
              placeholder="tu@email.com"
            />
          </label>
          <label class="field field--inline">
            <input v-model="form.email.enabled" type="checkbox" name="email_enabled" />
            <span>{{ t('config.sendDaily') }}</span>
          </label>
          <label class="field">
            <span>{{ t('config.sendTime') }}</span>
            <input v-model="form.email.daily_send_time" type="time" name="daily_send_time" autocomplete="off" />
          </label>
          <label class="field">
            <span>{{ t('config.sendMode') }}</span>
            <select v-model="form.email.send_mode" name="send_mode" autocomplete="off">
              <option value="manual">{{ sendModeLabel('manual') }}</option>
              <option value="automatic">{{ sendModeLabel('automatic') }}</option>
              <option value="manual_and_automatic">{{ sendModeLabel('manual_and_automatic') }}</option>
            </select>
          </label>
          <p class="muted">
            SMTP:
            <strong>{{ form.meta.smtp_configured ? t('config.smtpConfigured') : t('config.smtpPending') }}</strong>
          </p>
        </section>

        <section class="panel">
          <h3>{{ t('config.sources') }}</h3>
          <div class="source-grid">
            <label v-for="(source, key) in form.sources" :key="key" class="source-card">
              <div class="field field--inline">
                <input v-model="source.enabled" type="checkbox" />
                <span>{{ key }}</span>
              </div>
              <label class="field">
                <span>{{ t('config.maxPages') }}</span>
                <input v-model.number="source.max_pages" type="number" min="1" />
              </label>
              <label class="field">
                <span>{{ t('config.maxResults') }}</span>
                <input v-model.number="source.max_results" type="number" min="1" />
              </label>
            </label>
          </div>
        </section>
      </div>

      <section class="panel">
        <div class="panel__header">
          <div>
            <h3>{{ t('config.independentSearches') }}</h3>
            <p class="muted">
              {{ t('config.independentSearchesHelp') }}
            </p>
          </div>
          <button class="secondary-button" type="button" @click="addSearch">{{ t('config.addSearch') }}</button>
        </div>

        <div class="search-config-layout">
          <aside class="search-tabs">
            <button
              v-for="(search, index) in form.searches"
              :key="search.id"
              class="search-tab"
              :class="{ 'search-tab--active': activeSearch?.id === search.id }"
              type="button"
              @click="selectSearch(search.id)"
            >
              <span class="search-tab__title">{{ search.label || t('config.searchDefaultName', { index: index + 1 }) }}</span>
              <span class="search-tab__meta">{{ search.key || `search_${index + 1}` }}</span>
            </button>
          </aside>

          <article v-if="activeSearch" class="search-config-card search-config-card--editor">
            <div class="search-config-card__header">
              <div>
                <h4>{{ activeSearch.label || t('common.newSearch') }}</h4>
                <p class="muted">{{ t('config.searchOwnRules') }}</p>
              </div>
              <button
                class="secondary-button secondary-button--danger"
                type="button"
                :disabled="form.searches.length <= 1"
                @click="removeSearch(form.searches.findIndex((search) => search.id === activeSearch.id))"
              >
                {{ t('common.remove') }}
              </button>
            </div>

            <div class="search-editor-grid">
              <label class="field">
                <span>{{ t('config.visibleName') }}</span>
                <input
                  v-model="activeSearch.label"
                  type="text"
                  :name="`${activeSearch.id}-label`"
                  autocomplete="off"
                  placeholder="Ej. French"
                  @blur="syncSearchKey(activeSearch, form.searches.findIndex((search) => search.id === activeSearch.id))"
                />
              </label>

              <label class="field">
                <span>{{ t('config.identifier') }}</span>
                <input
                  v-model="activeSearch.key"
                  type="text"
                  :name="`${activeSearch.id}-key`"
                  autocomplete="off"
                  placeholder="Ej. french"
                />
              </label>
            </div>

            <section class="search-section">
              <h5>{{ t('config.tagsKeywords') }}</h5>
              <div class="search-keyword-entry">
                <input
                  v-model="activeSearch.draftKeyword"
                  type="text"
                  :name="`${activeSearch.id}-draft-keyword`"
                  autocomplete="off"
                  placeholder="Ej. French lecturer"
                  @keydown.enter.prevent="addSearchListItem(activeSearch, 'keywords', 'draftKeyword')"
                />
                <button class="secondary-button" type="button" @click="addSearchListItem(activeSearch, 'keywords', 'draftKeyword')">
                  {{ t('common.add') }}
                </button>
              </div>
              <div v-if="activeSearch.keywords.length > 0" class="keyword-chip-list">
                <button
                  v-for="keyword in activeSearch.keywords"
                  :key="keyword"
                  class="keyword-chip"
                  type="button"
                  @click="removeSearchListItem(activeSearch, 'keywords', keyword)"
                >
                  {{ keyword }}
                  <span aria-hidden="true">×</span>
                </button>
              </div>
              <textarea
                :value="textAreaValue(activeSearch.keywords)"
                :name="`${activeSearch.id}-keywords`"
                autocomplete="off"
                rows="8"
                :placeholder="t('config.oneKeywordPerLine')"
                @input="activeSearch.keywords = listFromText($event.target.value)"
              />
            </section>

            <div class="search-rules-grid">
              <section class="search-section">
                <h5>{{ t('config.supportTerms') }}</h5>
                <div class="search-keyword-entry">
                  <input
                    v-model="activeSearch.draftPositiveSupport"
                    type="text"
                    :name="`${activeSearch.id}-draft-positive-support`"
                    autocomplete="off"
                    placeholder="Ej. lecturer"
                    @keydown.enter.prevent="addSearchListItem(activeSearch, 'positive_support', 'draftPositiveSupport')"
                  />
                  <button
                    class="secondary-button"
                    type="button"
                    @click="addSearchListItem(activeSearch, 'positive_support', 'draftPositiveSupport')"
                  >
                    {{ t('common.add') }}
                  </button>
                </div>
                <div v-if="activeSearch.positive_support.length > 0" class="keyword-chip-list">
                  <button
                    v-for="keyword in activeSearch.positive_support"
                    :key="keyword"
                    class="keyword-chip"
                    type="button"
                    @click="removeSearchListItem(activeSearch, 'positive_support', keyword)"
                  >
                    {{ keyword }}
                    <span aria-hidden="true">×</span>
                  </button>
                </div>
                <textarea
                  :value="textAreaValue(activeSearch.positive_support)"
                  :name="`${activeSearch.id}-positive-support`"
                  autocomplete="off"
                  rows="8"
                  :placeholder="t('config.supportHint')"
                  @input="activeSearch.positive_support = listFromText($event.target.value)"
                />
              </section>

              <section class="search-section">
                <h5>{{ t('config.exclusions') }}</h5>
                <div class="search-keyword-entry">
                  <input
                    v-model="activeSearch.draftExcluded"
                    type="text"
                    :name="`${activeSearch.id}-draft-excluded`"
                    autocomplete="off"
                    placeholder="Ej. kindergarten"
                    @keydown.enter.prevent="addSearchListItem(activeSearch, 'excluded', 'draftExcluded')"
                />
                <button class="secondary-button" type="button" @click="addSearchListItem(activeSearch, 'excluded', 'draftExcluded')">
                  {{ t('common.add') }}
                </button>
              </div>
                <div v-if="activeSearch.excluded.length > 0" class="keyword-chip-list">
                  <button
                    v-for="keyword in activeSearch.excluded"
                    :key="keyword"
                    class="keyword-chip"
                    type="button"
                    @click="removeSearchListItem(activeSearch, 'excluded', keyword)"
                  >
                    {{ keyword }}
                    <span aria-hidden="true">×</span>
                  </button>
                </div>
                <textarea
                  :value="textAreaValue(activeSearch.excluded)"
                  :name="`${activeSearch.id}-excluded`"
                  autocomplete="off"
                  rows="8"
                  :placeholder="t('config.exclusionsHint')"
                  @input="activeSearch.excluded = listFromText($event.target.value)"
                />
              </section>
            </div>

            <section class="search-section">
              <h5>{{ t('config.filteringRules') }}</h5>
              <div class="search-filters-grid">
                <label class="field">
                  <span>{{ t('config.minScore') }}</span>
                  <input v-model.number="activeSearch.filters.score_threshold" type="number" min="1" />
                </label>
                <label class="field">
                  <span>{{ t('config.maxAgeDays') }}</span>
                  <input v-model.number="activeSearch.filters.max_age_days" type="number" min="1" />
                </label>
                <label class="field field--inline field--toggle">
                  <input v-model="activeSearch.filters.discard_without_posted_date" type="checkbox" />
                  <span>{{ t('config.discardNoReliableDate') }}</span>
                </label>
              </div>
            </section>
          </article>
        </div>
      </section>
    </template>
  </section>
</template>
