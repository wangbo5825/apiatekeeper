/** 会话状态（main.js 路由守卫依赖） */
export const authState = { loaded: false, installed: false, authed: false }

export async function refreshStatus() {
  try {
    const resp = await fetch('/admin/api/status', { credentials: 'same-origin' })
    const s = await resp.json().catch(() => ({}))
    authState.loaded = true
    authState.installed = !!s.installed
    authState.authed = !!s.authed
  } catch (e) {
    authState.loaded = false
  }
  return authState
}

export async function login(username, password) {
  const resp = await fetch('/admin/api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ username, password }),
  })
  const data = await resp.json().catch(() => ({}))
  if (!resp.ok) {
    const err = new Error(data.error || `登录失败（HTTP ${resp.status}）`)
    err.unauthorized = resp.status === 401
    throw err
  }
  authState.installed = true
  authState.authed = true
  return data
}

export async function logout() {
  try {
    await fetch('/admin/api/logout', { method: 'POST', credentials: 'same-origin' })
  } catch (e) { /* 忽略 */ }
  authState.authed = false
}

export async function install(username, password) {
  const resp = await fetch('/admin/api/install', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ username, password }),
  })
  const data = await resp.json().catch(() => ({}))
  if (!resp.ok) throw new Error(data.error || `初始化失败（HTTP ${resp.status}）`)
  authState.installed = true
  return data
}

async function request(method, path, body) {
  const resp = await fetch('/admin/api' + path, {
    method,
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  if (resp.status === 401) {
    authState.authed = false
    const err = new Error('未登录或会话已过期')
    err.unauthorized = true
    throw err
  }
  const data = await resp.json().catch(() => ({}))
  if (!resp.ok) throw new Error(data.error || `HTTP ${resp.status}`)
  return data
}

/** 文本响应（如 Prometheus metrics） */
async function rawText(path) {
  const resp = await fetch('/admin/api' + path, { method: 'GET', credentials: 'same-origin' })
  if (resp.status === 401) {
    authState.authed = false
    const err = new Error('未登录或会话已过期')
    err.unauthorized = true
    throw err
  }
  const text = await resp.text()
  if (!resp.ok) throw new Error(text.slice(0, 200) || `HTTP ${resp.status}`)
  return text
}

/** 应用级 REST 客户端：/admin/api/apps/{id}/... */
function appClient(appId) {
  const base = `/apps/${appId}`
  return {
    get: (res, id) => request('GET', `${base}/${res}${id !== undefined ? '/' + id : ''}`),
    create: (res, body) => request('POST', `${base}/${res}`, body),
    update: (res, id, body) => request('PUT', `${base}/${res}/${id}`, body),
    remove: (res, id) => request('DELETE', `${base}/${res}/${id}`),
  }
}

export const api = {
  login,
  logout,
  install,
  health: () => request('GET', '/health'),

  apps: () => request('GET', '/apps'),
  createApp: (data) => request('POST', '/apps', data),
  updateApp: (id, data) => request('PUT', `/apps/${id}`, data),
  deleteApp: (id) => request('DELETE', `/apps/${id}`),

  stats: () => request('GET', '/stats'),
  aggregate: () => request('POST', '/stats', { bucket: 'hour' }),
  metrics: () => rawText('/metrics'),
  purge: (key) => request('POST', '/cache', { key: key || '*' }),
  systemStatus: () => request('GET', '/system'),
  changePassword: (oldPassword, newPassword) => request('POST', '/password', { old_password: oldPassword, new_password: newPassword }),

  app: (appId) => appClient(appId),
}
