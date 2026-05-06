<script setup>
import { computed, onMounted, shallowRef } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAppDataState } from './stores/appData'
import { useConfigState } from './stores/config'
import { useI18nState } from './stores/i18n'

const route = useRoute()
const { loadAll, loadingAppData, appDataError: loadError } = useAppDataState()
const { searches, loadConfig } = useConfigState()
const { t, locale, setLocale, languageOptions } = useI18nState()

const loadAttempted = shallowRef(false)

const navigation = computed(() => [
  { label: t('app.nav.dashboard'), to: '/' },
  { label: t('app.nav.config'), to: '/configuracion' },
  {
    label: t('app.nav.results'),
    to: '/resultados',
    children: [
      ...searches.value.map((search) => ({
        label: search.label,
        to: `/resultados/${search.key}`,
      })),
      { label: t('app.nav.publishers'), to: '/resultados/agencias' },
    ],
  },
  { label: t('app.nav.logs'), to: '/logs' },
])

function isActive(item) {
  if (item.to === '/') {
    return route.path === item.to
  }

  return route.path === item.to || route.path.startsWith(`${item.to}/`)
}

onMounted(async () => {
  try {
    await Promise.allSettled([
      loadConfig(),
      loadAll(),
    ])
  } catch {
    // errors are captured in store state
  } finally {
    loadAttempted.value = true
  }
})

function onChangeLocale(event) {
  setLocale(event.target.value)
}
</script>

<template>
  <div class="app-shell">
    <aside class="app-sidebar">
      <div>
        <p class="eyebrow">{{ t('app.eyebrow') }}</p>
        <h1>{{ t('app.title') }}</h1>
        <p class="muted">{{ t('app.subtitle') }}</p>
      </div>

      <div class="field app-language-field">
        <label for="app-language-select">{{ t('app.language') }}</label>
        <select
          id="app-language-select"
          name="app_language"
          :value="locale"
          autocomplete="off"
          @change="onChangeLocale"
        >
          <option v-for="option in languageOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </div>

      <nav class="nav-list">
        <div v-for="item in navigation" :key="item.to" class="nav-group">
          <RouterLink
            :to="item.to"
            class="nav-link"
            :class="{ 'nav-link--active': isActive(item) }"
          >
            {{ item.label }}
          </RouterLink>

          <div v-if="item.children?.length" class="nav-children">
            <RouterLink
              v-for="child in item.children"
              :key="child.to"
              :to="child.to"
              class="nav-link nav-link--sub"
              :class="{ 'nav-link--active': route.path === child.to }"
            >
              {{ child.label }}
            </RouterLink>
          </div>
        </div>
      </nav>
    </aside>

    <main class="app-main">
      <div v-if="!loadAttempted || loadingAppData" class="global-loading">
        <div class="global-loading__spinner" aria-label="Cargando datos..."></div>
        <p class="global-loading__text">{{ t('dashboard.loading') }}</p>
      </div>

      <div v-if="loadAttempted && loadError" class="global-error">
        <p class="notice notice--error">{{ loadError }}</p>
      </div>

      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.global-loading {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 16px;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(4px);
  animation: fadeIn 0.2s ease-out;
}

.global-loading__spinner {
  width: 32px;
  height: 32px;
  border: 3px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

.global-loading__text {
  margin: 0;
  font-size: 0.875rem;
  color: #64748b;
}

.global-error {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 9999;
  padding: 12px 16px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(4px);
  animation: fadeIn 0.2s ease-out;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-8px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
