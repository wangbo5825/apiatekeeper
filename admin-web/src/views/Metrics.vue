<template>
  <div>
    <div class="card">
      <h2>Prometheus 指标（全局，含 app 标签）</h2>
      <div class="form-row">
        <span>抓取端点</span><code>/admin/api/metrics</code>
        <button class="primary sm" :disabled="busy" @click="fetchMetrics">立即抓取测试</button>
      </div>
      <table>
        <thead><tr><th>指标名</th><th>类型</th><th>标签</th><th>说明</th></tr></thead>
        <tbody>
          <tr v-for="m in metricDefs" :key="m.name">
            <td class="mono">{{ m.name }}</td>
            <td>{{ m.type }}</td>
            <td>{{ m.labels }}</td>
            <td>{{ m.desc }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="samples.length" class="card">
      <h2>当前采样</h2>
      <table>
        <thead><tr><th>指标</th><th>标签</th><th>值</th></tr></thead>
        <tbody>
          <tr v-for="s in samples.slice(0, 40)" :key="s.line">
            <td class="mono">{{ s.name }}</td>
            <td class="mono">{{ s.labels }}</td>
            <td>{{ s.value }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="raw" class="card">
      <h3>原始输出</h3>
      <pre class="raw">{{ raw }}</pre>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { api } from '../api'

const metricDefs = [
  { name: 'gatekeeper_http_requests_total', type: 'Counter', labels: 'app, api, client, status', desc: '请求量（按应用隔离）' },
  { name: 'gatekeeper_http_duration_seconds', type: 'Histogram', labels: 'app, api', desc: '延迟（P50/P95/P99）' },
  { name: 'gatekeeper_http_errors_total', type: 'Counter', labels: 'app, code', desc: '4xx/5xx' },
  { name: 'gatekeeper_denied_total', type: 'Counter', labels: 'app, reason', desc: '403/429、未命中应用 404' },
  { name: 'gatekeeper_auto_added_apis_total', type: 'Counter', labels: 'app', desc: '自动加入 API 数量' },
  { name: 'gatekeeper_upstream_healthy', type: 'Gauge', labels: 'app, group, target', desc: '上游健康状态（0/1）' },
  { name: 'gatekeeper_log_import_lag_seconds', type: 'Gauge', labels: '-', desc: '日志导入滞后' },
]

const busy = ref(false)
const raw = ref('')
const samples = ref([])

async function fetchMetrics() {
  busy.value = true
  try {
    raw.value = await api.metrics()
    const lines = String(raw.value).split('\n')
    samples.value = lines
      .filter((l) => l && !l.startsWith('#'))
      .map((l) => {
        const m = l.match(/^([a-zA-Z_:]+)(\{[^}]*\})?\s+([0-9.eE+-]+)/)
        if (!m) return null
        return { line: l, name: m[1], labels: (m[2] || '').replace(/"/g, ''), value: m[3] }
      })
      .filter(Boolean)
  } catch (e) {
    alert(e.message)
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.raw { background: #0f172a; color: #cbd5e1; padding: 14px; border-radius: 8px; font-size: 12px; overflow-x: auto; max-height: 320px; }
</style>
