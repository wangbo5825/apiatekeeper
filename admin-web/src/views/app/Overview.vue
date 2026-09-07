<template>
  <div>
    <div class="card">
      <div class="grid">
        <div class="stat"><div class="lb">API 数（自动登记）</div><b>{{ apis.length }}</b></div>
        <div class="stat"><div class="lb">客户端</div><b>{{ clients.length }}</b></div>
        <div class="stat"><div class="lb">累计请求</div><b>{{ fmt(totalRequests) }}</b></div>
        <div class="stat"><div class="lb">拦截（403/429）</div><b>{{ fmt(totalDenied) }}</b></div>
      </div>
    </div>
    <div class="card">
      <h2>应用请求趋势</h2>
      <div class="form-row" style="justify-content:flex-end; margin-bottom:0">
        <button class="primary sm" :disabled="busy" @click="aggregate">运行聚合</button>
      </div>
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
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { api } from '../../api'

const props = defineProps({ appId: { type: String, required: true } })
const apis = ref([])
const clients = ref([])
const stats = ref([])
const busy = ref(false)
let timer = null

const totalRequests = computed(() => stats.value.reduce((s, b) => s + Number(b.requests || 0), 0))
const totalErrors = computed(() => stats.value.reduce((s, b) => s + Number(b.errors || 0), 0))
const totalDenied = computed(() => stats.value.reduce((s, b) => s + Number(b.denied || 0), 0))
const trend = computed(() => [...stats.value].sort((a, b) => String(b.bucket).localeCompare(String(a.bucket))).slice(0, 24))

function fmt(n) {
  return Number(n || 0).toLocaleString()
}

async function load() {
  const c = api.app(props.appId)
  const [a, cl, st] = await Promise.all([c.get('apis'), c.get('clients'), c.get('stats')])
  apis.value = a
  clients.value = cl
  stats.value = st
}

async function aggregate() {
  busy.value = true
  try {
    await api.app(props.appId).create('stats', { bucket: 'hour' })
    stats.value = await api.app(props.appId).get('stats')
  } finally {
    busy.value = false
  }
}

watch(() => props.appId, () => { load(); clearInterval(timer); timer = setInterval(load, 60000) })
onMounted(() => {
  load()
  timer = setInterval(load, 60000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
