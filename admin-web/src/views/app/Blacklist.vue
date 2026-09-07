<template>
  <div>
    <div class="card">
      <h2>黑名单（应用内）</h2>
      <div class="form-row">
        <label>维度
          <select v-model="form.dimension">
            <option value="req.ip">req.ip</option>
            <option value="req.userid">req.userid</option>
            <option value="req.apikey">req.apikey</option>
            <option value="req.query:phone">req.query:xxx（自定义）</option>
          </select>
        </label>
        <input v-model="form.value" placeholder="值，如 1.2.3.4" style="width:160px" />
        <input v-model="form.reason" placeholder="原因（可选）" style="width:140px" />
        <label>过期时间 <input v-model="form.expires_at" type="datetime-local" /></label>
        <button class="primary" :disabled="busy" @click="add">加入黑名单</button>
      </div>
      <table>
        <thead><tr><th>ID</th><th>维度</th><th>值</th><th>原因</th><th>来源</th><th>过期时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="b in list" :key="b.id">
            <td>{{ b.id }}</td>
            <td>{{ b.dimension }}</td>
            <td class="mono">{{ b.value }}</td>
            <td>{{ b.reason || '—' }}</td>
            <td><span class="badge" :class="b.source === 'auto' ? 'tag-new' : ''">{{ b.source === 'auto' ? '自动' : '手动' }}</span></td>
            <td>{{ b.expires_at || '永久' }}</td>
            <td><button class="sm danger" @click="remove(b)">移除</button></td>
          </tr>
          <tr v-if="!list.length"><td colspan="7"><div class="empty">暂无黑名单</div></td></tr>
        </tbody>
      </table>
      <div class="hint">黑名单规则（"规则"页 blacklist 类型）声明维度后，命中本表即拦截。</div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import { api } from '../../api'

const props = defineProps({ appId: { type: String, required: true } })
const list = ref([])
const busy = ref(false)
const form = reactive({ dimension: 'req.ip', value: '', reason: '', expires_at: '' })

async function load() {
  list.value = await api.app(props.appId).get('blacklist')
}

async function add() {
  busy.value = true
  try {
    await api.app(props.appId).create('blacklist', {
      dimension: form.dimension,
      value: form.value.trim(),
      reason: form.reason.trim(),
      ...(form.expires_at ? { expires_at: form.expires_at.replace('T', ' ') } : {}),
    })
    form.value = ''
    form.reason = ''
    form.expires_at = ''
    await load()
  } catch (e) {
    alert(e.message)
  } finally {
    busy.value = false
  }
}

async function remove(b) {
  if (!confirm(`确认移除 ${b.dimension} = ${b.value}？`)) return
  await api.app(props.appId).remove('blacklist', b.id)
  await load()
}

watch(() => props.appId, load)
onMounted(load)
</script>
