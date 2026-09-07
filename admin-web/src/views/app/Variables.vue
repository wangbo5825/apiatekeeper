<template>
  <div>
    <div class="card">
      <h2>变量声明区域 <span class="badge tag-new">新增</span></h2>
      <table>
        <thead><tr><th>变量名</th><th>key 维度</th><th>取值来源</th><th>TTL</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="v in variables" :key="v.name">
            <td class="mono">{{ v.name }}</td>
            <td>{{ v.key_dim }}</td>
            <td>{{ v.source || '—' }}</td>
            <td>{{ v.ttl }}s</td>
            <td><button class="sm danger" @click="removeVariable(v)">删除</button></td>
          </tr>
          <tr v-if="!variables.length"><td colspan="5"><div class="empty">暂无变量</div></td></tr>
        </tbody>
      </table>
      <div class="hint">
        请求级变量内置：<code>req.url / req.method / req.ip / req.userid / req.apikey</code>，
        <code>req.header:&lt;name&gt; / req.query:&lt;name&gt; / req.path:&lt;name&gt;</code>。
        声明变量在规则参数中以 <code>var:&lt;name&gt;</code> 引用。
      </div>
    </div>

    <div class="card">
      <h2>维度定义（key 的来源） <span class="badge tag-new">新增</span></h2>
      <table>
        <thead><tr><th>维度名</th><th>解析链（按顺序取第一个非空）</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="d in dimensions" :key="d.name">
            <td class="mono">{{ d.name }}</td>
            <td class="mono">{{ chainText(d.chain) }}</td>
            <td><button class="sm danger" @click="removeDimension(d)">删除</button></td>
          </tr>
          <tr v-if="!dimensions.length"><td colspan="3"><div class="empty">暂无语义维度</div></td></tr>
        </tbody>
      </table>
      <div class="form-row" style="margin-top:10px">
        <input v-model="dimForm.name" placeholder="维度名，如 dim:user" style="width:130px" />
        <input v-model="dimForm.chain" placeholder="解析链：req.userid → req.header:X-User-Id → req.query:uid" style="flex:1; min-width:260px" />
        <button class="primary sm" :disabled="busy" @click="addDimension">新增语义维度</button>
      </div>
    </div>

    <div class="card">
      <h2>声明新变量</h2>
      <div class="form-row">
        <input v-model="varForm.name" placeholder="变量名，如 last_url" style="width:160px" />
        <label>key 维度 <input v-model="varForm.key_dim" placeholder="req.ip / dim:user / global" style="width:170px" /></label>
        <label>取值来源 <input v-model="varForm.source" placeholder="可选说明" style="width:180px" /></label>
        <label>TTL（秒）<input v-model.number="varForm.ttl" type="number" style="width:90px" /></label>
        <button class="primary" :disabled="busy" @click="addVariable">声明变量</button>
      </div>
      <div v-if="msg" class="alert-ok" style="margin-bottom:0">{{ msg }}</div>
      <div v-if="err" class="alert-err" style="margin-bottom:0">{{ err }}</div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import { api } from '../../api'

const props = defineProps({ appId: { type: String, required: true } })
const variables = ref([])
const dimensions = ref([])
const busy = ref(false)
const msg = ref('')
const err = ref('')
const varForm = reactive({ name: '', key_dim: 'req.ip', source: '', ttl: 3600 })
const dimForm = reactive({ name: '', chain: '' })

function chainText(chain) {
  if (Array.isArray(chain)) return chain.join(' → ')
  try { return JSON.parse(chain || '[]').join(' → ') } catch { return String(chain || '') }
}

async function load() {
  const c = api.app(props.appId)
  const [v, d] = await Promise.all([c.get('variables'), c.get('dimensions')])
  variables.value = v
  dimensions.value = d
}

async function addVariable() {
  busy.value = true
  msg.value = ''
  err.value = ''
  try {
    await api.app(props.appId).create('variables', {
      name: varForm.name.trim(), key_dim: varForm.key_dim.trim() || 'req.ip',
      source: varForm.source.trim(), ttl: Number(varForm.ttl || 3600),
    })
    msg.value = '变量已声明'
    varForm.name = ''
    await load()
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}

async function removeVariable(v) {
  if (!confirm(`确认删除变量 ${v.name}？`)) return
  await api.app(props.appId).remove('variables', v.name)
  await load()
}

async function addDimension() {
  busy.value = true
  err.value = ''
  try {
    const chain = dimForm.chain.split('→').map((x) => x.trim()).filter(Boolean)
    await api.app(props.appId).create('dimensions', { name: dimForm.name.trim(), chain })
    dimForm.name = ''
    dimForm.chain = ''
    await load()
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}

async function removeDimension(d) {
  if (!confirm(`确认删除维度 ${d.name}？`)) return
  await api.app(props.appId).remove('dimensions', d.name)
  await load()
}

watch(() => props.appId, load)
onMounted(load)
</script>
