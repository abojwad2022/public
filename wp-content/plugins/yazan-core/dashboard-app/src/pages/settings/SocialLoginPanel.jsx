import { useCallback, useEffect, useMemo, useState } from 'react'
import { socialAuthApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Badge, Button, Card, Field, Input, Spinner, Textarea } from '../../components/ui/index.js'

/**
 * Google / Apple one-tap sign-in credentials.
 *
 * Write-only on purpose: the API never returns a stored secret, so this form cannot pre-fill one.
 * A blank secret field therefore means "leave whatever is saved alone", not "clear it" — the copy
 * says so, and clearing is an explicit button. Non-secret values (client ids, team/key ids) DO come
 * back, because being able to eyeball them against the provider console is worth more than
 * pretending a value published in every authorisation URL is confidential.
 */

const PROVIDERS = [
  {
    key: 'google',
    label: 'Google',
    blurb: 'Free. The openid/email/profile scopes used here need no review from Google.',
    consoleUrl: 'https://console.cloud.google.com/apis/credentials',
    consoleLabel: 'Google Cloud console',
    fields: [
      { name: 'client_id', label: 'Client ID', help: 'Ends in .apps.googleusercontent.com' },
      { name: 'client_secret', label: 'Client secret', help: 'Starts with GOCSPX-' },
    ],
  },
  {
    key: 'apple',
    label: 'Apple',
    blurb: 'Requires a paid Apple Developer Program membership and a public HTTPS domain.',
    consoleUrl: 'https://developer.apple.com/account/resources/identifiers/list/serviceId',
    consoleLabel: 'Apple developer portal',
    fields: [
      { name: 'client_id', label: 'Services ID', help: 'The Services ID (e.g. com.yazan.web) — NOT the App ID.' },
      { name: 'team_id', label: 'Team ID', help: 'Top-right in the Apple developer portal.' },
      { name: 'key_id', label: 'Key ID', help: 'The ID of the “Sign in with Apple” key.' },
      {
        name: 'private_key',
        label: 'Private key (.p8)',
        help: 'Paste the whole file, BEGIN/END lines included. Apple lets you download it only once.',
        multiline: true,
      },
      {
        name: 'private_key_path',
        label: '…or path to the .p8 file',
        help: 'Absolute server path. Preferred if you can keep the file outside the webroot.',
      },
    ],
  },
]

export default function SocialLoginPanel() {
  const toast = useToast()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [edits, setEdits] = useState({})

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await socialAuthApi.get())
      setEdits({})
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const dirtyCount = useMemo(
    () => Object.values(edits).reduce((n, fields) => n + Object.keys(fields).length, 0),
    [edits]
  )

  const setValue = (provider, field, value) =>
    setEdits((current) => ({ ...current, [provider]: { ...(current[provider] || {}), [field]: value } }))

  const save = async () => {
    if (dirtyCount === 0) return
    setSaving(true)
    try {
      const result = await socialAuthApi.save(edits)
      setData(result)
      setEdits({})
      if (result.skipped?.length) {
        toast.error(`Locked by wp-config.php, not saved: ${result.skipped.join(', ')}`)
      } else {
        toast.success(`${result.saved.length} field${result.saved.length === 1 ? '' : 's'} saved.`)
      }
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  if (loading || !data) return <Spinner label="Loading sign-in settings…" />

  return (
    <div className="space-y-4">
      <p className="yz-card p-3 text-xs text-muted">
        Adds <strong className="text-fg">Continue with Google</strong> and{' '}
        <strong className="text-fg">Continue with Apple</strong> to the account and checkout pages.
        A provider stays hidden until every one of its fields below is filled in, so a half-finished
        setup can never reach customers. Secrets are <strong className="text-fg">encrypted before
        they are stored</strong> and are never sent back to this screen — a blank secret box means
        “keep what is saved”, not “erase it”.
      </p>

      {data.notes?.base_is_local && (
        <p className="yz-card p-3 text-xs text-warn border-s-2 border-warn">
          This site is on <code>{hostOf(data.notes.base_url)}</code>. Neither provider accepts a
          <code> .local</code> hostname, so sign-in cannot complete here no matter what you enter.
          Use Local’s <strong className="text-fg">Live Link</strong> for an HTTPS address (works for
          both), or Google alone over <code>http://localhost:PORT</code>, then point{' '}
          <code>YAZAN_SOCIAL_AUTH_BASE_URL</code> at it in <code>wp-config.php</code>.
        </p>
      )}

      {PROVIDERS.map((provider) => {
        const state = data.providers?.[provider.key]
        if (!state) return null

        return (
          <Card
            key={provider.key}
            title={
              <span className="flex items-center gap-2">
                {provider.label} sign-in
                <Badge tone={state.active ? 'ok' : 'muted'}>{state.active ? 'Active' : 'Not configured'}</Badge>
              </span>
            }
          >
            <p className="text-xs text-muted mb-4">
              {provider.blurb}{' '}
              <a href={provider.consoleUrl} target="_blank" rel="noreferrer" className="text-gold hover:underline">
                {provider.consoleLabel} ↗
              </a>
            </p>

            <Field
              label="Redirect URI"
              help="Copy this into the provider console exactly — including the trailing slash. A mismatch here is the usual cause of a failed first setup."
              className="mb-4"
            >
              <div className="flex gap-2">
                <Input value={state.redirect_uri} readOnly onFocus={(e) => e.target.select()} />
                <Button small onClick={() => copy(state.redirect_uri, toast)}>
                  Copy
                </Button>
              </div>
            </Field>

            <div className="grid sm:grid-cols-2 gap-5">
              {provider.fields.map((field) => {
                const meta = state.fields?.[field.name] || {}
                const edited = edits[provider.key]?.[field.name]
                const isDirty = edited !== undefined
                const Control = field.multiline ? Textarea : Input

                return (
                  <Field
                    key={field.name}
                    className={`${field.multiline ? 'sm:col-span-2' : ''} ${isDirty ? 'border-s-2 border-warn ps-3' : ''}`}
                    label={
                      <span className="flex items-center gap-2">
                        {field.label}
                        {isDirty && <span className="text-warn">•</span>}
                        {meta.locked && <Badge tone="muted">wp-config</Badge>}
                        {!meta.locked && meta.secret && meta.set && (
                          <Badge tone="ok">saved · {meta.length} chars</Badge>
                        )}
                      </span>
                    }
                    help={
                      meta.locked
                        ? `Pinned by ${meta.constant} in wp-config.php. Remove that line to edit it here.`
                        : field.help
                    }
                  >
                    <Control
                      rows={field.multiline ? 5 : undefined}
                      type={meta.secret && !field.multiline ? 'password' : 'text'}
                      autoComplete="off"
                      spellCheck={false}
                      disabled={meta.locked}
                      placeholder={meta.secret && meta.set ? '•••••••• (leave blank to keep)' : ''}
                      value={isDirty ? edited : meta.secret ? '' : (meta.value ?? '')}
                      onChange={(e) => setValue(provider.key, field.name, e.target.value)}
                    />
                  </Field>
                )
              })}
            </div>

            {provider.key === 'apple' && (
              <p className="text-xs text-faint mt-4">
                Give either the pasted key <em>or</em> the file path — not both. Apple’s client secret
                is generated fresh on every request, so there is nothing to rotate later.
              </p>
            )}
          </Card>
        )
      })}

      <div className="flex items-center justify-end gap-2">
        {dirtyCount > 0 && (
          <Button onClick={() => setEdits({})} disabled={saving}>
            Discard
          </Button>
        )}
        <Button variant="primary" onClick={save} disabled={saving || dirtyCount === 0}>
          {saving ? 'Saving…' : dirtyCount > 0 ? `Save ${dirtyCount} field${dirtyCount === 1 ? '' : 's'}` : 'Save'}
        </Button>
      </div>
    </div>
  )
}

function hostOf(url) {
  try {
    return new URL(url).host
  } catch {
    return url
  }
}

function copy(text, toast) {
  navigator.clipboard?.writeText(text).then(
    () => toast.success('Redirect URI copied.'),
    () => toast.error('Could not copy — select the field and copy manually.')
  )
}
