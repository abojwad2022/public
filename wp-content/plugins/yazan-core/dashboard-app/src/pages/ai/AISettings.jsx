import { useCallback, useEffect, useState } from 'react'
import { aiApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { PageHeader } from '../../components/Layout.jsx'
import {
  StatTile,
  useConfirm,
  Badge,
  Button,
  Card,
  Field,
  Input,
  Select,
  Spinner,
  TBody,
  TD,
  TH,
  THead,
  TR,
  Table,
  Textarea,
} from '../../components/ui/index.js'

/**
 * Provider Settings + Diagnostics.
 *
 * The one place to add API keys (write-only — the server never returns them), choose the default
 * provider and per-capability models, set the fallback chain and budget, test a connection, and read
 * the generation ledger. Everything here maps 1:1 to yazan/v1/ai/*.
 */
export default function AISettings() {
  const toast = useToast()
  const [data, setData] = useState(null)
  const [settings, setSettings] = useState(null)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState('')
  const [testResult, setTestResult] = useState({})
  const [testingAll, setTestingAll] = useState(false)
  const [healthAll, setHealthAll] = useState(null)

  const load = useCallback(async () => {
    try {
      const res = await aiApi.getSettings()
      setData(res)
      setSettings(res.settings)
    } catch (err) {
      toast.error(err.message)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  if (!data || !settings) return <Spinner label="Loading AI settings…" />

  const patch = (key, value) => setSettings((s) => ({ ...s, [key]: value }))
  const patchModel = (provider, cap, value) =>
    setSettings((s) => ({
      ...s,
      models: { ...s.models, [provider]: { ...s.models[provider], [cap]: value } },
    }))
  const patchPrompt = (key, value) =>
    setSettings((s) => ({ ...s, prompts: { ...(s.prompts || {}), [key]: value } }))
  const patchCore = (key, value) =>
    setSettings((s) => ({ ...s, ai_core: { ...(s.ai_core || {}), [key]: value } }))
  const patchSupport = (key, value) =>
    setSettings((s) => ({ ...s, support: { ...(s.support || {}), [key]: value } }))
  const patchCustom = (key, value) =>
    setSettings((s) => ({ ...s, custom_provider: { ...(s.custom_provider || {}), [key]: value } }))
  const patchTaskModel = (task, provider, value) =>
    setSettings((s) => ({
      ...s,
      task_models: { ...(s.task_models || {}), [task]: { ...((s.task_models || {})[task] || {}), [provider]: value } },
    }))

  const testAll = async () => {
    setTestingAll(true)
    try {
      const res = await aiApi.testAll()
      setHealthAll(res.results || [])
    } catch (err) {
      toast.error(err.message)
    } finally {
      setTestingAll(false)
    }
  }

  const toggleFallback = (id) => {
    const has = settings.fallback.includes(id)
    const next = has ? settings.fallback.filter((p) => p !== id) : [...settings.fallback, id]
    patch('fallback', next)
  }

  const save = async () => {
    setSaving(true)
    try {
      const res = await aiApi.saveSettings(settings)
      setSettings(res.settings)
      toast.success('AI settings saved.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  const test = async (provider) => {
    setTesting(provider)
    try {
      const res = await aiApi.test(provider)
      setTestResult((r) => ({ ...r, [provider]: res }))
      if (res.ok) toast.success(`${provider}: OK · ${res.latency_ms}ms · ${res.model}`)
      else toast.error(`${provider}: ${res.error?.message || 'failed'}`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setTesting('')
    }
  }

  const usage = data.usage || {}
  const budgetPct =
    usage.month_budget > 0 ? Math.min(100, Math.round((usage.month_cost / usage.month_budget) * 100)) : 0

  return (
    <>
      <PageHeader
        title="AI Settings"
        subtitle="Providers, models, fallback, and budget for the AI Store Manager"
        actions={
          <Button variant="primary" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save settings'}
          </Button>
        }
      />

      {/* Usage snapshot */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
        <StatTile label="This month · cost" value={`$${Number(usage.month_cost || 0).toFixed(4)}`}  />
        <StatTile label="This month · calls" value={usage.month_calls ?? 0} />
        <StatTile label="Monthly budget" value={usage.month_budget > 0 ? `$${usage.month_budget}` : 'No cap'} />
        <StatTile label="Budget used" value={usage.month_budget > 0 ? `${budgetPct}%` : '—'} tone={budgetPct>= 90 ? 'danger' : undefined} />
      </div>

      <div className="grid lg:grid-cols-2 gap-5 items-start">
        {/* Provider keys */}
        <Card
          title="Provider API keys"
          actions={
            <Button small variant="ghost" onClick={testAll} disabled={testingAll}>
              {testingAll ? 'Testing…' : 'Test all'}
            </Button>
          }
        >
          <p className="text-xs text-faint mb-4">
            Keys are stored server-side and never sent back to the browser. A <code>wp-config.php</code>{' '}
            constant (e.g. <code>YAZAN_AI_OPENROUTER_KEY</code>) always overrides a saved key.
          </p>
          {healthAll && (
            <div className="flex flex-wrap gap-2 mb-4">
              {healthAll.map((h) => (
                <span
                  key={h.provider}
                  className={`yz-badge ${!h.is_set ? 'border-edge text-faint' : h.ok ? 'border-ok text-ok' : 'border-danger text-danger'}`}
                  title={h.error?.message || ''}
                >
                  {h.provider}
                  {!h.is_set ? ' · no key' : h.ok ? ` · ${h.latency_ms}ms` : ` · ${h.error?.code || 'error'}`}
                </span>
              ))}
            </div>
          )}
          <div className="flex flex-col gap-4">
            {data.providers.map((p) => {
              const cred = data.credentials.find((c) => c.provider === p.id) || {}
              const res = testResult[p.id]
              return (
                <ProviderKeyRow
                  key={p.id}
                  provider={p}
                  cred={cred}
                  testing={testing === p.id}
                  result={res}
                  onSaved={load}
                  onTest={() => test(p.id)}
                  toast={toast}
                />
              )
            })}
          </div>
        </Card>

        {/* Routing + generation */}
        <div className="flex flex-col gap-5">
          <Card title="Routing">
            <div className="flex flex-col gap-4">
              <Field label="Default provider">
                <Select value={settings.default_provider} onChange={(e) => patch('default_provider', e.target.value)}>
                  {data.providers.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.label}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Fallback chain" help="Tried in order when the default fails, is rate-limited, or is over budget.">
                <div className="flex flex-wrap gap-2">
                  {data.providers.map((p) => {
                    const on = settings.fallback.includes(p.id)
                    return (
                      <button
                        key={p.id}
                        type="button"
                        onClick={() => toggleFallback(p.id)}
                        className={`yz-badge ${on ? 'border-gold text-gold' : 'border-edge text-muted'}`}
                      >
                        {on ? `${settings.fallback.indexOf(p.id) + 1}. ` : ''}
                        {p.label}
                      </button>
                    )
                  })}
                </div>
              </Field>
            </div>
          </Card>

          <Card title="Generation defaults">
            <div className="grid sm:grid-cols-2 gap-4">
              <Field label="Output language">
                <Select value={settings.language} onChange={(e) => patch('language', e.target.value)}>
                  <option value="both">Bilingual (EN + AR)</option>
                  <option value="en">English</option>
                  <option value="ar">Arabic</option>
                </Select>
              </Field>
              <Field label="Temperature" help="0 = precise, 2 = creative">
                <Input
                  type="number"
                  step="0.1"
                  min="0"
                  max="2"
                  value={settings.temperature}
                  onChange={(e) => patch('temperature', e.target.value)}
                />
              </Field>
              <Field label="Max tokens">
                <Input type="number" min="64" max="8192" value={settings.max_tokens} onChange={(e) => patch('max_tokens', e.target.value)} />
              </Field>
              <Field label="Monthly budget (USD)" help="0 = no cap">
                <Input type="number" step="1" min="0" value={settings.monthly_budget} onChange={(e) => patch('monthly_budget', e.target.value)} />
              </Field>
              <Field label="Per-user hourly limit" help="0 = no limit">
                <Input type="number" min="0" value={settings.user_rate_limit} onChange={(e) => patch('user_rate_limit', e.target.value)} />
              </Field>
              <Field label="Response cache">
                <Select value={settings.cache_enabled ? '1' : '0'} onChange={(e) => patch('cache_enabled', e.target.value === '1')}>
                  <option value="1">Enabled</option>
                  <option value="0">Disabled</option>
                </Select>
              </Field>
            </div>
          </Card>
        </div>
      </div>

      {/* Models */}
      <Card title="Models per provider" className="mt-5">
        <p className="text-xs text-faint mb-4">
          Override the model id used for text (descriptions, SEO, marketing, chat) and vision (photo
          analysis) tasks. Leave a field to fall back to the built-in default.
        </p>
        <div className="overflow-x-auto">
          <Table>
            <THead>
              <TR>
                <TH>Provider</TH>
                <TH>Text model</TH>
                <TH>Vision model</TH>
              </TR>
            </THead>
            <TBody>
              {data.providers.map((p) => (
                <TR key={p.id}>
                  <TD className="text-fg">{p.label}</TD>
                  <TD>
                    <Input value={settings.models[p.id]?.text || ''} onChange={(e) => patchModel(p.id, 'text', e.target.value)} placeholder={p.default_models?.text} />
                  </TD>
                  <TD>
                    <Input value={settings.models[p.id]?.vision || ''} onChange={(e) => patchModel(p.id, 'vision', e.target.value)} placeholder={p.default_models?.vision} />
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        </div>
      </Card>

      {/* Per-task model overrides */}
      {Array.isArray(data.tasks) && data.tasks.length > 0 && (
        <Card title="Model per task" className="mt-5">
          <p className="text-xs text-faint mb-4">
            Optional. Use a different model per task (e.g. a fast, cheap model for chat and a stronger
            one for product reasoning). Leave a cell blank to inherit the provider default above.
          </p>
          <div className="overflow-x-auto">
            <Table>
              <THead>
                <TR>
                  <TH>Task</TH>
                  {data.providers.map((p) => (
                    <TH key={p.id}>{p.label}</TH>
                  ))}
                </TR>
              </THead>
              <TBody>
                {data.tasks.map((t) => (
                  <TR key={t.key}>
                    <TD className="text-fg whitespace-nowrap">{t.label}</TD>
                    {data.providers.map((p) => {
                      const base =
                        t.key === 'product'
                          ? settings.models[p.id]?.vision
                          : settings.models[p.id]?.text
                      return (
                        <TD key={p.id}>
                          <Input
                            value={settings.task_models?.[t.key]?.[p.id] || ''}
                            onChange={(e) => patchTaskModel(t.key, p.id, e.target.value)}
                            placeholder={base}
                          />
                        </TD>
                      )
                    })}
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
          <div className="mt-4">
            <Button variant="primary" onClick={save} disabled={saving}>
              {saving ? 'Saving…' : 'Save settings'}
            </Button>
          </div>
        </Card>
      )}

      {/* Bring-your-own provider — add ANY other model without code */}
      <Card title="Add another model (custom provider)" className="mt-5">
        <p className="text-xs text-faint mb-4">
          Plug in any service that speaks the OpenAI chat API — DeepSeek, Mistral, Together, a self-hosted
          gateway, or a new vendor. Set the endpoint here, paste its key in the <b>Custom</b> card above, then
          type the model id in the <b>Models per provider</b> table below. HTTPS required (except localhost).
        </p>
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Display name" help="Shown in the provider list, e.g. “DeepSeek”.">
            <Input
              value={settings.custom_provider?.label || ''}
              onChange={(e) => patchCustom('label', e.target.value)}
              placeholder="DeepSeek"
            />
          </Field>
          <Field label="API base URL" help="Must end at /v1 (e.g. https://api.deepseek.com/v1).">
            <Input
              value={settings.custom_provider?.base_url || ''}
              onChange={(e) => patchCustom('base_url', e.target.value)}
              placeholder="https://api.deepseek.com/v1"
            />
          </Field>
          <Field label="Accepts images (vision)">
            <Select
              value={settings.custom_provider?.vision ? '1' : '0'}
              onChange={(e) => patchCustom('vision', e.target.value === '1')}
            >
              <option value="0">No — text only</option>
              <option value="1">Yes — the model can read images</option>
            </Select>
          </Field>
        </div>
        <div className="mt-4">
          <Button variant="primary" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save settings'}
          </Button>
        </div>
      </Card>

      <AICoreCard data={data} settings={settings} patchCore={patchCore} onSave={save} saving={saving} toast={toast} onReload={load} />

      {/* Human-support handoff for the storefront concierge */}
      <Card title="Human support (concierge handoff)" className="mt-5">
        <p className="text-xs text-faint mb-4">
          Let shoppers reach a person from the concierge. When enabled, a “Talk to a person” option emails the
          conversation to you, optionally posts it to your CRM, and can open WhatsApp.
        </p>
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Enable">
            <Select
              value={settings.support?.enabled ? '1' : '0'}
              onChange={(e) => patchSupport('enabled', e.target.value === '1')}
            >
              <option value="0">Off</option>
              <option value="1">On — show “Talk to a person”</option>
            </Select>
          </Field>
          <Field label="WhatsApp number" help="International format, digits only (e.g. 9677xxxxxxx). Blank hides WhatsApp.">
            <Input
              value={settings.support?.whatsapp || ''}
              onChange={(e) => patchSupport('whatsapp', e.target.value)}
              placeholder="9677xxxxxxx"
            />
          </Field>
          <Field label="Support email" help="Where transcripts are sent. Blank = your order-alert email.">
            <Input
              type="email"
              value={settings.support?.email || ''}
              onChange={(e) => patchSupport('email', e.target.value)}
              placeholder="care@example.com"
            />
          </Field>
          <Field label="CRM webhook URL" help="Optional. An HTTPS endpoint that receives the transcript as JSON.">
            <Input
              value={settings.support?.crm_webhook_url || ''}
              onChange={(e) => patchSupport('crm_webhook_url', e.target.value)}
              placeholder="https://…"
            />
          </Field>
        </div>
        <div className="mt-4">
          <Button variant="primary" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save settings'}
          </Button>
        </div>
      </Card>

      {/* Prompts & instructions — owner-authored guidance injected into every generation. */}
      <Card title="Prompts & instructions" className="mt-5">
        <p className="text-xs text-faint mb-4">
          Optional. These steer how the AI writes. Leave any field blank to use the built-in YAZAN
          brand voice. The per-content fields add to the description/SEO/marketing prompts.{' '}
          <a
            href="/wp-admin/edit.php?post_type=yazan_kb"
            target="_blank"
            rel="noreferrer"
            className="text-gold hover:underline"
          >
            Manage House Knowledge →
          </a>{' '}
          (facts the concierge &amp; content draw on).
        </p>
        <div className="grid lg:grid-cols-2 gap-4">
          <Field
            label="Brand voice additions"
            help="Applied to every generation — house tone, words to prefer or avoid."
            className="lg:col-span-2"
          >
            <Textarea
              value={settings.prompts?.voice || ''}
              onChange={(e) => patchPrompt('voice', e.target.value)}
              rows={3}
              placeholder="e.g. Speak with restrained confidence. Never mention discounts. Prefer “heritage”, “provenance”, “one-of-one”."
            />
          </Field>
          <Field label="Product description prompt" help="How product descriptions & stories should read.">
            <Textarea
              value={settings.prompts?.product || ''}
              onChange={(e) => patchPrompt('product', e.target.value)}
              rows={4}
              placeholder="e.g. Two short paragraphs. Open with the stone's character, then the silverwork. End with who it's for."
            />
          </Field>
          <div className="grid gap-4">
            <Field label="SEO prompt">
              <Textarea
                value={settings.prompts?.seo || ''}
                onChange={(e) => patchPrompt('seo', e.target.value)}
                rows={2}
                placeholder="e.g. Lead the title with the stone type. Keep meta descriptions inviting, not clickbait."
              />
            </Field>
            <Field label="Marketing prompt">
              <Textarea
                value={settings.prompts?.marketing || ''}
                onChange={(e) => patchPrompt('marketing', e.target.value)}
                rows={2}
                placeholder="e.g. Calm, editorial tone. One tasteful emoji at most. No fake urgency."
              />
            </Field>
          </div>
          <Field
            label="Concierge chat prompt"
            help="How the storefront sales assistant talks to shoppers — tone, what to emphasise, when to ask."
            className="lg:col-span-2"
          >
            <Textarea
              value={settings.prompts?.chat || ''}
              onChange={(e) => patchPrompt('chat', e.target.value)}
              rows={3}
              placeholder="e.g. Greet warmly, ask one question at most, lead with the stone's story, always mention lifetime authenticity."
            />
          </Field>
        </div>
        <div className="mt-4">
          <Button variant="primary" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save settings'}
          </Button>
        </div>
      </Card>

      <Diagnostics toast={toast} />
    </>
  )
}

function AICoreCard({ data, settings, patchCore, onSave, saving, toast, onReload }) {
  const core = settings.ai_core || { enabled: false, url: '' }
  const cred = (data.credentials || []).find((c) => c.provider === 'core') || {}
  const active = data.ai_core?.active
  const [secret, setSecret] = useState('')
  const [savingSecret, setSavingSecret] = useState(false)
  const [testing, setTesting] = useState(false)
  const [result, setResult] = useState(null)

  const saveSecret = async () => {
    setSavingSecret(true)
    try {
      await aiApi.setCredential('core', secret)
      setSecret('')
      toast.success(secret.trim() ? 'Shared secret saved.' : 'Shared secret cleared.')
      onReload()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSavingSecret(false)
    }
  }

  const test = async () => {
    setTesting(true)
    try {
      const res = await aiApi.testCore()
      setResult(res)
      if (res.ok) toast.success(`AI Core OK · ${res.latency_ms}ms · v${res.version}`)
      else toast.error(res.error || 'AI Core unreachable')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setTesting(false)
    }
  }

  return (
    <Card
      title="AI Core (remote service)"
      className="mt-5"
      actions={active ? <Badge tone="ok">Active</Badge> : <Badge tone="muted">Fallback</Badge>}
    >
      <p className="text-xs text-faint mb-4">
        Route provider execution through a standalone Node service (Phase 3). WordPress still owns keys,
        prompts, budget, and logs. If the service is unreachable, requests automatically fall back to the
        in-WordPress providers — so enabling this is zero-risk.
      </p>
      <div className="grid sm:grid-cols-2 gap-4">
        <Field label="Enable">
          <Select value={core.enabled ? '1' : '0'} onChange={(e) => patchCore('enabled', e.target.value === '1')}>
            <option value="0">Off — use in-WordPress providers</option>
            <option value="1">On — route to the service</option>
          </Select>
        </Field>
        <Field label="Service URL" help="e.g. http://127.0.0.1:8787">
          <Input value={core.url || ''} onChange={(e) => patchCore('url', e.target.value)} placeholder="http://127.0.0.1:8787" />
        </Field>
      </div>
      <div className="flex items-end gap-2 mt-4">
        <Field
          label="Shared secret"
          className="flex-1"
          help={
            cred.is_set
              ? `Set · ••••${cred.last4}${cred.source === 'constant' ? ' · wp-config' : ''}`
              : 'Must match YAZAN_CORE_SECRET on the service'
          }
        >
          <Input
            type="password"
            value={secret}
            onChange={(e) => setSecret(e.target.value)}
            placeholder={cred.is_set ? 'Replace secret (or leave blank)' : 'Paste shared secret'}
            autoComplete="off"
          />
        </Field>
        <Button small onClick={saveSecret} disabled={savingSecret || (!secret.trim() && !cred.is_set)}>
          {secret.trim() ? 'Save' : 'Clear'}
        </Button>
      </div>
      <div className="flex flex-wrap items-center gap-3 mt-4">
        <Button variant="primary" onClick={onSave} disabled={saving}>
          {saving ? 'Saving…' : 'Save settings'}
        </Button>
        <Button onClick={test} disabled={testing}>
          {testing ? 'Testing…' : 'Test Core'}
        </Button>
        {result && (
          <span className={`text-xs ${result.ok ? 'text-ok' : 'text-danger'}`}>
            {result.ok ? `OK · ${result.latency_ms}ms · v${result.version}` : result.error || 'unreachable'}
          </span>
        )}
        <span className="text-xs text-faint">
          {active ? 'Active — generations routed to the service.' : 'Fallback — using in-WordPress providers.'}
        </span>
      </div>
    </Card>
  )
}

function ProviderKeyRow({ provider, cred, testing, result, onSaved, onTest, toast }) {
  const [confirm, confirmDialog] = useConfirm()
  const [value, setValue] = useState('')
  const [editing, setEditing] = useState(false)
  const [busy, setBusy] = useState('') // '' | 'save' | 'delete'

  // A key set through a wp-config.php constant can't be replaced or removed from the UI.
  const isConstant = cred.source === 'constant'
  // Show the paste field when adding a first key, or when the owner clicks Edit to replace one.
  const showInput = !isConstant && (editing || !cred.is_set)

  const save = async () => {
    if (!value.trim()) return
    setBusy('save')
    try {
      await aiApi.setCredential(provider.id, value)
      setValue('')
      setEditing(false)
      toast.success(`${provider.label} key saved.`)
      onSaved()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy('')
    }
  }

  const remove = async () => {
    if (!(await confirm({ title: `Delete the ${provider.label} API key? This cannot be undone.` }))) return
    setBusy('delete')
    try {
      await aiApi.setCredential(provider.id, '') // empty value clears the stored key server-side
      setValue('')
      setEditing(false)
      toast.success(`${provider.label} key deleted.`)
      onSaved()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy('')
    }
  }

  const cancelEdit = () => {
    setEditing(false)
    setValue('')
  }

  return (
    <>
    <div className="border border-edge p-3">
      <div className="flex items-center justify-between mb-2">
        <span className="text-sm text-fg">{provider.label}</span>
        {cred.is_set ? (
          <Badge tone="ok">
            set · ••••{cred.last4}
            {isConstant ? ' · wp-config' : ''}
          </Badge>
        ) : (
          <Badge tone="muted">not set</Badge>
        )}
      </div>

      {showInput ? (
        <div className="flex gap-2">
          <Input
            type="password"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder={cred.is_set ? 'Paste the new key' : 'Paste API key'}
            autoComplete="off"
          />
          <Button small variant="primary" onClick={save} disabled={busy === 'save' || !value.trim()}>
            {busy === 'save' ? 'Saving…' : 'Save'}
          </Button>
          {editing && (
            <Button small variant="ghost" onClick={cancelEdit} disabled={busy === 'save'}>
              Cancel
            </Button>
          )}
        </div>
      ) : (
        <div className="flex gap-2">
          <Button small variant="ghost" onClick={onTest} disabled={testing || !cred.is_set}>
            {testing ? 'Testing…' : 'Test'}
          </Button>
          <Button small variant="ghost" onClick={() => setEditing(true)} disabled={isConstant}>
            Edit
          </Button>
          <Button small variant="ghost" onClick={remove} disabled={isConstant || busy === 'delete'}>
            {busy === 'delete' ? 'Deleting…' : 'Delete'}
          </Button>
        </div>
      )}

      {isConstant && (
        <p className="text-xs text-faint mt-2">Managed in wp-config.php — edit or remove the constant there.</p>
      )}

      {result && (
        <p className={`text-xs mt-2 ${result.ok ? 'text-ok' : 'text-danger'}`}>
          {result.ok ? `OK · ${result.latency_ms}ms · ${result.model}` : `Failed: ${result.error?.message || 'error'}`}
        </p>
      )}
    </div>
      {confirmDialog}
    </>
  )
}

function Diagnostics({ toast }) {
  const [rows, setRows] = useState(null)

  const load = useCallback(async () => {
    try {
      const res = await aiApi.logs({ per_page: 25 })
      setRows(res.items || [])
    } catch (err) {
      toast.error(err.message)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  return (
    <Card
      title="Diagnostics · recent generations"
      className="mt-5"
      bodyClass="p-0"
      actions={
        <Button small variant="ghost" onClick={load}>
          Refresh
        </Button>
      }
    >
      {!rows ? (
        <Spinner label="Loading log…" />
      ) : rows.length === 0 ? (
        <p className="p-6 text-sm text-muted text-center">No AI calls yet. Add a key and run a generation.</p>
      ) : (
        <div className="overflow-x-auto">
          <Table>
            <THead>
              <TR>
                <TH>When</TH>
                <TH>Module</TH>
                <TH>Provider</TH>
                <TH>Model</TH>
                <TH>Status</TH>
                <TH>Tokens</TH>
                <TH>Cost</TH>
                <TH>ms</TH>
              </TR>
            </THead>
            <TBody>
              {rows.map((r) => (
                <TR key={r.id}>
                  <TD className="text-faint whitespace-nowrap">{r.created_at}</TD>
                  <TD>{r.module}</TD>
                  <TD>{r.provider || '—'}</TD>
                  <TD className="font-mono text-xs">{r.model || '—'}</TD>
                  <TD>
                    {r.status === 'ok' ? (
                      <Badge tone={Number(r.cached) ? 'muted' : 'ok'}>{Number(r.cached) ? 'cached' : 'ok'}</Badge>
                    ) : (
                      <Badge tone="danger">{r.error_code || 'error'}</Badge>
                    )}
                  </TD>
                  <TD align="end" className="whitespace-nowrap">
                    {Number(r.tokens_in) + Number(r.tokens_out)}
                  </TD>
                  <TD align="end" className="whitespace-nowrap">${Number(r.cost_usd).toFixed(4)}</TD>
                  <TD align="end" className="text-faint">{r.duration_ms}</TD>
                </TR>
              ))}
            </TBody>
          </Table>
        </div>
      )}
    </Card>
  )
}

