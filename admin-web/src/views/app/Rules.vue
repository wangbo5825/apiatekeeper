<template>
  <div>
    <div class="card">
      <h2>规则列表（应用级 + API 级）</h2>
      <table>
        <thead>
          <tr><th>作用对象</th><th>名称</th><th>API 模式</th><th>类型</th><th>阶段</th><th>参数摘要</th><th>优先级</th><th>启用</th><th>操作</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in list" :key="r.id">
            <td><span class="badge" :class="r.scope === 'app' ? 'tag-info' : ''">{{ r.scope === 'app' ? '应用级' : 'API 级' }}</span></td>
            <td>
              <div>{{ r.name }}</div>
              <div v-if="r.description" class="rule-desc">{{ r.description }}</div>
            </td>
            <td class="mono">{{ r.api_pattern || '*' }}</td>
            <td><span class="badge">{{ r.action_type }}</span></td>
            <td><span class="badge" :class="r.phase === 'filter' ? 'tag-new' : ''">{{ r.phase }}</span></td>
            <td class="mono">{{ paramsSummary(r) }}</td>
            <td>{{ r.priority }}</td>
            <td>{{ r.enabled ? '是' : '否' }}</td>
            <td>
              <button class="sm" @click="openEdit(r)">编辑</button>
              <button class="sm danger" @click="remove(r)">删除</button>
            </td>
          </tr>
          <tr v-if="!list.length"><td colspan="9"><div class="empty">暂无规则</div></td></tr>
        </tbody>
      </table>
      <div class="hint">access 阶段做请求控制；filter 阶段做响应加工（response_transform，仅 buffer 模式）。</div>
    </div>

    <div class="card">
      <h2>{{ editing ? '编辑规则' : '新建规则' }}</h2>
      <div class="form-row">
        <input v-model="form.name" placeholder="规则名称" style="width:160px" />
        <input v-model="form.description" placeholder="规则描述（可选）" style="flex:1; min-width:200px" />
      </div>
      <div class="form-row">
        <label>作用对象
          <select v-model="form.scope">
            <option value="app">应用级</option>
            <option value="api">API 级</option>
          </select>
        </label>
        <label>API 模式 <input v-model="form.api_pattern" placeholder="GET /orders/{id} 或 *" style="width:180px" /></label>
        <label>客户端 <input v-model="form.client_pattern" placeholder="*" style="width:90px" /></label>
        <label>优先级 <input v-model.number="form.priority" type="number" style="width:70px" /></label>
        <label><input v-model="form.enabled" type="checkbox" /> 启用</label>
      </div>
      <div class="form-row">
        <label>规则类型
          <select v-model="form.action_type" @change="onTypeChange">
            <option v-for="t in actionTypes" :key="t" :value="t">{{ t }}</option>
          </select>
        </label>
      </div>

      <!-- stats -->
      <div v-if="form.action_type === 'stats'" class="field-block">
        <div class="field-label">统计计数</div>
        <div class="form-row">
          <label>维度（逗号分隔）<input v-model="p.stats.dimensions" placeholder="req.ip, req.userid" style="width:220px" /></label>
          <label>写入变量 <input v-model="p.stats.target" placeholder="visit_count" style="width:130px" /></label>
          <label>TTL（秒）<input v-model.number="p.stats.ttl" type="number" style="width:90px" /></label>
        </div>
      </div>

      <!-- cache -->
      <div v-if="form.action_type === 'cache'" class="field-block">
        <div class="field-label">缓存</div>
        <div class="form-row">
          <label>模式
            <select v-model="p.cache.mode">
              <option value="force">force 强制缓存</option>
              <option value="no-cache">no-cache 不缓存</option>
              <option value="auto">auto 自动（遵循协议）</option>
            </select>
          </label>
          <label>TTL（秒）<input v-model.number="p.cache.ttl" type="number" style="width:90px" /></label>
          <label>缓存键 <input v-model="p.cache.keys" placeholder="req.url" style="width:180px" /></label>
        </div>
      </div>

      <!-- rate_limit -->
      <div v-if="form.action_type === 'rate_limit'" class="field-block">
        <div class="field-label">速率限制</div>
        <div class="form-row">
          <label>窗口（秒）<input v-model.number="p.rl.window" type="number" style="width:80px" /></label>
          <label>阈值 <input v-model.number="p.rl.limit" type="number" style="width:80px" /></label>
          <label>突发 <input v-model.number="p.rl.burst" type="number" style="width:80px" /></label>
        </div>
        <div class="form-row">
          <label>维度（逗号分隔）<input v-model="p.rl.keys" placeholder="req.ip, req.userid" style="width:240px" /></label>
          <label><input v-model="p.rl.auto_blacklist" type="checkbox" /> 超限自动拉黑</label>
          <label>拉黑时长 <input v-model.number="p.rl.auto_blacklist_duration" type="number" style="width:90px" /></label>
        </div>
      </div>

      <!-- auth -->
      <div v-if="form.action_type === 'auth'" class="field-block">
        <div class="field-label">认证（应用内令牌 / JWT）</div>
        <div class="form-row">
          <label>类型
            <select v-model="p.auth.type">
              <option value="jwt">JWT</option>
              <option value="token">不透明令牌</option>
            </select>
          </label>
          <label>密钥 <input v-model="p.auth.secret" placeholder="HS256 密钥" style="width:180px" /></label>
          <label>issuer <input v-model="p.auth.issuer" placeholder="gatekeeper" style="width:120px" /></label>
          <label>audience <input v-model="p.auth.audience" placeholder="可选" style="width:110px" /></label>
        </div>
      </div>

      <!-- save_variable -->
      <div v-if="form.action_type === 'save_variable'" class="field-block">
        <div class="field-label">保存变量（按 key 存储，目标变量须已声明）</div>
        <div class="form-row">
          <label>变量名 <input v-model="p.sv.name" placeholder="last_url" style="width:120px" /></label>
          <label>key 维度 <input v-model="p.sv.key" placeholder="req.ip" style="width:120px" /></label>
          <label>取值来源 <input v-model="p.sv.from" placeholder="req.url" style="width:200px" /></label>
          <label>TTL（秒）<input v-model.number="p.sv.ttl" type="number" style="width:90px" /></label>
        </div>
      </div>

      <!-- change_target -->
      <div v-if="form.action_type === 'change_target'" class="field-block">
        <div class="field-label">改变请求目标</div>
        <div class="form-row">
          <label>目标上游组 <input v-model="p.ct.upstream" placeholder="备用组名称" style="width:160px" /></label>
          <label>路径改写 <input v-model="p.ct.rewrite_path" placeholder="strip:/v1 或 replace:/old:/new" style="width:200px" /></label>
          <label><input v-model="p.ct.strip_prefix" type="checkbox" /> 剥离 base_path</label>
        </div>
        <div class="form-row">
          <label>设置请求头（JSON）<input v-model="p.ct.set_headers" placeholder='{"X-App":"orders"}' style="width:240px" /></label>
        </div>
      </div>

      <!-- blacklist -->
      <div v-if="form.action_type === 'blacklist'" class="field-block">
        <div class="field-label">黑名单维度（数据在"黑名单"页维护）</div>
        <div class="form-row">
          <label><input v-model="p.bl.ip" type="checkbox" /> req.ip</label>
          <label><input v-model="p.bl.userid" type="checkbox" /> req.userid</label>
          <label><input v-model="p.bl.apikey" type="checkbox" /> req.apikey</label>
          <label>自定义维度 <input v-model="p.bl.custom" placeholder="req.query:phone" style="width:160px" /></label>
          <label>原因 <input v-model="p.bl.reason" placeholder="可选" style="width:140px" /></label>
        </div>
      </div>

      <!-- variable_check -->
      <div v-if="form.action_type === 'variable_check'" class="field-block">
        <div class="field-label">变量许可</div>
        <div class="form-row">
          <label>变量名 <input v-model="p.vc.name" placeholder="code" style="width:120px" /></label>
          <label>要求值（留空=存在即可）<input v-model="p.vc.require_value" style="width:140px" /></label>
          <label>最小剩余 TTL <input v-model.number="p.vc.min_ttl" type="number" style="width:90px" /></label>
        </div>
      </div>

      <!-- response_transform -->
      <div v-if="form.action_type === 'response_transform'" class="field-block">
        <div class="field-label">响应加工（filter 阶段，仅 buffer 模式）</div>
        <div class="form-row">
          <label>操作
            <select v-model="p.rt.op">
              <option value="replace">replace 替换 JSON 属性值</option>
              <option value="save_variable">save_variable 保存属性到变量</option>
              <option value="convert">convert JSON ↔ XML 转换</option>
            </select>
          </label>
          <template v-if="p.rt.op !== 'convert'">
            <label>JSON 属性路径 <input v-model="p.rt.path" placeholder="data.status" style="width:140px" /></label>
          </template>
          <template v-if="p.rt.op === 'replace'">
            <label>替换值 <input v-model="p.rt.value" placeholder="ok 或 var:req.ip" style="width:180px" /></label>
          </template>
          <template v-if="p.rt.op === 'save_variable'">
            <label>目标变量 <input v-model="p.rt.target" placeholder="last_token" style="width:140px" /></label>
            <label>key <input v-model="p.rt.key" placeholder="req.userid" style="width:120px" /></label>
            <label>TTL <input v-model.number="p.rt.ttl" type="number" style="width:80px" /></label>
          </template>
          <template v-if="p.rt.op === 'convert'">
            <label>方向
              <select v-model="p.rt.format">
                <option value="json_to_xml">JSON → XML</option>
                <option value="xml_to_json">XML → JSON</option>
              </select>
            </label>
            <label>XML 根节点 <input v-model="p.rt.root" placeholder="response" style="width:120px" /></label>
            <label>Content-Type <input v-model="p.rt.content_type" placeholder="application/xml" style="width:170px" /></label>
          </template>
        </div>
      </div>

      <div v-if="err" class="alert-err" style="margin-bottom:0">{{ err }}</div>
      <div class="form-row" style="margin-top:12px; margin-bottom:0">
        <button class="primary" :disabled="busy" @click="save">{{ editing ? '保存规则' : '创建规则' }}</button>
        <button @click="resetForm">重置</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import { api } from '../../api'

const props = defineProps({ appId: { type: String, required: true } })
const list = ref([])
const editing = ref(null)
const busy = ref(false)
const err = ref('')

const actionTypes = [
  'stats', 'cache', 'rate_limit', 'auth', 'save_variable',
  'change_target', 'blacklist', 'variable_check', 'response_transform',
]

const form = reactive({
  name: '', description: '', scope: 'app', api_pattern: '*', client_pattern: '*',
  priority: 0, enabled: true, action_type: 'cache',
})

const p = reactive({
  stats: { dimensions: '', target: '', ttl: 86400 },
  cache: { mode: 'force', ttl: 60, keys: '' },
  rl: { window: 60, limit: 100, burst: 0, keys: '', auto_blacklist: false, auto_blacklist_duration: 3600 },
  auth: { type: 'jwt', secret: '', issuer: '', audience: '' },
  sv: { name: '', key: 'req.ip', from: '', ttl: 3600 },
  ct: { upstream: '', rewrite_path: '', strip_prefix: true, set_headers: '' },
  bl: { ip: true, userid: false, apikey: false, custom: '', reason: '' },
  vc: { name: '', require_value: '', min_ttl: 60 },
  rt: { op: 'replace', path: '', value: '', target: '', key: 'req.userid', ttl: 3600, format: 'json_to_xml', root: 'response', content_type: '' },
})

function resetForm() {
  editing.value = null
  Object.assign(form, {
    name: '', description: '', scope: 'app', api_pattern: '*', client_pattern: '*',
    priority: 0, enabled: true, action_type: 'cache',
  })
  Object.assign(p.stats, { dimensions: '', target: '', ttl: 86400 })
  Object.assign(p.cache, { mode: 'force', ttl: 60, keys: '' })
  Object.assign(p.rl, { window: 60, limit: 100, burst: 0, keys: '', auto_blacklist: false, auto_blacklist_duration: 3600 })
  Object.assign(p.auth, { type: 'jwt', secret: '', issuer: '', audience: '' })
  Object.assign(p.sv, { name: '', key: 'req.ip', from: '', ttl: 3600 })
  Object.assign(p.ct, { upstream: '', rewrite_path: '', strip_prefix: true, set_headers: '' })
  Object.assign(p.bl, { ip: true, userid: false, apikey: false, custom: '', reason: '' })
  Object.assign(p.vc, { name: '', require_value: '', min_ttl: 60 })
  Object.assign(p.rt, { op: 'replace', path: '', value: '', target: '', key: 'req.userid', ttl: 3600, format: 'json_to_xml', root: 'response', content_type: '' })
  err.value = ''
}

function onTypeChange() {
  form.scope = form.action_type === 'response_transform' ? 'api' : form.scope
}

function paramsSummary(r) {
  const params = r.params || {}
  const parts = []
  if (params.mode) parts.push(params.mode + (params.ttl ? ' ttl=' + params.ttl : ''))
  if (params.keys) parts.push('keys: ' + (Array.isArray(params.keys) ? params.keys.join(',') : params.keys))
  if (params.window) parts.push(`${params.window}s/${params.limit}`)
  if (params.type) parts.push(params.type)
  if (params.name) parts.push(params.name)
  if (params.op) parts.push(params.op + (params.path ? ' ' + params.path : ''))
  if (params.dimensions) parts.push('dim: ' + (Array.isArray(params.dimensions) ? params.dimensions.join(',') : params.dimensions))
  if (params.upstream) parts.push('upstream: ' + params.upstream)
  return parts.join('；') || '{}'
}

function splitList(s) {
  return String(s || '').split(',').map((x) => x.trim()).filter(Boolean)
}

function buildParams() {
  switch (form.action_type) {
    case 'stats':
      return {
        dimensions: splitList(p.stats.dimensions),
        ...(p.stats.target ? { target: p.stats.target } : {}),
        ttl: Number(p.stats.ttl || 86400),
      }
    case 'cache':
      return {
        mode: p.cache.mode,
        ttl: Number(p.cache.ttl || 60),
        ...(splitList(p.cache.keys).length ? { keys: splitList(p.cache.keys) } : {}),
      }
    case 'rate_limit': {
      const params = {
        window: Number(p.rl.window || 60),
        limit: Number(p.rl.limit || 100),
        burst: Number(p.rl.burst || 0),
        keys: splitList(p.rl.keys).length ? splitList(p.rl.keys) : ['req.ip'],
      }
      if (p.rl.auto_blacklist) {
        params.auto_blacklist = 1
        params.auto_blacklist_duration = Number(p.rl.auto_blacklist_duration || 3600)
      }
      return params
    }
    case 'auth': {
      const params = { type: p.auth.type }
      if (p.auth.secret) params.secret = p.auth.secret
      if (p.auth.issuer) params.issuer = p.auth.issuer
      if (p.auth.audience) params.audience = p.auth.audience
      return params
    }
    case 'save_variable':
      return {
        name: p.sv.name, key: p.sv.key || 'req.ip', from: p.sv.from,
        ...(p.sv.ttl ? { ttl: Number(p.sv.ttl) } : {}),
      }
    case 'change_target': {
      const params = {
        ...(p.ct.upstream ? { upstream: p.ct.upstream } : {}),
        ...(p.ct.rewrite_path ? { rewrite_path: p.ct.rewrite_path } : {}),
        strip_prefix: p.ct.strip_prefix ? 1 : 0,
      }
      if (p.ct.set_headers.trim()) {
        try { params.set_headers = JSON.parse(p.ct.set_headers) } catch { /* ignore */ }
      }
      return params
    }
    case 'blacklist': {
      const dims = []
      if (p.bl.ip) dims.push('req.ip')
      if (p.bl.userid) dims.push('req.userid')
      if (p.bl.apikey) dims.push('req.apikey')
      if (p.bl.custom.trim()) dims.push(p.bl.custom.trim())
      return {
        dimensions: dims.length ? dims : ['req.ip'],
        ...(p.bl.reason ? { reason: p.bl.reason } : {}),
      }
    }
    case 'variable_check': {
      const params = { name: p.vc.name }
      if (p.vc.require_value) params.require_value = p.vc.require_value
      if (p.vc.min_ttl) params.min_ttl = Number(p.vc.min_ttl)
      return params
    }
    case 'response_transform': {
      const params = { op: p.rt.op }
      if (p.rt.op === 'replace') {
        params.path = p.rt.path
        params.value = p.rt.value
      } else if (p.rt.op === 'save_variable') {
        params.path = p.rt.path
        params.target = p.rt.target
        params.key = p.rt.key
        if (p.rt.ttl) params.ttl = Number(p.rt.ttl)
      } else {
        params.format = p.rt.format
        if (p.rt.root) params.root = p.rt.root
        if (p.rt.content_type) params.content_type = p.rt.content_type
      }
      return params
    }
    default:
      return {}
  }
}

function fillParams(params) {
  const x = params || {}
  Object.assign(p.stats, {
    dimensions: Array.isArray(x.dimensions) ? x.dimensions.join(', ') : '',
    target: x.target || '', ttl: Number(x.ttl || 86400),
  })
  Object.assign(p.cache, {
    mode: x.mode || 'force', ttl: Number(x.ttl || 60),
    keys: Array.isArray(x.keys) ? x.keys.join(', ') : (x.keys || ''),
  })
  Object.assign(p.rl, {
    window: Number(x.window || 60), limit: Number(x.limit || 100), burst: Number(x.burst || 0),
    keys: Array.isArray(x.keys) ? x.keys.join(', ') : '',
    auto_blacklist: !!x.auto_blacklist, auto_blacklist_duration: Number(x.auto_blacklist_duration || 3600),
  })
  Object.assign(p.auth, { type: x.type || 'jwt', secret: x.secret || '', issuer: x.issuer || '', audience: x.audience || '' })
  Object.assign(p.sv, { name: x.name || '', key: x.key || 'req.ip', from: x.from || '', ttl: Number(x.ttl || 3600) })
  Object.assign(p.ct, {
    upstream: x.upstream || '', rewrite_path: x.rewrite_path || '',
    strip_prefix: x.strip_prefix ? !!x.strip_prefix : true,
    set_headers: x.set_headers ? JSON.stringify(x.set_headers) : '',
  })
  const dims = Array.isArray(x.dimensions) ? x.dimensions : []
  Object.assign(p.bl, {
    ip: dims.includes('req.ip'), userid: dims.includes('req.userid'),
    apikey: dims.includes('req.apikey'),
    custom: dims.filter((d) => !['req.ip', 'req.userid', 'req.apikey'].includes(d)).join(', '),
    reason: x.reason || '',
  })
  Object.assign(p.vc, { name: x.name || '', require_value: x.require_value || '', min_ttl: Number(x.min_ttl || 60) })
  Object.assign(p.rt, {
    op: x.op || 'replace', path: x.path || '', value: x.value || '',
    target: x.target || '', key: x.key || 'req.userid', ttl: Number(x.ttl || 3600),
    format: x.format || 'json_to_xml', root: x.root || 'response', content_type: x.content_type || '',
  })
}

function openEdit(r) {
  editing.value = r
  Object.assign(form, {
    name: r.name, description: r.description || '', scope: r.scope || 'app', api_pattern: r.api_pattern || '*',
    client_pattern: r.client_pattern || '*', priority: Number(r.priority || 0),
    enabled: !!r.enabled, action_type: r.action_type,
  })
  fillParams(r.params)
  err.value = ''
}

async function save() {
  busy.value = true
  err.value = ''
  try {
    const body = {
      name: form.name.trim() || form.action_type,
      description: form.description.trim(),
      scope: form.scope,
      api_pattern: form.api_pattern.trim() || '*',
      client_pattern: form.client_pattern.trim() || '*',
      priority: Number(form.priority || 0),
      enabled: form.enabled ? 1 : 0,
      action_type: form.action_type,
      params: buildParams(),
    }
    const c = api.app(props.appId)
    if (editing.value) {
      await c.update('rules', editing.value.id, body)
    } else {
      await c.create('rules', body)
    }
    await load()
    resetForm()
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}

async function remove(r) {
  if (!confirm(`确认删除规则「${r.name}」？`)) return
  try {
    await api.app(props.appId).remove('rules', r.id)
    await load()
  } catch (e) {
    alert(e.message)
  }
}

async function load() {
  list.value = await api.app(props.appId).get('rules')
}

watch(() => props.appId, () => { resetForm(); load() })
onMounted(() => { resetForm(); load() })
</script>

<style scoped>
.field-block { background: #f9fafb; border: 1px solid #eef0f3; border-radius: 6px; padding: 10px 12px; margin-bottom: 10px; }
.rule-desc { font-size: 12px; color: #9ca3af; margin-top: 2px; max-width: 300px; }
</style>
