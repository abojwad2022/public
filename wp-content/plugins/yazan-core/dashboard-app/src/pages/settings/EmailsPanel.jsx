import { useCallback, useEffect, useState } from 'react'
import { emailsApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Badge, Button, Card, Field, Input, Modal, Select, Spinner, Textarea } from '../../components/ui/index.js'

export default function EmailsPanel() {
  const toast = useToast()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [edits, setEdits] = useState({})
  const [saving, setSaving] = useState(false)
  const [editing, setEditing] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await emailsApi.list())
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

  const dirty = Object.keys(edits)

  const saveGlobals = async () => {
    setSaving(true)
    try {
      setData(await emailsApi.saveGlobals(edits))
      setEdits({})
      toast.success('Email settings saved.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  const toggle = async (email) => {
    try {
      setData(await emailsApi.update(email.id, { enabled: !email.enabled }))
      toast.success(email.enabled ? 'Email disabled.' : 'Email enabled.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  if (loading || !data) return <Spinner />

  const valueOf = (f) => (f.option in edits ? edits[f.option] : f.value)

  return (
    <div className="space-y-5">
      <Card
        title="Sender & branding"
        actions={
          dirty.length > 0 && (
            <Button small variant="primary" onClick={saveGlobals} disabled={saving}>
              {saving ? 'Saving…' : `Save ${dirty.length}`}
            </Button>
          )
        }
      >
        <div className="grid sm:grid-cols-2 gap-5">
          {data.globals.map((f) => (
            <Field
              key={f.option}
              label={f.label}
              className={f.option in edits ? 'border-s-2 border-warn ps-3' : ''}
            >
              {f.type === 'textarea' ? (
                <Textarea
                  rows={2}
                  value={valueOf(f)}
                  onChange={(e) => setEdits({ ...edits, [f.option]: e.target.value })}
                />
              ) : f.type === 'color' ? (
                <div className="flex gap-2">
                  <input
                    type="color"
                    value={valueOf(f) || '#000000'}
                    onChange={(e) => setEdits({ ...edits, [f.option]: e.target.value })}
                    className="h-9 w-12 bg-sunken border border-edge cursor-pointer"
                  />
                  <Input
                    value={valueOf(f)}
                    onChange={(e) => setEdits({ ...edits, [f.option]: e.target.value })}
                    className="font-mono"
                  />
                </div>
              ) : (
                <Input
                  type={f.type === 'email' ? 'email' : 'text'}
                  value={valueOf(f)}
                  onChange={(e) => setEdits({ ...edits, [f.option]: e.target.value })}
                />
              )}
            </Field>
          ))}
        </div>
      </Card>

      <Card title={`Transactional emails (${data.items.length})`} bodyClass="p-0">
        <ul className="divide-y divide-divider">
          {data.items.map((email) => (
            <li key={email.id} className="flex flex-wrap items-center gap-3 p-3">
              <input
                type="checkbox"
                checked={email.enabled}
                onChange={() => toggle(email)}
                aria-label={`Enable ${email.title}`}
                className="size-4 accent-[var(--yz-agate)]"
              />
              <span className="flex-1 min-w-0">
                <span className={`block text-sm ${email.enabled ? 'text-fg' : 'text-muted'}`}>
                  {email.title}
                </span>
                <span className="block text-xs text-faint truncate">{email.description}</span>
              </span>
              <Badge tone={email.customer_email ? 'gold' : 'muted'}>
                {email.customer_email ? 'Customer' : 'Admin'}
              </Badge>
              <button
                type="button"
                onClick={() => setEditing(email)}
                className="text-xs text-muted hover:text-agate-fg transition-colors"
              >
                Edit
              </button>
            </li>
          ))}
        </ul>
      </Card>

      {editing && (
        <EmailModal
          email={editing}
          onClose={() => setEditing(null)}
          onSaved={(next) => {
            setData(next)
            setEditing(null)
          }}
        />
      )}
    </div>
  )
}

function EmailModal({ email, onClose, onSaved }) {
  const toast = useToast()
  const [form, setForm] = useState({
    recipient: email.customer_email ? '' : email.recipient || '',
    subject: email.subject || '',
    heading: email.heading || '',
    additional_content: email.additional_content || '',
    email_type: email.email_type || 'html',
  })
  const [saving, setSaving] = useState(false)

  const can = (key) => email.editable.includes(key)
  const set = (k, v) => setForm({ ...form, [k]: v })

  const save = async () => {
    setSaving(true)
    try {
      const payload = {}
      for (const key of ['recipient', 'subject', 'heading', 'additional_content', 'email_type']) {
        if (can(key) && !(key === 'recipient' && email.customer_email)) payload[key] = form[key]
      }
      onSaved(await emailsApi.update(email.id, payload))
      toast.success('Email updated.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} title={email.title}>
      <div className="space-y-4">
        {email.customer_email ? (
          <p className="text-xs text-faint">This email goes to the customer, so it has no recipient field.</p>
        ) : (
          can('recipient') && (
            <Field label="Recipient(s)" help="Comma separated.">
              <Input value={form.recipient} onChange={(e) => set('recipient', e.target.value)} />
            </Field>
          )
        )}

        {can('subject') && (
          <Field label="Subject" help={email.default_subject ? `Default: ${email.default_subject}` : undefined}>
            <Input
              value={form.subject}
              placeholder={email.default_subject}
              onChange={(e) => set('subject', e.target.value)}
            />
          </Field>
        )}

        {can('heading') && (
          <Field label="Heading" help={email.default_heading ? `Default: ${email.default_heading}` : undefined}>
            <Input
              value={form.heading}
              placeholder={email.default_heading}
              onChange={(e) => set('heading', e.target.value)}
            />
          </Field>
        )}

        {can('additional_content') && (
          <Field label="Additional content" help="Appended below the main email content.">
            <Textarea
              rows={3}
              value={form.additional_content}
              onChange={(e) => set('additional_content', e.target.value)}
            />
          </Field>
        )}

        {can('email_type') && (
          <Field label="Format">
            <Select value={form.email_type} onChange={(e) => set('email_type', e.target.value)}>
              <option value="html">HTML</option>
              <option value="plain">Plain text</option>
              <option value="multipart">Multipart</option>
            </Select>
          </Field>
        )}

        <p className="text-xs text-faint">
          Placeholders like <code>{'{site_title}'}</code > and <code>{'{order_number}'}</code > work in the
          subject and heading.
        </p>

        <div className="flex justify-end gap-2 pt-3 border-t border-edge">
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </div>
    </Modal>
  )
}
