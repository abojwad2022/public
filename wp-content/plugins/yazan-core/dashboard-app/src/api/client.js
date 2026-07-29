/**
 * REST client for the yazan/v1 namespace.
 *
 * Same-origin + cookie auth: every request sends the WordPress session cookie and the
 * `wp_rest` nonce in X-WP-Nonce. WordPress refuses to honour a cookie-authenticated REST
 * request without a valid nonce, so this header IS the CSRF protection — never strip it.
 * No API keys or tokens are ever stored in the browser.
 */

const bootEl = document.getElementById('yazan-boot')
export const boot = bootEl ? JSON.parse(bootEl.textContent) : {}

let nonce = boot.nonce || ''

export function setNonce(value) {
  nonce = value || ''
}

export class ApiError extends Error {
  constructor(message, status, code) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
  }
}

function buildUrl(path, params) {
  const url = new URL(`${boot.restRoot}${path}`, window.location.origin)
  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, value)
      }
    })
  }
  return url.toString()
}

async function request(path, { method = 'GET', params, body, formData } = {}) {
  const headers = { 'X-WP-Nonce': nonce }
  let payload

  if (formData) {
    payload = formData // Browser sets the multipart boundary itself.
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  const response = await fetch(buildUrl(path, params), {
    method,
    headers,
    body: payload,
    credentials: 'same-origin',
  })

  // 204 / empty body
  const text = await response.text()
  const data = text ? safeJson(text) : null

  if (!response.ok) {
    const message = (data && (data.message || data.error)) || `Request failed (${response.status})`
    throw new ApiError(message, response.status, data && data.code)
  }
  return data
}

function safeJson(text) {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}

export const api = {
  get: (path, params) => request(path, { params }),
  post: (path, body) => request(path, { method: 'POST', body }),
  put: (path, body) => request(path, { method: 'PUT', body }),
  del: (path, params) => request(path, { method: 'DELETE', params }),
  upload: (path, formData) => request(path, { method: 'POST', formData }),
}
