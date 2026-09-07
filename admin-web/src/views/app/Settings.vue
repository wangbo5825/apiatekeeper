<template>
  <div>
    <div class="card">
      <h2>应用缺省配置 <span class="badge tag-new">新增</span></h2>
      <div class="switch-row">
        <div><b>缺省放行</b><div class="d">未配置规则的请求是否放行到应用上游</div></div>
        <input v-model="form.default_allow" type="checkbox" />
      </div>
      <div class="switch-row">
        <div><b>缺省缓存返回数据</b><div class="d">未配置缓存规则时是否缓存响应</div></div>
        <input v-model="form.default_cache_enabled" type="checkbox" />
      </div>
      <div class="form-row" style="margin-top:10px">
        <label>缺省缓存 TTL（秒）<input v-model.number="form.default_cache_ttl" type="number" style="width:90px" /></label>
      </div>
      <div class="switch-row">
        <div><b>自动加入规则</b><div class="d">新 URL 按模式自动加入 API 列表（关闭后不再入列）</div></div>
        <input v-model="form.auto_add_rules" type="checkbox" />
      </div>
      <div class="form-row" style="margin-top:12px">
        <label>匹配方式
          <select v-model="form.match_mode">
            <option value="prefix">仅前缀</option>
            <option value="host">前缀 + Host</option>
            <option value="header">前缀 + 请求头</option>
          </select>
        </label>
        <input v-model="form.match_value" placeholder="Host / 请求头值" style="width:200px" />
        <label>状态
          <select v-model.number="form.status">
            <option :value="1">启用</option>
            <option :value="0">停用</option>
          </select>
        </label>
      </div>
      <div v-if="msg" class="alert-ok" style="margin-bottom:0">{{ msg }}</div>
      <div v-if="err" class="alert-err" style="margin-bottom:0">{{ err }}</div>
      <div class="form-row" style="margin-top:12px; margin-bottom:0">
        <button class="primary" :disabled="busy" @click="save">保存应用设置</button>
      </div>
    </div>
    <div class="card">
      <h3>缺省配置 × 自动加入 组合效果</h3>
      <table>
        <thead><tr><th>缺省放行</th><th>自动加入</th><th>效果</th></tr></thead>
        <tbody>
          <tr><td>放行</td><td>开</td><td>新 URL 自动放行并加入列表（零配置可用）</td></tr>
          <tr><td>放行</td><td>关</td><td>新 URL 放行但不加入列表</td></tr>
          <tr><td>拒绝</td><td>开</td><td>新 URL 拒绝；加入列表后放行</td></tr>
          <tr><td>拒绝</td><td>关</td><td>严格白名单，仅列表内 API 可用</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, watch, onMounted } from 'vue'
import { api } from '../../api'

const props = defineProps({ appId: { type: String, required: true } })
const busy = ref(false)
const msg = ref('')
const err = ref('')
const form = reactive({
  name: '', default_allow: true, default_cache_enabled: false, default_cache_ttl: 60,
  auto_add_rules: true, match_mode: 'prefix', match_value: '', status: 1,
})

async function load() {
  const apps = await api.apps()
  const app = apps.find((a) => String(a.id) === String(props.appId))
  if (!app) return
  Object.assign(form, {
    name: app.name, default_allow: !!app.default_allow,
    default_cache_enabled: !!app.default_cache_enabled,
    default_cache_ttl: Number(app.default_cache_ttl || 60),
    auto_add_rules: !!app.auto_add_rules,
    match_mode: app.match_mode || 'prefix', match_value: app.match_value || '',
    status: Number(app.status ?? 1),
  })
}

async function save() {
  busy.value = true
  msg.value = ''
  err.value = ''
  try {
    await api.updateApp(props.appId, {
      name: form.name,
      default_allow: form.default_allow ? 1 : 0,
      default_cache_enabled: form.default_cache_enabled ? 1 : 0,
      default_cache_ttl: Number(form.default_cache_ttl || 60),
      auto_add_rules: form.auto_add_rules ? 1 : 0,
      match_mode: form.match_mode,
      match_value: form.match_value,
      status: Number(form.status),
    })
    msg.value = '已保存'
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}

watch(() => props.appId, load)
onMounted(load)
</script>
