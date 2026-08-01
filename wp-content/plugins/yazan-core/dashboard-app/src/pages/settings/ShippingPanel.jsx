import { useCallback, useEffect, useState } from 'react'
import { shippingApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Badge, Button, Card, Field, Input, Modal, Select, Spinner, useConfirm } from '../../components/ui/index.js'

export default function ShippingPanel() {
  const [confirm, confirmDialog] = useConfirm()
  const toast = useToast()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [zoneModal, setZoneModal] = useState(null)
  const [methodFor, setMethodFor] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await shippingApi.zones())
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const removeZone = async (zone) => {
    if (!(await confirm({ title: `Delete the zone “${zone.name}” and its ${zone.methods.length} method(s)?` }))) return
    try {
      await shippingApi.deleteZone(zone.id)
      toast.success('Zone deleted.')
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const addMethod = async (zone, methodId) => {
    if (!methodId) return
    try {
      await shippingApi.addMethod(zone.id, methodId)
      toast.success('Method added.')
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const toggleMethod = async (zone, method) => {
    try {
      await shippingApi.updateMethod(zone.id, method.instance_id, { enabled: !method.enabled })
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const removeMethod = async (zone, method) => {
    if (!(await confirm({ title: `Remove “${method.title}” from ${zone.name}?` }))) return
    try {
      await shippingApi.deleteMethod(zone.id, method.instance_id)
      toast.success('Method removed.')
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  if (loading || !data) return <Spinner />

  return (
    <>
    <div className="space-y-5">
      <div className="flex justify-end">
        <Button variant="primary" onClick={() => setZoneModal({ isNew: true, name: '', regions: [] })}>
          Add zone
        </Button>
      </div>

      {data.zones.map((zone) => (
        <Card
          key={zone.id}
          title={zone.name}
          actions={
            <div className="flex items-center gap-3">
              {zone.is_fallback ? (
                <Badge tone="muted">Fallback</Badge>
              ) : (
                <span className="text-xs text-muted">{zone.region_text}</span>
              )}
              {!zone.is_fallback && (
                <>
                  <button
                    type="button"
                    onClick={() => setZoneModal({ ...zone })}
                    className="text-xs text-muted hover:text-agate-fg transition-colors"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    onClick={() => removeZone(zone)}
                    className="text-xs text-muted hover:text-danger transition-colors"
                  >
                    Delete
                  </button>
                </>
              )}
            </div>
          }
        >
          {zone.is_fallback && (
            <p className="text-xs text-faint mb-3">
              Applies to any address not matched by a zone above. It cannot be deleted.
            </p>
          )}

          {zone.methods.length === 0 ? (
            <p className="text-sm text-muted mb-3">No shipping methods — customers here cannot check out.</p>
          ) : (
            <ul className="divide-y divide-divider mb-3">
              {zone.methods.map((m) => (
                <li key={m.instance_id} className="flex flex-wrap items-center gap-3 py-2">
                  <input
                    type="checkbox"
                    checked={m.enabled}
                    onChange={() => toggleMethod(zone, m)}
                    title={m.enabled ? 'Enabled' : 'Disabled'}
                    className="size-4 accent-[var(--yz-agate)]"
                  />
                  <span className="flex-1 min-w-0">
                    <span className={`block text-sm ${m.enabled ? 'text-fg' : 'text-faint line-through'}`}>
                      {m.title}
                    </span>
                    <span className="block text-xs text-faint">{m.method_title}</span>
                  </span>
                  <button
                    type="button"
                    onClick={() => setMethodFor({ zone, method: m })}
                    className="text-xs text-muted hover:text-agate-fg transition-colors"
                  >
                    Configure
                  </button>
                  <button
                    type="button"
                    onClick={() => removeMethod(zone, m)}
                    className="text-xs text-muted hover:text-danger transition-colors"
                  >
                    Remove
                  </button>
                </li>
              ))}
            </ul>
          )}

          <Select
            value=""
            onChange={(e) => addMethod(zone, e.target.value)}
            className="w-auto"
            aria-label={`Add a method to ${zone.name}`}
          >
            <option value="">+ Add shipping method…</option>
            {data.available_methods.map((m) => (
              <option key={m.id} value={m.id}>
                {m.title}
              </option>
            ))}
          </Select>
        </Card>
      ))}

      {zoneModal && (
        <ZoneModal
          zone={zoneModal}
          countries={data.countries}
          continents={data.continents}
          onClose={() => setZoneModal(null)}
          onSaved={() => {
            setZoneModal(null)
            load()
          }}
        />
      )}

      {methodFor && (
        <MethodModal
          zone={methodFor.zone}
          method={methodFor.method}
          onClose={() => setMethodFor(null)}
          onSaved={() => {
            setMethodFor(null)
            load()
          }}
        />
      )}
    </div>
      {confirmDialog}
    </>
  )
}

function ZoneModal({ zone, countries, continents, onClose, onSaved }) {
  const toast = useToast()
  const [name, setName] = useState(zone.name || '')
  const [regions, setRegions] = useState(zone.regions || [])
  const [saving, setSaving] = useState(false)

  const addRegion = (type, code) => {
    if (!code || regions.some((r) => r.type === type && r.code === code)) return
    setRegions([...regions, { type, code }])
  }

  const save = async () => {
    if (!name.trim()) {
      toast.error('A zone name is required.')
      return
    }
    setSaving(true)
    try {
      if (zone.isNew) await shippingApi.createZone({ name, regions })
      else await shippingApi.updateZone(zone.id, { name, regions })
      toast.success(zone.isNew ? 'Zone created.' : 'Zone updated.')
      onSaved()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} title={zone.isNew ? 'New shipping zone' : `Edit “${zone.name}”`}>
      <div className="space-y-4">
        <Field label="Zone name">
          <Input value={name} onChange={(e) => setName(e.target.value)} autoFocus />
        </Field>

        <div>
          <span className="yz-label">Regions</span>
          {regions.length === 0 ? (
            <p className="text-sm text-muted mb-2">
              No regions — this zone will never match. Add at least one.
            </p>
          ) : (
            <div className="flex flex-wrap gap-1.5 mb-2">
              {regions.map((r, i) => (
                <span key={`${r.type}-${r.code}`} className="yz-badge border-edge text-muted flex items-center gap-2">
                  {r.type === 'continent' ? continents[r.code] || r.code : countries[r.code] || r.code}
                  <button
                    type="button"
                    onClick={() => setRegions(regions.filter((_, x) => x !== i))}
                    className="hover:text-danger"
                    aria-label="Remove region"
                  >
                    ✕
                  </button>
                </span>
              ))}
            </div>
          )}
          <div className="grid sm:grid-cols-2 gap-2">
            <Select value="" onChange={(e) => addRegion('country', e.target.value)}>
              <option value="">Add a country…</option>
              {Object.entries(countries).map(([code, label]) => (
                <option key={code} value={code}>
                  {label}
                </option>
              ))}
            </Select>
            <Select value="" onChange={(e) => addRegion('continent', e.target.value)}>
              <option value="">Add a continent…</option>
              {Object.entries(continents).map(([code, label]) => (
                <option key={code} value={code}>
                  {label}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div className="flex justify-end gap-2 pt-3 border-t border-edge">
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save zone'}
          </Button>
        </div>
      </div>
    </Modal>
  )
}

function MethodModal({ zone, method, onClose, onSaved }) {
  const toast = useToast()
  const [values, setValues] = useState(() =>
    Object.fromEntries(method.fields.map((f) => [f.key, f.value ?? ''])),
  )
  const [saving, setSaving] = useState(false)

  const save = async () => {
    setSaving(true)
    try {
      await shippingApi.updateMethod(zone.id, method.instance_id, { settings: values })
      toast.success('Method updated.')
      onSaved()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} title={`${method.method_title} — ${zone.name}`}>
      <div className="space-y-4">
        {method.fields.map((f) => (
          <Field key={f.key} label={f.label} help={f.description}>
            {f.options ? (
              <Select value={values[f.key] ?? ''} onChange={(e) => setValues({ ...values, [f.key]: e.target.value })}>
                {Object.entries(f.options).map(([k, v]) => (
                  <option key={k} value={k}>
                    {v}
                  </option>
                ))}
              </Select>
            ) : (
              <Input
                value={values[f.key] ?? ''}
                onChange={(e) => setValues({ ...values, [f.key]: e.target.value })}
              />
            )}
          </Field>
        ))}

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
