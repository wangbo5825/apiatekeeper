<template>
  <div>
    <div class="card">
      <h2>上游组（应用内）</h2>
      <table>
        <thead><tr><th>名称</th><th>策略</th><th>路径映射</th><th>目标数</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="g in groups" :key="g.id">
            <td>{{ g.name }}</td>
            <td>{{ strategyLabel(g.strategy) }}</td>
            <td>{{ pathLabel(g) }}</td>
            <td>{{ targetsByGroup[g.id] ? targetsByGroup[g.id].length : 0 }}</td>
            <td>
              <button class="sm" @click="selectGroup(g)">{{ selectedGroup && selectedGroup.id === g.id ? '收起' : '目标' }}</button>
              <button class="sm danger" @click="removeGroup(g)">删除</button>
            </td>
          </tr>
          <tr v-if="!groups.length"><td colspan="5"><div class="empty">暂无上游组</div></td></tr>
        </tbody>
      </table>

      <div class="form-row" style="margin-top:12px">
        <input v-model="groupForm.name" placeholder="上游组名称，如 订单上游组" style="width:180px" />
        <label>策略
          <select v-model="groupForm.strategy">
            <option value="round_robin">多目标轮询</option>
            <option value="active_standby">主备切换</option>
          </select>
        </label>
        <button class="primary" :disabled="busy" @click="addGroup">新建上游组</button>
      </div>
      <div v-if="err" class="alert-err" style="margin-bottom:0">{{ err }}</div>
    </div>

    <div v-if="selectedGroup" class="card">
      <h2>{{ selectedGroup.name }} · 目标列表</h2>
      <div class="form-row">
        <input v-model="targetForm.address" placeholder="http://10.1.0.31:8080" style="width:220px" />
        <label>角色
          <select v-model="targetForm.role">
            <option value="round_robin">轮询节点</option>
            <option value="active">主节点</option>
            <option value="standby">备用节点</option>
          </select>
        </label>
        <label>权重 <input v-model.number="targetForm.weight" type="number" style="width:70px" /></label>
        <button class="primary sm" :disabled="busy" @click="addTarget">添加目标</button>
      </div>
      <table>
        <thead><tr><th>地址</th><th>角色</th><th>权重</th><th>启用</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="t in targets" :key="t.id">
            <td class="mono">{{ t.address }}</td>
            <td>{{ t.role }}</td>
            <td>{{ t.weight }}</td>
            <td>{{ t.enabled ? '是' : '否' }}</td>
            <td><button class="sm danger" @click="removeTarget(t)">删除</button></td>
          </tr>
          <tr v-if="!targets.length"><td colspan="5"><div class="empty">暂无目标</div></td></tr>
        </tbody>
      </table>

      <h3 style="margin-top:16px">缺省映射（前缀转写）与健康检查</h3>
      <div class="form-row">
        <label>名称 <input v-model="groupForm.name" style="width:150px" /></label>
        <label>路径模式
          <select v-model="groupForm.path_mode">
            <option value="strip">剥离应用 base_path</option>
            <option value="replace">前缀替换</option>
            <option value="add">加前缀</option>
            <option value="none">不转写</option>
          </select>
        </label>
        <label>源前缀 <input v-model="groupForm.prefix_from" placeholder="/v1" style="width:80px" /></label>
        <label>目标前缀 <input v-model="groupForm.prefix_to" placeholder="/api/v1" style="width:100px" /></label>
      </div>
      <div class="form-row">
        <label>检查模式
          <select v-model="groupForm.check_mode">
            <option value="active">主动</option>
            <option value="passive">被动</option>
            <option value="both">主动 + 被动</option>
          </select>
        </label>
        <label>间隔 <input v-model.number="groupForm.check_interval" type="number" style="width:60px" />s</label>
        <label>超时 <input v-model.number="groupForm.check_timeout" type="number" style="width:60px" />s</label>
        <label>探测路径 <input v-model="groupForm.check_path" style="width:100px" /></label>
        <label>失败阈值 <input v-model.number="groupForm.fail_threshold" type="number" style="width:60px" /></label>
        <label>恢复阈值 <input v-model.number="groupForm.recover_threshold" type="number" style="width:60px" /></label>
        <button class="primary sm" :disabled="busy" @click="saveGroup">保存</button>
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
const groups = ref([])
const targets = ref([])
const targetsByGroup = ref({})
const selectedGroup = ref(null)
const busy = ref(false)
const msg = ref('')
const err = ref('')

const groupForm = reactive({
  name: '', strategy: 'round_robin', path_mode: 'strip', prefix_from: '', prefix_to: '',
  check_mode: 'active', check_interval: 10, check_timeout: 2, check_path: '/healthz',
  fail_threshold: 3, recover_threshold: 2,
})
const targetForm = reactive({ address: '', role: 'round_robin', weight: 100 })

function strategyLabel(s) {
  return s === 'active_standby' ? '主备切换' : '多目标轮询'
}

function pathLabel(g) {
  const m = { strip: '剥离 base_path', replace: `替换 ${g.prefix_from || '?'} → ${g.prefix_to || '?'}`, add: `加前缀 ${g.prefix_to || '?'}`, none: '不转写' }
  return m[g.path_mode] || g.path_mode
}

async function loadGroups() {
  groups.value = await api.app(props.appId).get('upstreams')
  await Promise.all(groups.value.map(async (g) => {
    targetsByGroup.value[g.id] = await api.app(props.appId).get(`upstreams/${g.id}/targets`)
  }))
}

async function selectGroup(g) {
  if (selectedGroup.value && selectedGroup.value.id === g.id) {
    selectedGroup.value = null
    return
  }
  selectedGroup.value = g
  Object.assign(groupForm, {
    name: g.name, strategy: g.strategy || 'round_robin', path_mode: g.path_mode || 'strip',
    prefix_from: g.prefix_from || '', prefix_to: g.prefix_to || '',
    check_mode: g.check_mode || 'active', check_interval: Number(g.check_interval || 10),
    check_timeout: Number(g.check_timeout || 2), check_path: g.check_path || '/healthz',
    fail_threshold: Number(g.fail_threshold || 3), recover_threshold: Number(g.recover_threshold || 2),
  })
  msg.value = ''
  targets.value = await api.app(props.appId).get(`upstreams/${g.id}/targets`)
}

async function addGroup() {
  busy.value = true
  err.value = ''
  try {
    await api.app(props.appId).create('upstreams', {
      name: groupForm.name.trim() || '新上游组',
      strategy: groupForm.strategy, path_mode: groupForm.path_mode,
      prefix_from: groupForm.prefix_from, prefix_to: groupForm.prefix_to,
      check_mode: groupForm.check_mode, check_interval: groupForm.check_interval,
      check_timeout: groupForm.check_timeout, check_path: groupForm.check_path,
      fail_threshold: groupForm.fail_threshold, recover_threshold: groupForm.recover_threshold,
    })
    groupForm.name = ''
    await loadGroups()
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}

async function saveGroup() {
  if (!selectedGroup.value) return
  busy.value = true
  msg.value = ''
  err.value = ''
  try {
    await api.app(props.appId).update('upstreams', selectedGroup.value.id, {
      name: groupForm.name.trim() || selectedGroup.value.name,
      strategy: groupForm.strategy,
      path_mode: groupForm.path_mode,
      prefix_from: groupForm.prefix_from,
      prefix_to: groupForm.prefix_to,
      check_mode: groupForm.check_mode,
      check_interval: groupForm.check_interval,
      check_timeout: groupForm.check_timeout,
      check_path: groupForm.check_path,
      fail_threshold: groupForm.fail_threshold,
      recover_threshold: groupForm.recover_threshold,
    })
    msg.value = '已保存'
    await loadGroups()
    const fresh = groups.value.find((g) => g.id === selectedGroup.value.id)
    if (fresh) selectedGroup.value = fresh
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}

async function removeGroup(g) {
  if (!confirm(`确认删除上游组「${g.name}」？`)) return
  await api.app(props.appId).remove('upstreams', g.id)
  selectedGroup.value = null
  await loadGroups()
}

async function addTarget() {
  busy.value = true
  try {
    await api.app(props.appId).create(`upstreams/${selectedGroup.value.id}/targets`, {
      address: targetForm.address.trim(), role: targetForm.role, weight: Number(targetForm.weight || 100),
    })
    targetForm.address = ''
    targets.value = await api.app(props.appId).get(`upstreams/${selectedGroup.value.id}/targets`)
    await loadGroups()
  } catch (e) {
    alert(e.message)
  } finally {
    busy.value = false
  }
}

async function removeTarget(t) {
  if (!confirm(`确认删除目标 ${t.address}？`)) return
  await api.app(props.appId).remove(`upstreams/${selectedGroup.value.id}/targets`, t.id)
  targets.value = await api.app(props.appId).get(`upstreams/${selectedGroup.value.id}/targets`)
  await loadGroups()
}

watch(() => props.appId, () => { selectedGroup.value = null; loadGroups() })
onMounted(loadGroups)
</script>
