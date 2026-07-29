import { useCallback, useEffect, useState } from 'react'
import { webhooksApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import {
  useConfirm,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  Input,
  Modal,
  Select,
  Spinner,
  TBody,
  TD,
  TH,
  THead,
  TR,
  Table,
} from '../../components/ui/index.js'

const STATUS_TONE = { active: 'ok', paused: 'warn', disabled: 'danger' }

export default function WebhooksPanel() {
  const [confirm, confirmDialog] = useConfirm()
  const toast = useToast()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await webhooksApi.list())
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const remove = async (hook) => {
    if (!(await confirm({ title: `Delete the webhook “${hook.name}”? Deliveries to ${hook.delivery_url} will stop.` }))) return
    try {
      await webhooksApi.remove(hook.id)
      toast.success('Webhook deleted.')
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  if (loading || !data) return <Spinner />

  return (
    <>
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-xs text-muted flex-1 min-w-56">
          Webhooks POST store events to an external URL — fulfilment, accounting, a CRM. The signing
          secret is generated for you and is <strong className="text-fg">never shown again</strong > after
          it is set.
        </p>
        <Button variant="primary" onClick={() => setEditing({ isNew: true })}>
          Add webhook
        </Button>
      </div>

      <Card bodyClass="p-0">
        {data.items.length === 0 ? (
          <EmptyState title="No webhooks">
            Nothing is being notified of store events yet.
          </EmptyState>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <THead>
                <TR>
                  <TH>Name</TH>
                  <TH className="w-40">Topic</TH>
                  <TH>Delivery URL</TH>
                  <TH className="w-24">Status</TH>
                  <TH className="w-20 text-center">Fails</TH>
                  <TH className="w-32 text-end">Actions</TH>
                </TR>
              </THead>
              <TBody>
                {data.items.map((h) => (
                  <TR key={h.id}>
                    <TD className="text-fg">
                      {h.name}
                      {!h.has_secret && (
                        <span className="ms-2">
                          <Badge tone="warn">no secret</Badge>
                        </span>
                      )}
                    </TD>
                    <TD className="text-muted font-mono text-xs">{h.topic}</TD>
                    <TD className="max-w-64 truncate text-xs text-muted" title={h.delivery_url}>
                      {h.delivery_url}
                    </TD>
                    <TD>
                      <Badge tone={STATUS_TONE[h.status] || 'muted'}>{h.status}</Badge>
                    </TD>
                    <TD align="center">
                      <span className={h.failure_count > 0 ? 'text-danger' : 'text-faint'}>
                        {h.failure_count}
                      </span>
                    </TD>
                    <TD align="end" className="whitespace-nowrap">
                      <button
                        type="button"
                        onClick={() => setEditing(h)}
                        className="text-xs text-muted hover:text-agate-fg transition-colors me-3"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        onClick={() => remove(h)}
                        className="text-xs text-muted hover:text-danger transition-colors"
                      >
                        Delete
                      </button>
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
        )}
      </Card>

      {editing && (
        <WebhookModal
          hook={editing}
          topics={data.topics}
          statuses={data.statuses}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            load()
          }}
        />
      )}
    </div>
      {confirmDialog}
    </>
  )
}

function WebhookModal({ hook, topics, statuses, onClose, onSaved }) {
  const toast = useToast()
  const isNew = Boolean(hook.isNew)
  const [form, setForm] = useState({
    name: hook.name || '',
    topic: hook.topic || 'order.created',
    delivery_url: hook.delivery_url || '',
    status: hook.status || 'active',
    secret: '',
  })
  const [saving, setSaving] = useState(false)

  const set = (k, v) => setForm({ ...form, [k]: v })

  const save = async () => {
    if (!form.name.trim()) {
      toast.error('A webhook name is required.')
      return
    }
    if (!form.delivery_url.trim()) {
      toast.error('A delivery URL is required.')
      return
    }
    setSaving(true)
    try {
      const payload = { ...form }
      if (!payload.secret) delete payload.secret // Don't rotate unless one was typed.
      if (isNew) await webhooksApi.create(payload)
      else await webhooksApi.update(hook.id, payload)
      toast.success(isNew ? 'Webhook created.' : 'Webhook updated.')
      onSaved()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} title={isNew ? 'New webhook' : `Edit “${hook.name}”`}>
      <div className="space-y-4">
        <Field label="Name" help="For your own reference.">
          <Input value={form.name} onChange={(e) => set('name', e.target.value)} autoFocus />
        </Field>

        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Topic" help="Which event triggers a delivery.">
            <Select value={form.topic} onChange={(e) => set('topic', e.target.value)}>
              {Object.entries(topics).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Status">
            <Select value={form.status} onChange={(e) => set('status', e.target.value)}>
              {Object.entries(statuses).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <Field label="Delivery URL" help="Must be http:// or https://.">
          <Input
            type="url"
            value={form.delivery_url}
            placeholder="https://example.com/webhook"
            onChange={(e) => set('delivery_url', e.target.value)}
          />
        </Field>

        <Field
          label="Secret"
          help={
            isNew
              ? 'Leave blank and one will be generated. Used to sign the payload so the receiver can verify it.'
              : 'Leave blank to keep the current secret. Typing a new one rotates it.'
          }
        >
          <Input
            value={form.secret}
            placeholder={isNew ? 'Auto-generated' : '•••••••• (unchanged)'}
            onChange={(e) => set('secret', e.target.value)}
            className="font-mono"
          />
        </Field>

        <p className="text-xs text-faint">
          For security the secret is write-only — it is never returned by the API once saved.
        </p>

        <div className="flex justify-end gap-2 pt-3 border-t border-edge">
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : isNew ? 'Create webhook' : 'Save'}
          </Button>
        </div>
      </div>
    </Modal>
  )
}
