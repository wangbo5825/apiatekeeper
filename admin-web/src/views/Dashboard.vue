<template>
  <div>
    <div class="tabs">
      <button class="tab" :class="{ active: tab === 'overview' }" @click="tab = 'overview'">概览</button>
      <button class="tab" :class="{ active: tab === 'metrics' }" @click="tab = 'metrics'">监控</button>
      <button class="tab" :class="{ active: tab === 'system' }" @click="tab = 'system'">系统</button>
    </div>

    <template v-if="tab === 'overview'">
      <div class="card">
        <h2>全局概览</h2>
        <div class="grid">
          <div class="stat"><div class="lb">应用数</div><b>{{ apps.length }}</b></div>
          <div class="stat"><div class="lb">API 总数</div><b>{{ totalApis }}</b></div>
          <div class="stat"><div class="lb">客户端</div><b>{{ totalClients }}</b></div>
          <div class="stat"><div class="lb">累计请求</div><b>{{ fmt(totalRequests) }}</b></div>
          <div class="stat"><div class="lb">错误 4xx/5xx</div><b>{{ fmt(totalErrors) }}</b></div>
          <div class="stat"><div class="lb">拦截（403/429）</div><b>{{ fmt(totalDenied) }}</b></div>
        </div>
        <div class="form-row" style="justify-content:flex-end; margin-top:12px; margin-bottom:0">
          <button class="primary sm" :disabled="busy" @click="aggregateAll">运行聚合</button>
        </div>
      </div>

      <div class="card">
        <h2>应用一览</h2>
        <table>
          <thead>
            <tr><th>应用</th><th>base_path</th><th>缺省放行</th><th>自动加入</th><th>状态</th><th>累计请求</th><th>操作</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in appRows" :key="row.app.id">
              <td>{{ row.app.name }}</td>
              <td class="mono">{{ row.app.base_path }}</td>
              <td><span class="badge" :class="row.app.default_allow ? 'tag-ok' : 'tag-down'">{{ row.app.default_allow ? '放行' : '拒绝' }}</span></td>
              <td><span class="badge" :class="row.app.auto_add_rules ? 'tag-info' : 'tag-warn'">{{ row.app.auto_add_rules ? '开' : '关' }}</span></td>
              <td><span class="badge" :class="row.app.status ? 'tag-ok' : 'tag-down'">{{ row.app.status ? '启用' : '停用' }}</span></td>
              <td>{{ fmt(row.requests) }}</td>
              <td><button class="sm primary" @click="goApp(row.app.id)">进入详情</button></td>
            </tr>
            <tr v-if="!appRows.length"><td colspan="7"><div class="empty">暂无应用，请先创建</div></td></tr>
          </tbody>
        </table>
        <div class="hint">未命中任何应用 → 404。各应用缺省配置独立。</div>
      </div>

      <div class="card">
        <h2>请求趋势（全局，按小时桶）</h2>
        <table>
          <thead><tr><th>时间</th><th>请求数</th><th>错误</th><th>拦截</th></tr></thead>
          <tbody>
            <tr v-for="t in trend" :key="t.bucket">
              <td>{{ t.bucket }}</td>
              <td>{{ fmt(t.requests) }}</td>
              <td>{{ fmt(t.errors) }}</td>
              <td>{{ fmt(t.denied) }}</td>
            </tr>
            <tr v-if="!trend.length"><td colspan="4"><div class="empty">暂无数据，点击"运行聚合"生成</div></td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <Metrics v-else-if="tab === 'metrics'" />
    <System v-else />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import Metrics from './Metrics.vue'
import System from './System.vue'

const router = useRouter()
const tab = ref('overview')
const apps = ref([])
const statsByApp = ref({})
const busy = ref(false)
let timer = null

const appRows = computed(() => apps.value.map((app) => {
  const stats = statsByApp.value[app.id] || []
  return {
    app,
    apis: 0,
    clients: 0,
    requests: stats.reduce((s, b) => s + Number(b.requests || 0), 0),
    errors: stats.reduce((s, b) => s + Number(b.errors || 0), 0),
    denied: stats.reduce((s, b) => s + Number(b.denied || 0), 0),
  }
}))

const totalApis = computed(() => appRows.value.reduce((s, r) => s + r.apis, 0))
const totalClients = computed(() => appRows.value.reduce((s, r) => s + r.clients, 0))
const totalRequests = computed(() => appRows.value.reduce((s, r) => s + r.requests, 0))
const totalErrors = computed(() => appRows.value.reduce((s, r) => s + r.errors, 0))
const totalDenied = computed(() => appRows.value.reduce((s, r) => s + r.denied, 0))

const trend = computed(() => {
  const map = {}
  Object.values(statsByApp.value).forEach((buckets) => {
    buckets.forEach((b) => {
      if (!map[b.bucket]) map[b.bucket] = { bucket: b.bucket, requests: 0, errors: 0, denied: 0 }
      map[b.bucket].requests += Number(b.requests || 0)
      map[b.bucket].errors += Number(b.errors || 0)
      map[b.bucket].denied += Number(b.denied || 0)
    })
  })
  return Object.values(map).sort((a, b) => String(b.bucket).localeCompare(String(a.bucket))).slice(0, 24)
})

function fmt(n) {
  return Number(n || 0).toLocaleString()
}

function goApp(id) {
  router.push(`/apps/${id}/overview`)
}

async function load() {
  apps.value = await api.apps()
  await Promise.all(apps.value.map(async (app) => {
    const c = api.app(app.id)
    const [stats, apis, clients] = await Promise.all([
      c.get('stats'),
      c.get('apis'),
      c.get('clients'),
    ])
    statsByApp.value[app.id] = stats
    const row = appRows.value.find((r) => r.app.id === app.id)
    if (row) {
      row.apis = Array.isArray(apis) ? apis.length : 0
      row.clients = Array.isArray(clients) ? clients.length : 0
    }
  }))
}

async function aggregateAll() {
  busy.value = true
  try {
    await Promise.all(apps.value.map((app) => api.app(app.id).create('stats', { bucket: 'hour' })))
    statsByApp.value = {}
    await load()
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  load()
  timer = setInterval(load, 60000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<style scoped>
.tabs { display: flex; gap: 6px; margin-bottom: 16px; }
.tab { padding: 7px 18px; font-size: 14px; border: 1px solid #d1d5db; background: #fff; border-radius: 8px; cursor: pointer; }
.tab.active { background: #2563eb; color: #fff; border-color: #2563eb; }
</style>
