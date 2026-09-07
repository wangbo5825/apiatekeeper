<template>
  <div class="login-wrap">
    <form class="login-card" @submit.prevent="submit">
      <h1>ApiGateKeeper</h1>
      <p class="sub">管理端登录</p>

      <label class="field-label">用户名</label>
      <input v-model.trim="username" autocomplete="username" placeholder="管理员用户名" :disabled="busy" />

      <label class="field-label" style="margin-top:12px">密码</label>
      <input
        v-model="password"
        type="password"
        autocomplete="current-password"
        placeholder="密码"
        :disabled="busy"
      />

      <div v-if="error" class="alert-err" style="margin-top:10px; margin-bottom:0">{{ error }}</div>
      <button class="primary" type="submit" :disabled="busy" style="width:100%; margin-top:16px">
        {{ busy ? '登录中…' : '登 录' }}
      </button>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { login, refreshStatus } from '../api'

const router = useRouter()
const username = ref('')
const password = ref('')
const busy = ref(false)
const error = ref('')

async function submit() {
  if (!username.value || !password.value) {
    error.value = '请输入用户名和密码'
    return
  }
  busy.value = true
  error.value = ''
  try {
    await login(username.value, password.value)
    await refreshStatus()
    router.push('/')
  } catch (e) {
    error.value = e.message || '登录失败'
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f5f6f8; }
.login-card { width: 360px; background: #fff; border-radius: 10px; padding: 30px; box-shadow: 0 8px 30px rgba(0,0,0,.1); }
.login-card h1 { font-size: 20px; text-align: center; }
.login-card .sub { color: #6b7280; font-size: 13px; text-align: center; margin: 6px 0 18px; }
.login-card input { width: 100%; padding: 9px 10px; font-size: 14px; }
</style>
