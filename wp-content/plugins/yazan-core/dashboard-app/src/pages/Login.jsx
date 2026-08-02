import { useState } from 'react'
import { useAuth } from '../context/AuthContext.jsx'
import { Alert, Button, Checkbox, Field, Input } from '../components/ui/index.js'

/**
 * Standalone sign-in — the dashboard's own front door, outside wp-admin.
 * Credentials POST to yazan/v1/auth/login which wraps wp_signon(); on success WordPress
 * sets its normal session cookie and we adopt the freshly-minted REST nonce.
 */
export default function Login() {
  const { login, busy } = useAuth()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [error, setError] = useState('')

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    try {
      await login(username, password, remember)
    } catch (err) {
      // client.js already tries a silent reload before a cookie/nonce failure ever reaches here —
      // this branch only fires for a browser that genuinely can't hold cookies (blocked, private
      // mode with storage disabled, etc.), where reloading again would just repeat the same result.
      // WordPress's own wording ("Cookie check failed") is written for a developer reading a log,
      // not a shopper staring at a sign-in form, so it is replaced rather than shown verbatim.
      setError(
        err.code === 'rest_cookie_invalid_nonce'
          ? 'Your browser blocked or cleared a required cookie, so the session could not be verified. Please enable cookies for this site and try again.'
          : err.message || 'Sign in failed.'
      )
    }
  }

  return (
    <div id="yz-main" className="flex min-h-screen items-center justify-center bg-canvas p-5">
      <div className="w-full max-w-sm">
        <div className="mb-7 text-center">
          {/* tracking-wide, not the old 0.24em: heavy tracking severs Arabic
              letter joins, and this wordmark sits above a bilingual product. */}
          <span className="font-display text-3xl tracking-wide text-fg">YAZAN</span>
          <p className="mt-1.5 text-xs text-faint">Store Manager</p>
        </div>

        <form onSubmit={submit} className="yz-card space-y-4 p-6">
          {error && (
            <Alert tone="danger" title="Sign in failed">
              {error}
            </Alert>
          )}

          <Field label="Username or email" htmlFor="yz-user">
            <Input
              id="yz-user"
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              autoComplete="username"
              autoFocus
              required
            />
          </Field>

          <Field label="Password" htmlFor="yz-pass">
            <Input
              id="yz-pass"
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="current-password"
              required
            />
          </Field>

          <Checkbox label="Keep me signed in" checked={remember} onChange={setRemember} />

          <Button type="submit" variant="primary" size="lg" loading={busy} className="w-full">
            {busy ? 'Signing in…' : 'Sign in'}
          </Button>

          <p className="pt-1 text-center text-xs text-faint">
            Access requires a WooCommerce Shop Manager or Administrator account.
          </p>
        </form>
      </div>
    </div>
  )
}
