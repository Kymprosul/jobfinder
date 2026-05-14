const API_BASE = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '')

function buildQueryString(query) {
  if (!query) {
    return ''
  }

  const params = new URLSearchParams(
    Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )
  const serialized = params.toString()

  return serialized ? `?${serialized}` : ''
}

function buildHeaders(headers = {}, body) {
  const normalizedHeaders = new Headers(headers)

  if (body !== undefined && !normalizedHeaders.has('Content-Type')) {
    normalizedHeaders.set('Content-Type', 'application/json')
  }

  return Object.fromEntries(normalizedHeaders.entries())
}

async function parseResponse(response) {
  if (response.status === 204) {
    return { success: true }
  }

  const contentType = response.headers.get('content-type') || ''

  if (contentType.includes('application/json')) {
    return response.json()
  }

  const text = (await response.text()).trim()
  if (text === '') {
    return { success: response.ok }
  }

  return {
    success: response.ok,
    error: text,
    message: text,
  }
}

async function request(path, options = {}) {
  const { query, headers, body, ...fetchOptions } = options
  const queryString = buildQueryString(query)

  const response = await fetch(`${API_BASE}${path}${queryString}`, {
    ...fetchOptions,
    body,
    headers: buildHeaders(headers, body),
  })

  const data = await parseResponse(response)

  if (!response.ok || data.success === false) {
    throw new Error(data?.message || data?.error || `Error HTTP ${response.status}`)
  }

  return data
}

export const api = {
  getConfig: () => request('/api/config'),
  saveConfig: (payload) =>
    request('/api/config', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
  getJobs: (query = {}) => request('/api/jobs', { query }),
  rejectJob: (id) =>
    request(`/api/jobs/${encodeURIComponent(String(id))}/reject`, {
      method: 'POST',
      body: JSON.stringify({}),
    }),
  getPreviewJobs: (query = {}) => request('/api/preview', { query }),
  getRuns: () => request('/api/runs'),
  getLogs: () => request('/api/logs'),
  getStatus: () => request('/api/status'),
  runNow: () =>
    request('/api/run', {
      method: 'POST',
      body: JSON.stringify({}),
    }),
  runSource: (source) =>
    request(`/api/run/${encodeURIComponent(source)}`, {
      method: 'POST',
      body: JSON.stringify({}),
    }),
  sendReport: () =>
    request('/api/send-report', {
      method: 'POST',
      body: JSON.stringify({}),
    }),
}
