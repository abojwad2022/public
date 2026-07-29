import { useCallback, useEffect, useMemo, useState } from 'react'
import { settingsApi } from '../api/endpoints.js'
import { useAuth } from '../context/AuthContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import { Button, Card, Checkbox, Field, Input, Select, Spinner } from '../components/ui/index.js'
import ActivityLog from './ActivityLog.jsx'
import TaxPanel from './settings/TaxPanel.jsx'
import ShippingPanel from './settings/ShippingPanel.jsx'
import PaymentsPanel from './settings/PaymentsPanel.jsx'
import EmailsPanel from './settings/EmailsPanel.jsx'
import ToolsPanel from './settings/ToolsPanel.jsx'
import WebhooksPanel from './settings/WebhooksPanel.jsx'
import StatusPanel from './settings/StatusPanel.jsx'
import BackupPanel from './settings/BackupPanel.jsx'

export default function Settings() {
  const toast = useToast()
  const { can } = useAuth()
  const [groups, setGroups] = useState(null)
  const [tab, setTab] = useState('store')
  const [edits, setEdits] = useState({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const canManage = can('manage_woo')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await settingsApi.get()
      setGroups(data.groups)
      setEdits({})
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    if (canManage) load()
    else setLoading(false)
  }, [canManage, load])

  const dirtyKeys = useMemo(() => Object.keys(edits), [edits])

  const valueOf = (field) => (field.option in edits ? edits[field.option] : field.value)

  const setValue = (option, value) => setEdits((current) => ({ ...current, [option]: value }))

  const save = async () => {
    if (dirtyKeys.length === 0) return
    setSaving(true)
    try {
      const result = await settingsApi.save(edits)
      setGroups(result.groups)
      setEdits({})
      toast.success(`${result.saved.length} setting${result.saved.length === 1 ? '' : 's'} saved.`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  if (!canManage) {
    return (
      <>
        <PageHeader title="Settings" />
        <Card>
          <p className="text-sm text-muted">
            Managing store settings requires the <code>manage_woocommerce</code > capability. Ask an
            administrator if you need access.
          </p>
        </Card>
      </>
    )
  }

  if (loading || !groups) return <Spinner label="Loading settings…" />

  // Panels that manage their own data rather than the simple option registry.
  const EXTRA_TABS = [
    { key: 'tax', label: 'Tax' },
    { key: 'shipping', label: 'Shipping' },
    { key: 'payments', label: 'Payments' },
    { key: 'emails', label: 'Emails' },
    { key: 'tools', label: 'Import / Export' },
    { key: 'webhooks', label: 'Webhooks' },
    { key: 'status', label: 'System status' },
    { key: 'backup', label: 'Backup' },
    { key: 'activity', label: 'Activity log' },
  ]
  const CUSTOM_KEYS = EXTRA_TABS.map((t) => t.key)
  const tabs = [...groups.map((g) => ({ key: g.key, label: g.label })), ...EXTRA_TABS]
  const activeGroup = groups.find((g) => g.key === tab)

  return (
    <>
      <PageHeader
        title="Settings"
        subtitle="Store configuration and the dashboard activity trail"
        actions={
          !CUSTOM_KEYS.includes(tab) && (
            <>
              {dirtyKeys.length > 0 && (
                <Button onClick={() => setEdits({})} disabled={saving}>
                  Discard
                </Button>
              )}
              <Button variant="primary" onClick={save} disabled={saving || dirtyKeys.length === 0}>
                {saving
                  ? 'Saving…'
                  : dirtyKeys.length > 0
                    ? `Save ${dirtyKeys.length} change${dirtyKeys.length === 1 ? '' : 's'}`
                    : 'Save'}
              </Button>
            </>
          )
        }
      />

      <div className="flex flex-wrap gap-2 mb-4">
        {tabs.map((entry) => (
          <Button
            key={entry.key}
            small
            variant={tab === entry.key ? 'primary' : 'ghost'}
            onClick={() => setTab(entry.key)}
          >
            {entry.label}
          </Button>
        ))}
      </div>

      {dirtyKeys.length > 0 && !CUSTOM_KEYS.includes(tab) && (
        <p className="yz-card p-3 mb-4 text-sm text-warn border-s-2 border-warn">
          {dirtyKeys.length} unsaved change{dirtyKeys.length === 1 ? '' : 's'}. These write directly to
          your live WooCommerce configuration when saved.
        </p>
      )}

      {tab === 'activity' ? (
        <ActivityLog />
      ) : tab === 'tax' ? (
        <TaxPanel />
      ) : tab === 'shipping' ? (
        <ShippingPanel />
      ) : tab === 'payments' ? (
        <PaymentsPanel />
      ) : tab === 'emails' ? (
        <EmailsPanel />
      ) : tab === 'tools' ? (
        <ToolsPanel />
      ) : tab === 'webhooks' ? (
        <WebhooksPanel />
      ) : tab === 'status' ? (
        <StatusPanel />
      ) : tab === 'backup' ? (
        <BackupPanel />
      ) : (
        activeGroup && (
          <Card title={activeGroup.label}>
            <div className="grid sm:grid-cols-2 gap-5">
              {activeGroup.fields.map((field) => (
                <SettingField
                  key={field.option}
                  field={field}
                  value={valueOf(field)}
                  dirty={field.option in edits}
                  onChange={(v) => setValue(field.option, v)}
                />
              ))}
            </div>
          </Card>
        )
      )}
    </>
  )
}

function SettingField({ field, value, dirty, onChange }) {
  const label = (
    <>
      {field.label}
      {dirty && <span className="text-warn ms-1.5">•</span>}
    </>
  )

  if (field.type === 'bool_yesno') {
    return (
      <div className={dirty ? 'border-s-2 border-warn ps-3' : ''}>
        <Checkbox label={field.label} checked={Boolean(value)} onChange={onChange} help={field.help} />
      </div>
    )
  }

  if (field.type === 'select') {
    return (
      <Field label={label} help={field.help} className={dirty ? 'border-s-2 border-warn ps-3' : ''}>
        <Select value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          {Object.entries(field.choices || {}).map(([key, text]) => (
            <option key={key} value={key}>
              {text}
            </option>
          ))}
        </Select>
      </Field>
    )
  }

  return (
    <Field label={label} help={field.help} className={dirty ? 'border-s-2 border-warn ps-3' : ''}>
      <Input
        type={field.type === 'number' ? 'number' : field.type === 'email' ? 'email' : 'text'}
        value={value ?? ''}
        min={field.min ?? undefined}
        max={field.max ?? undefined}
        onChange={(e) => onChange(e.target.value)}
      />
    </Field>
  )
}
