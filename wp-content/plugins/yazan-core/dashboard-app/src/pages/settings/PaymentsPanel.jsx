import { useCallback, useEffect, useState } from 'react'
import { gatewaysApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Badge, Button, Card, Field, Input, Modal, Spinner, Textarea } from '../../components/ui/index.js'

export default function PaymentsPanel() {
  const toast = useToast()
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(null)
  const [showAll, setShowAll] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setItems((await gatewaysApi.list()).items)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const toggle = async (gateway) => {
    try {
      setItems((await gatewaysApi.update(gateway.id, { enabled: !gateway.enabled })).items)
      toast.success(gateway.enabled ? 'Gateway disabled.' : 'Gateway enabled.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  const move = async (index, direction) => {
    const next = [...items]
    const target = index + direction
    if (target < 0 || target>= next.length) return
    ;[next[index], next[target]] = [next[target], next[index]]
    setItems(next)
    try {
      await gatewaysApi.reorder(next.map((g) => g.id))
    } catch (err) {
      toast.error(err.message)
      load()
    }
  }

  if (loading) return <Spinner />

  // Most stores use a handful of gateways; the rest are WooPayments sub-methods.
  const visible = showAll ? items : items.filter((g) => g.enabled || !g.id.startsWith('woocommerce_payments_'))

  return (
    <div className="space-y-4">
      <p className="yz-card p-3 text-xs text-muted">
        This screen controls which methods appear at checkout, their order, and the wording customers
        see. <strong className="text-fg">API keys and credentials are intentionally not editable here</strong> —
        connect a gateway once in WooCommerce → Settings → Payments, then run it from this dashboard.
      </p>

      <Card bodyClass="p-0">
        <ul className="divide-y divide-divider">
          {visible.map((g, index) => (
            <li key={g.id} className="flex flex-wrap items-center gap-3 p-3">
              <input
                type="checkbox"
                checked={g.enabled}
                onChange={() => toggle(g)}
                aria-label={`Enable ${g.method_title}`}
                className="size-4 accent-[var(--yz-agate)]"
              />

              <span className="flex-1 min-w-0">
                <span className={`block text-sm ${g.enabled ? 'text-fg' : 'text-muted'}`}>
                  {g.title || g.method_title}
                </span>
                <span className="block text-xs text-faint">{g.method_title}</span>
              </span>

              <div className="flex items-center gap-2">
                {g.needs_setup && <Badge tone="warn">Not connected</Badge>}
                {g.supports_refunds && <Badge tone="muted">Refunds</Badge>}
              </div>

              <div className="flex items-center gap-1">
                <button
                  type="button"
                  onClick={() => move(index, -1)}
                  disabled={index === 0}
                  className="text-muted hover:text-fg disabled:opacity-30 px-1"
                  aria-label="Move up"
                >
                  ↑
                </button>
                <button
                  type="button"
                  onClick={() => move(index, 1)}
                  disabled={index === visible.length - 1}
                  className="text-muted hover:text-fg disabled:opacity-30 px-1"
                  aria-label="Move down"
                >
                  ↓
                </button>
              </div>

              <button
                type="button"
                onClick={() => setEditing(g)}
                className="text-xs text-muted hover:text-agate-fg transition-colors"
              >
                Edit copy
              </button>
              {g.has_credentials && (
                <a
                  href={g.settings_url}
                  target="_blank"
                  rel="noreferrer"
                  className="text-xs text-muted hover:text-agate-fg transition-colors"
                >
                  Keys ↗
                </a>
              )}
            </li>
          ))}
        </ul>
      </Card>

      {items.length > visible.length && (
        <Button small onClick={() => setShowAll(true)}>
          Show {items.length - visible.length} more payment methods
        </Button>
      )}

      {editing && (
        <GatewayModal
          gateway={editing}
          onClose={() => setEditing(null)}
          onSaved={(next) => {
            setItems(next)
            setEditing(null)
          }}
        />
      )}
    </div>
  )
}

function GatewayModal({ gateway, onClose, onSaved }) {
  const toast = useToast()
  const [title, setTitle] = useState(gateway.title || '')
  const [description, setDescription] = useState(gateway.description || '')
  const [instructions, setInstructions] = useState(gateway.instructions || '')
  const [saving, setSaving] = useState(false)

  const save = async () => {
    setSaving(true)
    try {
      const payload = { title, description }
      if (gateway.has_instructions) payload.instructions = instructions
      onSaved((await gatewaysApi.update(gateway.id, payload)).items)
      toast.success('Gateway updated.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} title={gateway.method_title}>
      <div className="space-y-4">
        <Field label="Title" help="What the customer sees at checkout.">
          <Input value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
        </Field>
        <Field label="Description" help="Shown under the title when this method is selected.">
          <Textarea rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        </Field>
        {gateway.has_instructions && (
          <Field label="Instructions" help="Added to the thank-you page and order emails.">
            <Textarea rows={3} value={instructions} onChange={(e) => setInstructions(e.target.value)} />
          </Field>
        )}

        {gateway.has_credentials && (
          <p className="text-xs text-faint border border-edge p-3">
            This gateway also has API credentials. Those are managed in wp-admin —{' '}
            <a href={gateway.settings_url} target="_blank" rel="noreferrer" className="text-gold hover:underline">
              open its settings ↗
            </a>
          </p>
        )}

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
