<template>
  <div>
    <div class="card">
      <h2>应用列表</h2>
      <div class="form-row" style="justify-content:flex-end; margin-bottom:0">
        <button class="primary sm" @click="openCreate">新建应用</button>
      </div>
      <table>
        <thead>
          <tr><th>名称</th><th>base_path</th><th>匹配方式</th><th>缺省配置</th><th>状态</th><th>操作</th></tr>
        </thead>
        <tbody>
          <tr v-for="app in apps" :key="app.id">
            <td>{{ app.name }}</td>
            <td class="mono">{{ app.base_path }}</td>
            <td>{{ matchLabel(app) }}</td>
            <td>{{ app.default_allow ? '放行' : '拒绝' }} · 自动加入{{ app.auto_add_rules ? '开' : '关' }}</td>
            <td><span class="badge" :class="app.status ? 'tag-ok' : 'tag-down'">{{ app.status ? '启用' : '停用' }}</span></td>
            <td>
              <button class="sm primary" @click="goDetail(app.id)">进入详情</button>
              <button class="sm" @click="openEdit(app)">编辑</button>
              <button class="sm danger" @click="remove(app)">删除</button>
            </td>
          </tr>
          <tr v-if="!apps.length"><td colspan="6"><div class="empty">暂无应用</div></td></tr>
        </tbody>
      </table>
    </div>

    <div v-if="showModal" class="modal-mask" @click.self="showModal = false">
      <div class="modal">
        <h2>{{ editing ? '编辑应用' : '新建应用' }}</h2>
        <div class="form-row">
          <input v-model="form.name" placeholder="应用名称，如 订单服务" style="flex:1; min-width:180px" />
          <label>base_path <input v-model="form.base_path" placeholder="/v1" style="width:90px" /></label>
        </div>
        <div class="form-row">
          <label>匹配方式
            <select v-model="form.match_mode">
              <option value="prefix">仅前缀</option>
              <option value="host">前缀 + Host</option>
              <option value="header">前缀 + 请求头</option>
            </select>
          </label>
          <input v-model="form.match_value" placeholder="Host / 请求头值（可选）" style="flex:1; min-width:180px" />
        </div>
        <div class="form-row">
          <label><input v-model="form.default_allow" type="checkbox" /> 缺省放行</label>
          <label><input v-model="form.auto_add_rules" type="checkbox" /> 自动加入规则</label>
          <label><input v-model="form.default_cache_enabled" type="checkbox" /> 缺省缓存</label>
          <label>缓存 TTL <input v-model.number="form.default_cache_ttl" type="number" style="width:80px" /></label>
        </div>
        <div v-if="error" class="alert-err" style="margin-bottom:0">{{ error }}</div>
        <div class="form-row" style="justify-content:flex-end; margin-top:14px; margin-bottom:0">
          <button @click="showModal = false">取消</button>
          <button class="primary" :disabled="busy" @click="save">保存</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'

const router = useRouter()
const apps = ref([])
const showModal = ref(false)
const editing = ref(null)
const busy = ref(false)
const error = ref('')

const form = reactive({
  name: '',
  base_path: '/v1',
  match_mode: 'prefix',
  match_value: '',
  default_allow: true,
  default_cache_enabled: false,
  default_cache_ttl: 60,
  auto_add_rules: true,
})

function matchLabel(app) {
  const m = { prefix: '仅前缀', host: '前缀 + Host', header: '前缀 + 请求头' }[app.match_mode] || app.match_mode
  return app.match_value ? `${m}（${app.match_value}）` : m
}

function resetForm() {
  Object.assign(form, {
    name: '', base_path: '/v1', match_mode: 'prefix', match_value: '',
    default_allow: true, default_cache_enabled: false, default_cache_ttl: 60, auto_add_rules: true,
  })
}

function openCreate() {
  editing.value = null
  resetForm()
  error.value = ''
  showModal.value = true
}

function openEdit(app) {
  editing.value = app
  Object.assign(form, {
    name: app.name, base_path: app.base_path, match_mode: app.match_mode || 'prefix',
    match_value: app.match_value || '', default_allow: !!app.default_allow,
    default_cache_enabled: !!app.default_cache_enabled, default_cache_ttl: Number(app.default_cache_ttl || 60),
    auto_add_rules: !!app.auto_add_rules,
  })
  error.value = ''
  showModal.value = true
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const data = {
      name: form.name.trim() || '新应用',
      base_path: form.base_path.trim() || '/',
      match_mode: form.match_mode,
      match_value: form.match_value.trim(),
      default_allow: form.default_allow ? 1 : 0,
      default_cache_enabled: form.default_cache_enabled ? 1 : 0,
      default_cache_ttl: Number(form.default_cache_ttl || 60),
      auto_add_rules: form.auto_add_rules ? 1 : 0,
    }
    if (editing.value) {
      await api.updateApp(editing.value.id, data)
    } else {
      await api.createApp(data)
    }
    showModal.value = false
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function remove(app) {
  if (!confirm(`确认删除应用「${app.name}」？该操作不可恢复。`)) return
  try {
    await api.deleteApp(app.id)
    await load()
  } catch (e) {
    alert(e.message)
  }
}

async function load() {
  apps.value = await api.apps()
}

function goDetail(id) {
  router.push(`/apps/${id}/overview`)
}

onMounted(load)
</script>
