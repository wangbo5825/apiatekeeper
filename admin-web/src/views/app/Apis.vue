<template>
  <div>
    <div class="card">
      <h2>API 列表（应用内相对路径）</h2>
      <div class="alert-line" style="margin-top:0">
        自动加入规则：新 URL 自动访问上游并按模式登记到本列表（由后台导入器落库）。
      </div>
      <div class="form-row">
        <input v-model="search" placeholder="搜索路径" style="width:220px" />
        <button class="primary sm" @click="openCreate">手动添加 API</button>
        <span class="badge tag-info">已登记 {{ filtered.length }} 个</span>
      </div>
      <table>
        <thead><tr><th>方法</th><th>相对路径模板</th><th>名称</th><th>来源</th><th>请求数</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="a in filtered" :key="a.id">
            <td><span class="badge">{{ a.method }}</span></td>
            <td class="mono">{{ a.template }}</td>
            <td>{{ a.display_name || '—' }}</td>
            <td><span class="badge" :class="a.auto ? 'tag-info' : 'tag-new'">{{ a.auto ? '自动登记' : '手动' }}</span></td>
            <td>{{ a.requests || 0 }}</td>
            <td>
              <button class="sm" @click="openRename(a)">编辑</button>
              <button class="sm danger" @click="remove(a)">删除</button>
            </td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="6"><div class="empty">暂无 API</div></td></tr>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>{{ editing ? '编辑 API' : '手动添加 API' }}</h2>
      <div class="form-row">
        <label>方法
          <select v-model="form.method">
            <option v-for="m in ['GET','POST','PUT','DELETE','PATCH']" :key="m" :value="m">{{ m }}</option>
          </select>
        </label>
        <label>相对路径模板 <input v-model="form.template" placeholder="/orders/{id}" style="width:180px" /></label>
        <input v-model="form.display_name" placeholder="名称（可选）" style="width:150px" />
        <button class="primary sm" :disabled="busy" @click="save">{{ editing ? '保存' : '添加' }}</button>
      </div>
      <div v-if="err" class="alert-err" style="margin-bottom:0">{{ err }}</div>
      <div class="hint">保存时检测与其它 URL 的冲突（完全重复 / 模板互相覆盖 / 通配重叠）。</div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { api } from '../../api'

const props = defineProps({ appId: { type: String, required: true } })
const list = ref([])
const search = ref('')
const editing = ref(null)
const busy = ref(false)
const err = ref('')
let timer = null
const form = reactive({ method: 'GET', template: '', display_name: '' })

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return list.value
  return list.value.filter((a) => (a.method + ' ' + a.template).toLowerCase().includes(q))
})

async function load() {
  list.value = await api.app(props.appId).get('apis')
}

function openCreate() {
  editing.value = null
  Object.assign(form, { method: 'GET', template: '', display_name: '' })
  err.value = ''
}

function openRename(a) {
  editing.value = a
  Object.assign(form, { method: a.method, template: a.template, display_name: a.display_name || '' })
  err.value = ''
}

async function save() {
  busy.value = true
  err.value = ''
  try {
    const body = { method: form.method, template: form.template.trim(), display_name: form.display_name.trim() }
    if (editing.value) {
      await api.app(props.appId).update('apis', editing.value.id, body)
    } else {
      await api.app(props.appId).create('apis', body)
    }
    await load()
    openCreate()
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}

async function remove(a) {
  if (!confirm(`确认删除 ${a.method} ${a.template}？`)) return
  try {
    await api.app(props.appId).remove('apis', a.id)
    await load()
  } catch (e) {
    alert(e.message)
  }
}

watch(() => props.appId, () => { load(); clearInterval(timer); timer = setInterval(load, 60000) })
onMounted(() => {
  load()
  timer = setInterval(load, 60000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
