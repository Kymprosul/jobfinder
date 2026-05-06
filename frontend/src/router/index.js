import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'dashboard', component: () => import('../views/DashboardView.vue') },
    { path: '/configuracion', name: 'config', component: () => import('../views/ConfigView.vue') },
    { path: '/resultados', name: 'results', component: () => import('../views/ResultsView.vue') },
    { path: '/resultados/agencias', name: 'results-publishers', component: () => import('../views/ResultsPublishersView.vue') },
    { path: '/resultados/:searchKey', name: 'results-search', component: () => import('../views/ResultsSearchView.vue') },
    { path: '/logs', name: 'logs', component: () => import('../views/LogsView.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

export default router
