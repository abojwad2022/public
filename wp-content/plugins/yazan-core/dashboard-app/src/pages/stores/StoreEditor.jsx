/**
 * A single store: information, branding, domains, modules, settings and users.
 *
 * Modelled on access/RoleEditor.jsx — same dirty-tracking, same optimistic-concurrency echo of
 * `updated_at`, same beforeunload guard. Tabs are driven by the query string so a deep link lands
 * on the right panel.
 *
 * Unlike the other editors this is EDIT-ONLY: creating a store goes through the wizard, because a
 * store needs an address and modules chosen before it means anything, and a flat form cannot carry
 * that without becoming a worse wizard.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams, useSearchParams } from 'react-router'

import { storesApi } from '../../api/endpoints.js'
import { Can } from '../../components/Protected.jsx'
import { PageHeader } from '../../components/Layout.jsx'
import { useToast } from '../../context/ToastContext.jsx'
import {
  Alert, Badge, Button, Card, Checkbox, Field, Input, Select, Skeleton,
  Table, TBody, TD, TH, THead, TR, Tabs, useConfirm,
} from '../../components/ui/index.js'
import { Ban, Check, Globe, Plus, Rocket, Save, Trash2 } from '../../components/ui/icons.js'

const TABS = [
  { key: 'info', label: 'Information' },
  { key: 'domains', label: 'Domains' },
  { key: 'modules', label: 'Modules' },
  { key: 'settings', label: 'Settings' },
  { key: 'users', label: 'Users' },
]

const TONE = { active: 'ok', suspended: 'warn', draft: 'info', archived: 'muted' }

export default function StoreEditor() {
  const { id } = useParams()
  const [params, setParams] = useSearchParams()
  const tab = TABS.some((t) => t.key === params.get('tab')) ? params.get('tab') : 'info'

  const [store, setStore] = useState(null)
  const [form, setForm] = useState({})
  const [domains, setDomains] = useState([])
  const [modules, setModules] = useState(null)
  const [settings, setSettings] = useState({})
  const [users, setUsers] = useState(null)
  const [roles, setRoles] = useState([])
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [newDomain, setNewDomain] = useState({ host: '', type: 'subdomain', path: '' })

  const loadedAt = useRef('')
  const toast = useToast()
  const [confirm, confirmDialog] = useConfirm()

  const load = useCallback(async () => {
    setError(null)
    try {
      const data = await storesApi.get(id)
      setStore(data.store)
      setForm(data.store)
      setDomains(data.domains || [])
      setSettings(data.settings || {})
      setModules(data.modules || {})
      loadedAt.current = data.store.updated_at
      setDirty(false)
    } catch (err) {
      setError(err)
    }
  }, [id])

  useEffect(() => { load() }, [load])

  // Load the user tab lazily — it needs the whole role catalogue.
  useEffect(() => {
    if ('users' !== tab || users !== null) return
    storesApi.users(id).then((data) => { setUsers(data.items || []); setRoles(data.roles || []) }).catch(() => setUsers([]))
  }, [tab, users, id])

  useEffect(() => {
    if (!dirty) return undefined
    const onBeforeUnload = (event) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [dirty])

  const set = (key) => (event) => {
    const value = event?.target ? event.target.value : event
    setForm((prev) => ({ ...prev, [key]: value }))
    setDirty(true)
  }

  const editable = Boolean(store?.editable)

  const onSave = async () => {
    setSaving(true)
    try {
      const saved = await storesApi.update(id, {
        name: form.name,
        currency: form.currency,
        locale: form.locale,
        timezone: form.timezone,
        languages: form.languages,
        theme: form.theme,
        // Echoed so the server can refuse a blind overwrite of somebody else's save.
        updated_at: loadedAt.current,
      })
      loadedAt.current = saved.updated_at
      setStore(saved)
      setDirty(false)
      toast.success('Store saved.')
    } catch (err) {
      if (err.code === 'yazan_stale') {
        toast.error('This store changed while you were editing it. Reload and try again.')
      } else {
        toast.error(err.message)
      }
    } finally {
      setSaving(false)
    }
  }

  const runAction = async (fn, message) => {
    try {
      await fn()
      toast.success(message)
      await load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const addDomain = async () => {
    try {
      await storesApi.addDomain(id, { ...newDomain, is_primary: domains.length === 0 })
      setNewDomain({ host: '', type: 'subdomain', path: '' })
      toast.success('Address added.')
      await load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const removeDomain = async (domain) => {
    const ok = await confirm({
      title: `Remove ${domain.host}${domain.path}?`,
      body: 'The store stops answering on this address as soon as the map is rebuilt.',
      confirmLabel: 'Remove',
      tone: 'danger',
    })
    if (ok) runAction(() => storesApi.removeDomain(id, domain.id), 'Address removed.')
  }

  if (!store) {
    return (
      <>
        <PageHeader title="Store" breadcrumbs={[{ label: 'Administration' }, { label: 'Stores', to: '/stores' }]} />
        {error && <Alert tone="danger" title="Could not load this store" onRetry={load}>{error.message}</Alert>}
        {!error && <Skeleton h={420} rounded="md" />}
      </>
    )
  }

  return (
    <>
      <PageHeader
        title={store.name}
        subtitle={store.slug}
        breadcrumbs={[{ label: 'Administration' }, { label: 'Stores', to: '/stores' }, { label: store.name }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={TONE[store.status] || 'muted'}>{store.status}</Badge>
            {!store.is_active && store.status !== 'archived' && (
              <Can perm="stores.edit">
                <Button icon={Rocket} onClick={() => runAction(() => storesApi.activate(id), `“${store.name}” is live.`)}>
                  Publish
                </Button>
              </Can>
            )}
            {store.is_active && (
              <Can perm="stores.edit">
                <Button icon={Ban} onClick={() => runAction(() => storesApi.suspend(id), 'Store disabled.')}>
                  Disable
                </Button>
              </Can>
            )}
            <Can perm="stores.edit" mode="disable">
              <Button variant="primary" icon={Save} loading={saving} disabled={!editable || !dirty} onClick={onSave}>
                Save changes
              </Button>
            </Can>
          </div>
        }
      />

      {!editable && (
        <Alert tone="info" title="Archived" className="mb-4">
          This store is archived. Its history is kept, but it cannot be edited or reached.
        </Alert>
      )}

      <Tabs
        items={TABS}
        value={tab}
        onChange={(value) => setParams(value === 'info' ? {} : { tab: value })}
        className="mb-4"
      />

      {tab === 'info' && (
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Name" required>
              <Input value={form.name || ''} onChange={set('name')} disabled={!editable} />
            </Field>
            <Field label="Slug" help="Immutable — every address pointing at this store depends on it.">
              <Input value={form.slug || ''} disabled />
            </Field>
            <Field label="Theme"><Input value={form.theme || ''} onChange={set('theme')} disabled={!editable} /></Field>
            <Field label="Currency"><Input value={form.currency || ''} onChange={set('currency')} disabled={!editable} /></Field>
            <Field label="Locale"><Input value={form.locale || ''} onChange={set('locale')} disabled={!editable} /></Field>
            <Field label="Time zone"><Input value={form.timezone || ''} onChange={set('timezone')} disabled={!editable} /></Field>
            <Field label="Languages" className="sm:col-span-2">
              <Input value={form.languages || ''} onChange={set('languages')} disabled={!editable} />
            </Field>
            <Field label="Identifier" help="Stable across environments — use this in APIs, not the numeric id." className="sm:col-span-2">
              <Input value={form.uuid || ''} disabled />
            </Field>
          </div>
        </Card>
      )}

      {tab === 'domains' && (
        <Card bodyClass="">
          <Table>
            <THead><TR><TH>Address</TH><TH>Type</TH><TH>Primary</TH><TH align="end">Actions</TH></TR></THead>
            <TBody>
              {domains.map((domain) => (
                <TR key={domain.id}>
                  <TD primary label="Address">{domain.host}{domain.path}</TD>
                  <TD label="Type">{domain.type}</TD>
                  <TD label="Primary">{Number(domain.is_primary) ? <Badge tone="ok">primary</Badge> : '—'}</TD>
                  <TD align="end">
                    <Can perm="stores.domains">
                      <Button small icon={Trash2} variant="danger" onClick={() => removeDomain(domain)}>Remove</Button>
                    </Can>
                  </TD>
                </TR>
              ))}
              {domains.length === 0 && (
                <TR><TD label="Address"><span className="text-muted">No address yet — this store answers nothing.</span></TD><TD /><TD /><TD /></TR>
              )}
            </TBody>
          </Table>

          <Can perm="stores.domains">
            <div className="flex flex-wrap items-end gap-2 border-t border-divider p-4">
              <Field label="Host" className="grow">
                <Input value={newDomain.host} onChange={(e) => setNewDomain({ ...newDomain, host: e.target.value })} placeholder="honey.yazan.com" />
              </Field>
              <Field label="Type">
                <Select value={newDomain.type} onChange={(e) => setNewDomain({ ...newDomain, type: e.target.value })}>
                  <option value="subdomain">Subdomain</option>
                  <option value="custom">Custom domain</option>
                  <option value="path">Path</option>
                </Select>
              </Field>
              {newDomain.type === 'path' && (
                <Field label="Path">
                  <Input value={newDomain.path} onChange={(e) => setNewDomain({ ...newDomain, path: e.target.value })} placeholder="/honey" />
                </Field>
              )}
              <Button icon={Plus} disabled={!newDomain.host} onClick={addDomain}>Add address</Button>
            </div>
          </Can>
        </Card>
      )}

      {tab === 'modules' && (
        <Card title="Modules" subtitle="Everything is on unless you turn it off.">
          <div className="grid gap-2">
            {modules && Object.entries(modules).map(([key, enabled]) => (
              <Checkbox
                key={key}
                label={key.replace(/_/g, ' ')}
                checked={Boolean(enabled)}
                disabled={!editable}
                onChange={(value) => {
                  const next = { ...modules, [key]: value }
                  setModules(next)
                  storesApi.saveModules(id, next)
                    .then(() => toast.success('Modules updated.'))
                    .catch((err) => { toast.error(err.message); setModules(modules) })
                }}
              />
            ))}
          </div>
        </Card>
      )}

      {tab === 'settings' && (
        <Card title="Store settings" subtitle="Values scoped to this store alone.">
          {Object.keys(settings).length === 0 && <p className="text-sm text-muted">No settings stored yet.</p>}
          <div className="grid gap-2">
            {Object.entries(settings).map(([key, value]) => (
              <Field key={key} label={key}>
                <Input
                  value={value}
                  disabled={!editable}
                  onChange={(e) => setSettings({ ...settings, [key]: e.target.value })}
                />
              </Field>
            ))}
          </div>
          {Object.keys(settings).length > 0 && (
            <Can perm="stores.settings" mode="disable">
              <Button
                className="mt-3"
                icon={Save}
                disabled={!editable}
                onClick={() => runAction(() => storesApi.saveSettings(id, settings), 'Settings saved.')}
              >
                Save settings
              </Button>
            </Can>
          )}
        </Card>
      )}

      {tab === 'users' && (
        <Card bodyClass="" title="Store users" subtitle="Roles held in THIS store. A person can be staff here and nowhere else.">
          {users === null && <Skeleton h={200} rounded="md" />}
          {users && (
            <Table>
              <THead><TR><TH>User</TH><TH>Roles here</TH><TH align="end" /></TR></THead>
              <TBody>
                {users.map((user) => (
                  <TR key={user.id}>
                    <TD primary label="User">
                      {user.name}
                      <div className="text-2xs text-muted">{user.email}</div>
                    </TD>
                    <TD label="Roles">
                      {user.roles.map((roleId) => {
                        const role = roles.find((r) => Number(r.id) === Number(roleId))
                        return <Badge key={roleId} tone="info" className="me-1">{role ? role.name : roleId}</Badge>
                      })}
                      {user.roles.length === 0 && <span className="text-faint">none</span>}
                    </TD>
                    <TD align="end">
                      <Can perm="stores.edit">
                        <Button
                          small
                          icon={Check}
                          onClick={() =>
                            runAction(
                              () => storesApi.setUserRoles(id, user.id, []),
                              `Removed ${user.name} from this store.`
                            )
                          }
                        >
                          Remove from store
                        </Button>
                      </Can>
                    </TD>
                  </TR>
                ))}
                {users.length === 0 && (
                  <TR><TD label="User"><span className="text-muted">Nobody has a role in this store yet.</span></TD><TD /><TD /></TR>
                )}
              </TBody>
            </Table>
          )}
          <div className="border-t border-divider p-4 text-sm text-muted">
            <Globe className="me-1 inline" />
            Roles themselves are platform-wide; membership is per store. Granting somebody a role here
            does not touch what they hold anywhere else.
          </div>
        </Card>
      )}

      {confirmDialog}
    </>
  )
}
