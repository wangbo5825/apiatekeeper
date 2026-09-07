import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import { authState, refreshStatus } from './api'
import Login from './views/Login.vue'
import Install from './views/Install.vue'
import Dashboard from './views/Dashboard.vue'
import Apps from './views/Apps.vue'
import AppDetail from './views/AppDetail.vue'

const router = createRouter({
  history: createWebHistory('/admin/'),
  routes: [
    { path: '/login', component: Login, meta: { public: true } },
    { path: '/install', component: Install, meta: { public: true } },
    { path: '/', component: Dashboard },
    { path: '/apps', component: Apps },
    { path: '/apps/:appId/:sub?', component: AppDetail },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  if (!authState.loaded) await refreshStatus()
  if (to.path === '/install') {
    return authState.installed ? (authState.authed ? '/' : '/login') : true
  }
  if (to.path === '/login') {
    return authState.installed ? (authState.authed ? '/' : true) : '/install'
  }
  if (!authState.installed) return '/install'
  if (!authState.authed) return '/login'
  return true
})

createApp(App).use(router).mount('#app')
