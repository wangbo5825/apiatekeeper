<template>
  <div>
    <div class="card">
      <h2>缓存 PURGE</h2>
      <div class="form-row">
        <input v-model="purgeKey" placeholder="缓存键 / URL（留空=全部）" style="width:280px" />
        <button class="primary sm" :disabled="busy" @click="purge">PURGE</button>
      </div>
      <div v-if="purgeMsg" class="alert-ok" style="margin-bottom:0">{{ purgeMsg }}</div>
      <div v-if="purgeErr" class="alert-err" style="margin-bottom:0">{{ purgeErr }}</div>
    </div>

    <div class="card">
      <h2>记录链路</h2>
      <table>
        <thead><tr><th>项</th><th>当前值</th><th>说明</th></tr></thead>
        <tbody>
          <tr>
            <td>日志文件</td>
            <td class="mono">{{ status.log ? status.log.dir + '/' + status.log.today_file : '—' }}</td>
            <td>今日 {{ status.log ? status.log.lines : 0 }} 行 / {{ fmtBytes(status.log ? status.log.bytes : 0) }}，最后写入 {{ status.log ? status.log.last_write : '—' }}</td>
          </tr>
          <tr>
            <td>导入进程</td>
            <td><span class="badge tag-ok">运行中</span></td>
            <td>独立 PHP CLI（cron 每分钟），导入 + 自动登记 + 聚合 + 保留清理</td>
          </tr>
          <tr>
            <td>最近导入（聚合水位）</td>
            <td class="mono">{{ status.import ? status.import.processed_until : '—' }}</td>
            <td>滞后 {{ status.import ? status.import.lag_seconds : '—' }} 秒</td>
          </tr>
          <tr>
            <td>数据量</td>
            <td>{{ status.counts ? `${status.counts.request_logs} 请求 / ${status.counts.apis} API / ${status.counts.apps} 应用` : '—' }}</td>
            <td>request_logs / apis / apps 计数</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>修改密码</h2>
      <div class="form-row">
        <input v-model="pw.old" type="password" autocomplete="current-password" placeholder="原密码" style="width:180px" />
        <input v-model="pw.next" type="password" autocomplete="new-password" placeholder="新密码（至少 8 位）" style="width:200px" />
        <input v-model="pw.confirm" type="password" autocomplete="new-password" placeholder="确认新密码" style="width:180px" />
        <button class="primary" :disabled="busy" @click="changePassword">保存新密码</button>
      </div>
      <div v-if="pwMsg" class="alert-ok" style="margin-bottom:0">{{ pwMsg }}</div>
      <div v-if="pwErr" class="alert-err" style="margin-bottom:0">{{ pwErr }}</div>
    </div>

    <div class="card">
      <h2>存储分层</h2>
      <table>
        <thead><tr><th>层</th><th>用途</th><th>说明</th></tr></thead>
        <tbody>
          <tr><td>APCu（内存）</td><td>应用规则 / 黑名单 / 令牌快照、关联变量、限流计数、请求上下文</td><td>热路径唯一状态源，请求路径不触 SQLite</td></tr>
          <tr><td>SQLite</td><td>管理端、配置存储（apps / upstreams / rules / apis / variables / blacklist / tokens）、后台统计</td><td>仅管理与配置用途，异步导入写入</td></tr>
          <tr><td>Redis</td><td>—</td><td><span class="badge tag-open">暂缓</span> 多实例部署时再评估</td></tr>
          <tr><td>Souin</td><td>HTTP 缓存</td><td>缓存策略由规则注入 Cache-Control</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '../api'

const purgeKey = ref('')
const busy = ref(false)
const purgeMsg = ref('')
const purgeErr = ref('')
const status = ref(null)
const pwMsg = ref('')
const pwErr = ref('')
const pw = reactive({ old: '', next: '', confirm: '' })

function fmtBytes(n) {
  if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB'
  if (n >= 1024) return (n / 1024).toFixed(1) + ' KB'
  return n + ' B'
}

async function purge() {
  busy.value = true
  purgeMsg.value = ''
  purgeErr.value = ''
  try {
    const r = await api.purge(purgeKey.value.trim())
    purgeMsg.value = `已提交 PURGE：${r.purged}`
  } catch (e) {
    purgeErr.value = e.message
  } finally {
    busy.value = false
  }
}

async function loadStatus() {
  try {
    status.value = await api.systemStatus()
  } catch (e) { /* 忽略 */ }
}

async function changePassword() {
  pwMsg.value = ''
  pwErr.value = ''
  if (!pw.old || !pw.next || pw.next.length < 8) {
    pwErr.value = '请输入原密码，新密码至少 8 位'
    return
  }
  if (pw.next !== pw.confirm) {
    pwErr.value = '两次输入的新密码不一致'
    return
  }
  busy.value = true
  try {
    await api.changePassword(pw.old, pw.next)
    pwMsg.value = '密码已修改（其它登录会话已失效）'
    pw.old = ''
    pw.next = ''
    pw.confirm = ''
  } catch (e) {
    pwErr.value = e.message
  } finally {
    busy.value = false
  }
}

onMounted(loadStatus)
</script>
