<template>
  <div v-if="app">
    <div class="app-head">
      <h2>{{ app.name }}</h2>
      <span class="mono meta">base_path {{ app.base_path }}</span>
      <span class="badge" :class="app.default_allow ? 'tag-ok' : 'tag-down'">{{ app.default_allow ? '缺省放行' : '缺省拒绝' }}</span>
      <span class="badge tag-info">自动加入规则：{{ app.auto_add_rules ? '开' : '关' }}</span>
      <span class="badge" :class="app.status ? 'tag-ok' : 'tag-down'">{{ app.status ? '启用' : '停用' }}</span>
    </div>

    <Overview v-if="sub === 'overview'" :app-id="appId" />
    <Settings v-else-if="sub === 'settings'" :app-id="appId" />
    <Apis v-else-if="sub === 'apis'" :app-id="appId" />
    <Clients v-else-if="sub === 'clients'" :app-id="appId" />
    <Rules v-else-if="sub === 'rules'" :app-id="appId" />
    <Variables v-else-if="sub === 'variables'" :app-id="appId" />
    <Upstreams v-else-if="sub === 'upstreams'" :app-id="appId" />
    <Blacklist v-else-if="sub === 'blacklist'" :app-id="appId" />
  </div>
  <div v-else class="card"><div class="empty">应用不存在或已删除</div></div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import Overview from './app/Overview.vue'
import Settings from './app/Settings.vue'
import Apis from './app/Apis.vue'
import Clients from './app/Clients.vue'
import Rules from './app/Rules.vue'
import Variables from './app/Variables.vue'
import Upstreams from './app/Upstreams.vue'
import Blacklist from './app/Blacklist.vue'

const route = useRoute()
const router = useRouter()
const appId = computed(() => String(route.params.appId))
const sub = computed(() => {
  const s = route.params.sub || 'overview'
  const valid = ['overview', 'settings', 'apis', 'clients', 'rules', 'variables', 'upstreams', 'blacklist']
  if (!valid.includes(s)) {
    router.replace(`/apps/${appId.value}/overview`)
    return 'overview'
  }
  return s
})
const apps = ref([])
const app = computed(() => apps.value.find((a) => String(a.id) === appId.value) || null)

async function load() {
  apps.value = await api.apps()
  if (!app.value) {
    // 可能是刚创建或已删除，重新拉取一次
    apps.value = await api.apps()
  }
}

watch(() => appId.value, load)
onMounted(load)
</script>
