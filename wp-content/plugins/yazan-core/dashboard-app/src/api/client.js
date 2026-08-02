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

/** In-flight nonce renewal, shared so a burst of failures triggers one request, not one each. */
let renewal = null

/**
 * Mint a fresh `wp_rest` nonce from the session cookie alone.
 *
 * The nonce printed into the page shell is only good for 12–24 hours, and it is also bound to the
 * login session: expiring or re-issuing the auth cookie (signing in again in another tab) leaves an
 * open dashboard holding a nonce the server no longer accepts. Core's `rest-nonce` admin-ajax
 * action is the one endpoint that will renew it, because — unlike every REST route — it is not
 * itself gated on a nonce.
 *
 * @returns {Promise<string>} The new nonce, or '' when the session is genuinely gone.
 */
async function renewNonce() {
  if (renewal) return renewal

  renewal = (async () => {
    try {
      const response = await fetch(boot.nonceUrl, { credentials: 'same-origin' })
      if (!response.ok) return '' // Logged out: admin-ajax answers 400 for an action it won't run.
      const value = (await response.text()).trim()
      // A bare '0'/'-1' is admin-ajax's "no" — never treat it as a nonce.
      if (!value || value === '0' || value === '-1') return ''
      setNonce(value)
      return value
    } catch {
      return '' // Offline or blocked — indistinguishable from here, and handled the same way.
    } finally {
      renewal = null
    }
  })()

  return renewal
}

/**
 * Fired when the server says the session is gone or the account is suspended.
 *
 * A DOM event rather than a context import: this module must stay free of React so it can be used
 * from anywhere, and the auth provider is the only thing that needs to react.
 */
export const AUTH_LOST_EVENT = 'yazan:auth-lost'

/**
 * Guards the one-shot silent reload below against looping forever.
 *
 * sessionStorage, not a module-level variable: the reload we are guarding against wipes JS memory,
 * so only storage that survives a reload can actually count attempts across it.
 */
const RELOAD_GUARD_KEY = 'yazan_dash_reload_guard'

/**
 * The self-heal every mature site does silently when a session turns out to be unrecoverable:
 * reload, once. The page is served with nocache_headers() (class-yazan-dashboard.php), so the
 * reload is guaranteed a genuinely fresh document — new nonce, current cookie state read from
 * scratch — which is exactly what a manual hard-refresh accomplishes, minus the visitor ever
 * needing to know that trick exists.
 *
 * The guard exists for the one case a reload can't fix: cookies actually blocked or unsupported in
 * this browser. Without it that would reload forever; with it, the second failure in the same tab
 * falls through to the plain sign-in screen and its friendly copy instead.
 *
 * @returns {boolean} True if a reload was triggered (caller should stop — the page is going away).
 */
function reloadOnceOrGiveUp() {
  if (sessionStorage.getItem(RELOAD_GUARD_KEY)) return false

  try {
    sessionStorage.setItem(RELOAD_GUARD_KEY, '1')
  } catch {
    return false // Storage unavailable (private mode, quota) — can't guard, so don't risk a loop.
  }

  window.location.reload()
  return true
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

async function request(path, options = {}, isReplay = false) {
  const { method = 'GET', params, body, formData, skipNonce = false } = options
  // Most calls need X-WP-Nonce — it IS the CSRF protection for cookie auth, never strip it here.
  // The one exception is login: that route is deliberately public (the credentials themselves are
  // the proof), so it has nothing to gain from a nonce and everything to lose from a stale one —
  // core's global cookie-nonce gate (rest_cookie_check_errors) runs BEFORE any route's own
  // permission_callback, so a nonce left over from a browser's earlier, unrelated session against
  // this same origin can fail that gate and block sign-in before our code ever sees the request.
  // Omitting the header entirely takes the login POST down core's other path instead: no nonce
  // present -> treated as anonymous -> always let through, whatever stale cookie is sitting there.
  const headers = skipNonce ? {} : { 'X-WP-Nonce': nonce }
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
    const code = data && data.code

    /*
     * A stale nonce (aged out, or superseded by a newer login session) is rejected by core at
     * authentication time — before routing — so nothing ran and replaying is safe even for a
     * write. Renew once and repeat the call; if no nonce can be minted the session really is
     * gone, so hand over to the sign-in screen instead of stranding the user on an error card.
     */
    if (response.status === 403 && code === 'rest_cookie_invalid_nonce' && !isReplay) {
      if (await renewNonce()) return request(path, options, true)

      // Renewal itself failed too: not just an aged nonce, the whole cookie/session state this
      // page loaded with is stale. A silent reload re-fetches everything from scratch and is, in
      // the overwhelming majority of cases (browser cookies just fine, session simply moved on),
      // the end of it — the visitor never sees this ever happened.
      if (reloadOnceOrGiveUp()) {
        return new Promise(() => {}) // Page is navigating away; never resolve into a flashed error.
      }

      window.dispatchEvent(new CustomEvent(AUTH_LOST_EVENT, { detail: { code, message } }))
    }

    /*
     * 401 means the cookie or nonce is no longer valid; yazan_suspended means the account was
     * switched off while this tab was open. Both leave the UI showing screens it can no longer
     * use, so tell the app to fall back to the sign-in view.
     *
     * 403 deliberately does NOT trigger this — a forbidden action is a normal outcome for a
     * limited role and must not sign them out.
     */
    if (response.status === 401 || code === 'yazan_suspended') {
      window.dispatchEvent(new CustomEvent(AUTH_LOST_EVENT, { detail: { code, message } }))
    }

    throw new ApiError(message, response.status, code)
  }

  // A clean response proves the session is healthy again — release the one-shot reload guard so a
  // later, genuinely new cookie failure (hours from now, in the same tab) still gets one free
  // silent reload rather than inheriting a flag left over from this unrelated earlier recovery.
  try {
    sessionStorage.removeItem(RELOAD_GUARD_KEY)
  } catch {
    /* Storage unavailable — nothing to release. */
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
