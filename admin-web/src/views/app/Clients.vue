<template>
  <div>
    <div class="card">
      <h2>客户端（应用内）</h2>
      <table>
        <thead><tr><th>客户端</th><th>类型</th><th>请求数</th><th>错误</th><th>拦截</th></tr></thead>
        <tbody>
          <tr v-for="c in list" :key="c.client_key">
            <td class="mono">{{ c.client_key }}</td>
            <td>{{ typeLabel(c.client_key) }}</td>
            <td>{{ c.requests || 0 }}</td>
            <td>{{ c.errors || 0 }}</td>
            <td>{{ c.denied || 0 }}</td>
          </tr>
          <tr v-if="!list.length"><td colspan="5"><div class="empty">暂无客户端数据</div></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { api } from '../../api'

const props = defineProps({ appId: { type: String, required: true } })
const list = ref([])

function typeLabel(key) {
  if (key.startsWith('user:')) return 'JWT sub'
  if (key.startsWith('key:')) return 'API Key'
  if (key.startsWith('ip:')) return 'IP'
  return '未知'
}

async function load() {
  list.value = await api.app(props.appId).get('clients')
}

watch(() => props.appId, load)
onMounted(load)
</script>
