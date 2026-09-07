<template>
  <div v-if="route.meta.public" class="standalone">
    <RouterView />
  </div>
  <div v-else class="layout">
    <aside class="sidebar">
      <div class="brand">
        <h1>ApiGateKeeper</h1>
        <div class="ver">管理端 v0.4 · 零配置可用</div>
      </div>

      <nav>
        <RouterLink to="/">仪表盘</RouterLink>

        <div class="nav-group">应用</div>
        <div class="app-picker">
          <select :value="currentAppId || ''" @change="onAppSelect">
            <option value="">请选择应用</option>
            <option v-for="a in apps" :key="a.id" :value="String(a.id)">
              {{ a.name }} {{ a.base_path }}
            </option>
            <option value="new">＋ 新建应用…</option>
          </select>
        </div>
        <RouterLink :to="appSub('overview')">概览</RouterLink>
        <RouterLink :to="appSub('apis')">API 列表</RouterLink>
        <RouterLink :to="appSub('clients')">客户端</RouterLink>

        <div class="nav-group">治理</div>
        <RouterLink :to="appSub('rules')">规则</RouterLink>
        <RouterLink :to="appSub('variables')">变量</RouterLink>

        <div class="nav-group">配置</div>
        <RouterLink :to="appSub('settings')">缺省设置</RouterLink>
        <RouterLink :to="appSub('upstreams')">上游</RouterLink>

        <div class="nav-group">安全</div>
        <RouterLink :to="appSub('blacklist')">黑名单</RouterLink>
      </nav>

      <div class="foot">
        <button class="logout" @click="logout">退出登录</button>
      </div>
    </aside>

    <main class="content">
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, logout as apiLogout } from './api'

const route = useRoute()
const router = useRouter()
const apps = ref([])

const currentAppId = computed(() => route.params.appId ? String(route.params.appId) : '')

function appSub(sub) {
  return currentAppId.value ? `/apps/${currentAppId.value}/${sub}` : '/apps'
}

async function loadApps() {
  try {
    apps.value = await api.apps()
  } catch (e) {
    if (e.unauthorized) router.push('/login')
  }
}

function onAppSelect(e) {
  const v = e.target.value
  if (!v || v === 'new') {
    router.push('/apps')
    return
  }
  router.push(`/apps/${v}/overview`)
}

async function logout() {
  await apiLogout()
  router.push('/login')
}

watch(() => route.params.appId, loadApps)
onMounted(loadApps)
</script>

<style>
* { box-sizing: border-box; margin: 0; }
body { font-family: system-ui, "Microsoft YaHei", sans-serif; background: #f5f6f8; color: #222; }
.layout { display: flex; min-height: 100vh; }
.sidebar { width: 230px; background: #1f2937; color: #fff; padding: 14px 12px; display: flex; flex-direction: column; gap: 10px; flex-shrink: 0; position: sticky; top: 0; height: 100vh; }
.brand h1 { font-size: 17px; padding: 0 8px; line-height: 1.4; }
.brand .ver { font-size: 11px; color: #9ca3af; padding: 0 8px; margin-top: 2px; }
.sidebar nav { display: flex; flex-direction: column; gap: 2px; overflow-y: auto; scrollbar-width: none; }
.sidebar nav::-webkit-scrollbar { display: none; }
.sidebar nav a { color: #cbd5e1; text-decoration: none; padding: 7px 10px; border-radius: 6px; font-size: 13px; display: block; }
.sidebar nav a:hover { background: #2d3748; }
.sidebar nav a.router-link-active { background: #374151; color: #fff; }
.nav-group { font-size: 10px; color: #6b7280; padding: 8px 10px 2px; letter-spacing: .05em; }
.app-picker select { width: 100%; padding: 6px 8px; border-radius: 6px; border: none; font-size: 12px; background: #374151; color: #fff; }
.foot { margin-top: auto; display: flex; flex-direction: column; gap: 8px; }
.foot .logout { padding: 6px; border: 1px solid #4b5563; background: transparent; color: #cbd5e1; border-radius: 6px; cursor: pointer; font-size: 12px; }
.foot .logout:hover { background: #374151; color: #fff; }
.content { flex: 1; padding: 22px 26px; min-width: 0; }

.card { background: #fff; border-radius: 8px; padding: 16px 18px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.card h2 { margin-bottom: 12px; font-size: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.card h3 { font-size: 14px; margin-bottom: 10px; color: #374151; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #edf0f3; vertical-align: middle; }
th { color: #6b7280; font-weight: 600; white-space: nowrap; }
tr:last-child td { border-bottom: none; }
.badge { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 12px; background: #e5e7eb; color: #374151; }
.tag-new { background: #dbeafe; color: #1e40af; }
.tag-open { background: #fef3c7; color: #92400e; }
.tag-ok { background: #dcfce7; color: #166534; }
.tag-warn { background: #ffedd5; color: #9a3412; }
.tag-down { background: #fee2e2; color: #991b1b; }
.tag-info { background: #e0e7ff; color: #3730a3; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
.stat { background: #fff; border-radius: 8px; padding: 14px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.stat .lb { color: #6b7280; font-size: 12px; }
.stat b { font-size: 22px; display: block; margin-top: 4px; }
.stat .sub { font-size: 12px; color: #9ca3af; margin-top: 2px; }
button { padding: 7px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; font-size: 13px; }
button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
button.danger { background: #fff; color: #dc2626; border-color: #fecaca; }
button.sm { padding: 3px 10px; font-size: 12px; }
button:disabled { opacity: .55; cursor: not-allowed; }
input, select, textarea { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; }
input[type=checkbox] { accent-color: #2563eb; }
.form-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
.form-row label { font-size: 13px; color: #374151; display: flex; align-items: center; gap: 5px; }
.field-label { font-size: 12px; color: #6b7280; margin: 8px 0 4px; }
.hint { font-size: 12px; color: #9ca3af; margin-top: 6px; line-height: 1.7; }
code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px; font-size: 12px; color: #111827; }
.mono { font-family: Consolas, monospace; font-size: 12px; }
.alert-line { border-left: 3px solid #f59e0b; background: #fffbeb; padding: 8px 12px; border-radius: 4px; font-size: 13px; color: #92400e; margin-bottom: 12px; }
.alert-err { border-left: 3px solid #ef4444; background: #fef2f2; padding: 8px 12px; border-radius: 4px; font-size: 13px; color: #991b1b; margin-bottom: 12px; }
.alert-ok { border-left: 3px solid #22c55e; background: #f0fdf4; padding: 8px 12px; border-radius: 4px; font-size: 13px; color: #166534; margin-bottom: 12px; }
.switch-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px dashed #e5e7eb; }
.switch-row:last-child { border-bottom: none; }
.switch-row b { font-size: 13px; display: block; }
.switch-row .d { font-size: 12px; color: #6b7280; }
.switch-row input { width: 36px; height: 20px; margin-left: auto; }
.app-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; background: #fff; border-radius: 8px; padding: 14px 18px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.app-head h2 { font-size: 17px; }
.app-head .meta { color: #6b7280; font-size: 13px; }
.modal-mask { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: flex; align-items: center; justify-content: center; z-index: 50; }
.modal { background: #fff; border-radius: 10px; padding: 22px; width: 560px; max-width: 92vw; box-shadow: 0 10px 30px rgba(0,0,0,.25); }
.modal h2 { font-size: 16px; margin-bottom: 14px; }
.empty { color: #9ca3af; text-align: center; padding: 24px; font-size: 13px; }
@media (max-width: 900px) { .sidebar { width: 190px; } }
</style>
