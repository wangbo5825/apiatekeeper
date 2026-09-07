<template>
  <div class="login-wrap">
    <form class="install-card" @submit.prevent="submit">
      <h1>ApiGateKeeper</h1>
      <p class="sub">首次使用 · 初始化系统</p>

      <div class="steps">
        <p>检测到这是一套新系统，只需一步即可完成初始化：</p>
        <ol>
          <li>创建管理员账号（用于登录管理端）</li>
          <li>自动创建"默认应用"（base_path=/，缺省放行 + 自动加入）</li>
          <li>尝试注册每分钟的日志导入 cron</li>
        </ol>
      </div>

      <label class="field-label">管理员用户名</label>
      <input v-model.trim="username" autocomplete="username" placeholder="如 admin" :disabled="busy" />

      <label class="field-label" style="margin-top:12px">密码（至少 8 位）</label>
      <input v-model="password" type="password" autocomplete="new-password" placeholder="设置登录密码" :disabled="busy" />

      <label class="field-label" style="margin-top:12px">确认密码</label>
      <input v-model="confirm" type="password" autocomplete="new-password" placeholder="再次输入密码" :disabled="busy" />

      <div v-if="error" class="alert-err" style="margin-top:10px; margin-bottom:0">{{ error }}</div>
      <div v-if="cronMsg" class="alert-line" style="margin-top:10px; margin-bottom:0; white-space:pre-wrap">{{ cronMsg }}</div>

      <button class="primary" type="submit" :disabled="busy" style="width:100%; margin-top:16px">
        {{ busy ? '初始化中…' : '完成初始化' }}
      </button>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { install, login } from '../api'

const router = useRouter()
const username = ref('')
const password = ref('')
const confirm = ref('')
const busy = ref(false)
const error = ref('')
const cronMsg = ref('')

async function submit() {
  error.value = ''
  cronMsg.value = ''
  if (!username.value) {
    error.value = '请输入管理员用户名'
    return
  }
  if (password.value.length < 8) {
    error.value = '密码至少 8 位'
    return
  }
  if (password.value !== confirm.value) {
    error.value = '两次输入的密码不一致'
    return
  }
  busy.value = true
  try {
    const r = await install(username.value, password.value)
    if (!r.cron_ok) {
      cronMsg.value = '系统已初始化。cron 自动注册未成功，请手动添加：\n' + (r.cron || '')
    }
    await login(username.value, password.value)
    router.push('/')
  } catch (e) {
    error.value = e.message || '初始化失败'
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f5f6f8; }
.install-card { width: 420px; background: #fff; border-radius: 10px; padding: 30px; box-shadow: 0 8px 30px rgba(0,0,0,.1); }
.install-card h1 { font-size: 20px; text-align: center; }
.install-card .sub { color: #6b7280; font-size: 13px; text-align: center; margin: 6px 0 16px; }
.install-card input { width: 100%; padding: 9px 10px; font-size: 14px; }
.steps { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 14px; font-size: 13px; color: #1e40af; margin-bottom: 16px; line-height: 1.8; }
.steps ol { padding-left: 18px; }
</style>
