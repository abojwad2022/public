/**
 * Store creation wizard — five steps.
 *
 * There is no wizard pattern anywhere else in this dashboard, so this defines one. It is a
 * full-page route rather than a modal because every other create flow here (/users/new,
 * /roles/new, /products/new) is a full page, and a five-step form in a modal fights the focus trap.
 *
 * THE ORDER OF THE LAST STEP MATTERS. "Deploy" does not create-and-publish in one motion: it
 * creates a DRAFT with everything configured, and publishing is a second, explicit act. The window
 * between "created" and "configured" is exactly where a half-built store would otherwise be live
 * on the web, and that window is avoidable by construction rather than by being careful.
 */

import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router'

import { storesApi } from '../../api/endpoints.js'
import { PageHeader } from '../../components/Layout.jsx'
import { useToast } from '../../context/ToastContext.jsx'
import {
  Alert, Button, Card, Checkbox, Field, Input, ProgressBar, Select,
} from '../../components/ui/index.js'
import { ArrowLeft, ArrowRight, Check, Rocket, Store } from '../../components/ui/icons.js'

/** Mirrors Store::SLUG_PATTERN on the server — it must be legal as a hostname label AND a path segment. */
const SLUG_RE = /^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$|^[a-z0-9]$/

const STEPS = [
  { key: 'info', label: 'Store information' },
  { key: 'brand', label: 'Branding & theme' },
  { key: 'domain', label: 'Domain' },
  { key: 'modules', label: 'Modules' },
  { key: 'review', label: 'Review & deploy' },
]

const slugify = (value) =>
  value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 63)

export default function StoreWizard() {
  const [step, setStep] = useState(0)
  const [form, setForm] = useState({
    name: '', slug: '', currency: '', locale: '', timezone: '',
    languages: '', theme: '', host: '', type: 'subdomain', path: '',
  })
  const [modules, setModules] = useState(null)
  const [created, setCreated] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const navigate = useNavigate()
  const toast = useToast()

  const set = (key) => (event) => {
    const value = event?.target ? event.target.value : event
    setForm((prev) => {
      // Auto-derive the slug from the name until the user edits it themselves.
      if (key === 'name' && (prev.slug === '' || prev.slug === slugify(prev.name))) {
        return { ...prev, name: value, slug: slugify(value) }
      }
      return { ...prev, [key]: value }
    })
  }

  const slugError = form.slug && !SLUG_RE.test(form.slug)
    ? 'Lowercase letters, digits and hyphens only; must start and end with a letter or digit.'
    : null

  const currencyError = form.currency && !/^[A-Za-z]{3}$/.test(form.currency)
    ? 'Three-letter ISO code, e.g. YER or USD.'
    : null

  const canAdvance = useMemo(() => {
    if (step === 0) return Boolean(form.name.trim() && form.slug && !slugError)
    if (step === 1) return !currencyError
    return true
  }, [step, form, slugError, currencyError])

  /** Load the module catalogue the first time the modules step is reached. */
  const goTo = async (next) => {
    if (next === 3 && modules === null) {
      try {
        // Any store can serve the catalogue — it is platform-wide, not per-store.
        const data = await storesApi.modules(1)
        setModules(data.catalogue)
      } catch {
        setModules({})
      }
    }
    setStep(next)
  }

  /**
   * Create everything as a draft.
   *
   * The domain and module writes need the store to exist first, so a failure part-way leaves a
   * draft that is incomplete but harmless — it has no address in the hostmap and answers nothing.
   */
  const deploy = async () => {
    setBusy(true)
    setError(null)
    try {
      const store = await storesApi.create({
        name: form.name.trim(),
        slug: form.slug,
        currency: form.currency.toUpperCase(),
        locale: form.locale,
        timezone: form.timezone,
        languages: form.languages,
        theme: form.theme,
      })

      if (form.host.trim()) {
        await storesApi.addDomain(store.id, {
          host: form.host.trim(),
          path: form.type === 'path' ? form.path : '',
          type: form.type,
          is_primary: true,
        })
      }

      if (modules) {
        const off = Object.entries(modules).filter(([, on]) => !on)
        if (off.length) await storesApi.saveModules(store.id, Object.fromEntries(modules ? Object.entries(modules) : []))
      }

      setCreated(store)
      toast.success(`“${store.name}” created as a draft.`)
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  const publish = async () => {
    setBusy(true)
    try {
      await storesApi.activate(created.id)
      toast.success(`“${created.name}” is live.`)
      navigate(`/stores/${created.id}`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader
        title="New store"
        subtitle={STEPS[step].label}
        breadcrumbs={[{ label: 'Administration' }, { label: 'Stores', to: '/stores' }, { label: 'New' }]}
      />

      <div className="mb-4">
        <ProgressBar value={step + 1} max={STEPS.length} label={`Step ${step + 1} of ${STEPS.length} — ${STEPS[step].label}`} />
      </div>

      {error && (
        <Alert tone="danger" title="Could not create the store" className="mb-4">
          {error.message}
        </Alert>
      )}

      <Card>
        {step === 0 && (
          <div className="grid gap-4">
            <Field label="Store name" required help="What customers and staff will see.">
              <Input value={form.name} onChange={set('name')} placeholder="Yazan Jewelry" autoFocus />
            </Field>
            <Field
              label="Slug"
              required
              error={slugError}
              help="Used in the store's address. It cannot be changed later, because every URL pointing at the store would break."
            >
              <Input value={form.slug} onChange={set('slug')} placeholder="jewelry" />
            </Field>
          </div>
        )}

        {step === 1 && (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Theme" help="Leave empty to inherit the platform default.">
              <Input value={form.theme} onChange={set('theme')} placeholder="black" />
            </Field>
            <Field label="Currency" error={currencyError} help="ISO 4217. Empty inherits the platform currency.">
              <Input value={form.currency} onChange={set('currency')} placeholder="YER" />
            </Field>
            <Field label="Locale">
              <Input value={form.locale} onChange={set('locale')} placeholder="ar" />
            </Field>
            <Field label="Time zone">
              <Input value={form.timezone} onChange={set('timezone')} placeholder="Asia/Aden" />
            </Field>
            <Field label="Languages" help="Comma separated." className="sm:col-span-2">
              <Input value={form.languages} onChange={set('languages')} placeholder="ar, en" />
            </Field>
          </div>
        )}

        {step === 2 && (
          <div className="grid gap-4">
            <Alert tone="info" title="An address can wait">
              A store with no address is created as a draft and answers nothing. You can add one later
              from the store's Domains tab.
            </Alert>
            <Field label="Address type">
              <Select value={form.type} onChange={set('type')}>
                <option value="subdomain">Subdomain — jewelry.yazan.com</option>
                <option value="custom">Custom domain — example.com</option>
                <option value="path">Path — yazan.com/jewelry</option>
              </Select>
            </Field>
            <Field
              label="Host"
              help="Every address needs a host, including a path address — that is what confines it to the domain it was meant for."
            >
              <Input value={form.host} onChange={set('host')} placeholder="jewelry.yazan.com" />
            </Field>
            {form.type === 'path' && (
              <Field label="Path prefix">
                <Input value={form.path} onChange={set('path')} placeholder="/jewelry" />
              </Field>
            )}
          </div>
        )}

        {step === 3 && (
          <div className="grid gap-2">
            <p className="mb-2 text-sm text-muted">
              Everything is on by default — a new store should work, not be a checklist. Turn off what
              this store will not use.
            </p>
            {modules === null && <p className="text-sm text-muted">Loading…</p>}
            {modules && Object.keys(modules).length === 0 && (
              <p className="text-sm text-muted">No modules are registered.</p>
            )}
            {modules &&
              Object.entries(modules).map(([key, enabled]) => (
                <Checkbox
                  key={key}
                  label={key.replace(/_/g, ' ')}
                  checked={Boolean(enabled)}
                  onChange={(value) => setModules((prev) => ({ ...prev, [key]: value }))}
                />
              ))}
          </div>
        )}

        {step === 4 && !created && (
          <div className="grid gap-4">
            <dl className="grid gap-2 text-sm sm:grid-cols-2">
              {[
                ['Name', form.name],
                ['Slug', form.slug],
                ['Theme', form.theme || 'platform default'],
                ['Currency', form.currency || 'platform default'],
                ['Locale', form.locale || '—'],
                ['Time zone', form.timezone || '—'],
                ['Address', form.host ? `${form.type}: ${form.host}${form.path}` : 'none yet'],
              ].map(([label, value]) => (
                <div key={label} className="flex justify-between gap-4 border-b border-divider py-1">
                  <dt className="text-muted">{label}</dt>
                  <dd className="font-medium">{value}</dd>
                </div>
              ))}
            </dl>
            <Alert tone="info" title="Created as a draft">
              Deploying writes the store, its address and its modules. It stays a <strong>draft</strong> and
              answers nothing until you publish it — so a mistake here is never a broken storefront on
              the web.
            </Alert>
          </div>
        )}

        {step === 4 && created && (
          <div className="grid gap-4">
            <Alert tone="ok" title={`“${created.name}” was created`}>
              It is a draft with no traffic. Publish it when you are ready, or keep configuring it first.
            </Alert>
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" icon={Rocket} loading={busy} onClick={publish}>
                Publish store
              </Button>
              <Button icon={Store} onClick={() => navigate(`/stores/${created.id}`)}>
                Keep configuring
              </Button>
            </div>
          </div>
        )}
      </Card>

      {!created && (
        <div className="mt-4 flex items-center justify-between">
          <Button icon={ArrowLeft} disabled={step === 0 || busy} onClick={() => goTo(step - 1)}>
            Back
          </Button>
          {step < STEPS.length - 1 ? (
            <Button variant="primary" icon={ArrowRight} disabled={!canAdvance} onClick={() => goTo(step + 1)}>
              Continue
            </Button>
          ) : (
            <Button variant="primary" icon={Check} loading={busy} onClick={deploy}>
              Create draft
            </Button>
          )}
        </div>
      )}
    </>
  )
}
