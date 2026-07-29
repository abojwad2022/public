import { useCallback, useEffect, useState } from 'react'
import { taxApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import {
  useConfirm,
  Badge,
  Button,
  Card,
  Checkbox,
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
} from '../../components/ui/index.js'

export default function TaxPanel() {
  const [confirm, confirmDialog] = useConfirm()
  const toast = useToast()
  const [data, setData] = useState(null)
  const [edits, setEdits] = useState({})
  const [taxClass, setTaxClass] = useState('')
  const [rates, setRates] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const d = await taxApi.get()
      setData(d)
      setEdits({})
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  const loadRates = useCallback(async () => {
    try {
      setRates((await taxApi.rates(taxClass)).items)
    } catch (err) {
      toast.error(err.message)
    }
  }, [taxClass, toast])

  useEffect(() => {
    load()
  }, [load])
  useEffect(() => {
    loadRates()
  }, [loadRates])

  const dirty = Object.keys(edits)
  const valueOf = (f) => (f.option in edits ? edits[f.option] : f.value)

  const save = async () => {
    setSaving(true)
    try {
      setData(await taxApi.saveSettings(edits))
      setEdits({})
      toast.success('Tax settings saved.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  const addRate = async () => {
    try {
      await taxApi.createRate({ country: '*', state: '*', rate: '0', name: 'Tax', priority: 1, shipping: true, class: taxClass })
      loadRates()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const saveRate = async (rate, patch) => {
    try {
      await taxApi.updateRate(rate.id, { ...rate, ...patch, class: taxClass })
      loadRates()
    } catch (err) {
      toast.error(err.message)
      loadRates()
    }
  }

  const removeRate = async (rate) => {
    if (!(await confirm({ title: `Delete the “${rate.name}” rate?` }))) return
    try {
      await taxApi.deleteRate(rate.id)
      loadRates()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const addClass = async () => {
    const name = window.prompt('Name for the new tax class:')
    if (!name?.trim()) return
    try {
      await taxApi.createClass(name)
      toast.success('Tax class added.')
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const removeClass = async (cls) => {
    if (!(await confirm({ title: `Delete the “${cls.name}” tax class and its ${cls.rates} rate(s)?` }))) return
    try {
      await taxApi.deleteClass(cls.slug)
      toast.success('Tax class deleted.')
      if (taxClass === cls.slug) setTaxClass('')
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  if (loading || !data) return <Spinner />

  return (
    <>
    <div className="space-y-5">
      {!data.enabled && (
        <p className="yz-card p-3 text-sm text-warn border-s-2 border-warn">
          Taxes are currently disabled — rates below are stored but not applied at checkout.
        </p>
      )}

      <Card
        title="Tax options"
        actions={
          dirty.length > 0 && (
            <Button small variant="primary" onClick={save} disabled={saving}>
              {saving ? 'Saving…' : `Save ${dirty.length}`}
            </Button>
          )
        }
      >
        <div className="grid sm:grid-cols-2 gap-5">
          {data.fields.map((f) => (
            <div key={f.option} className={f.option in edits ? 'border-s-2 border-warn ps-3' : ''}>
              {f.type === 'bool_yesno' ? (
                <Checkbox
                  label={f.label}
                  checked={Boolean(valueOf(f))}
                  onChange={(v) => setEdits({ ...edits, [f.option]: v })}
                />
              ) : f.type === 'select' ? (
                <Field label={f.label}>
                  <Select
                    value={valueOf(f) ?? ''}
                    onChange={(e) => setEdits({ ...edits, [f.option]: e.target.value })}
                  >
                    {Object.entries(f.choices || {}).map(([k, v]) => (
                      <option key={k} value={k}>
                        {v}
                      </option>
                    ))}
                  </Select>
                </Field>
              ) : (
                <Field label={f.label}>
                  <Input
                    value={valueOf(f) ?? ''}
                    onChange={(e) => setEdits({ ...edits, [f.option]: e.target.value })}
                  />
                </Field>
              )}
            </div>
          ))}
        </div>
      </Card>

      <Card
        title="Tax classes"
        actions={
          <button type="button" onClick={addClass} className="text-xs text-muted hover:text-agate-fg transition-colors">
            + Add class
          </button>
        }
      >
        <div className="flex flex-wrap gap-2">
          {data.classes.map((c) => (
            <span
              key={c.slug || 'standard'}
              className={`yz-badge flex items-center gap-2 cursor-pointer ${
                taxClass === c.slug ? 'border-gold text-gold' : 'border-edge text-muted'
              }`}
              onClick={() => setTaxClass(c.slug)}
              role="button"
              tabIndex={0}
              onKeyDown={(e) => e.key === 'Enter' && setTaxClass(c.slug)}
            >
              {c.name}
              <span className="text-faint">{c.rates}</span>
              {c.slug && (
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation()
                    removeClass(c)
                  }}
                  className="hover:text-danger"
                  aria-label={`Delete ${c.name}`}
                >
                  ✕
                </button>
              )}
            </span>
          ))}
        </div>
      </Card>

      <Card
        title={`Rates — ${data.classes.find((c) => c.slug === taxClass)?.name ?? 'Standard'}`}
        actions={
          <button type="button" onClick={addRate} className="text-xs text-muted hover:text-agate-fg transition-colors">
            + Add rate
          </button>
        }
        bodyClass="p-0"
      >
        {rates.length === 0 ? (
          <p className="p-6 text-sm text-muted text-center">No rates in this class.</p>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <THead>
                <TR>
                  <TH className="w-24">Country</TH>
                  <TH className="w-24">State</TH>
                  <TH>Postcode</TH>
                  <TH>City</TH>
                  <TH className="w-24">Rate %</TH>
                  <TH>Name</TH>
                  <TH className="w-20 text-center">Ship</TH>
                  <TH className="w-10" />
                </TR>
              </THead>
              <TBody>
                {rates.map((r) => (
                  <TR key={r.id}>
                    {['country', 'state', 'postcode', 'city', 'rate', 'name'].map((k) => (
                      <TD key={k}>
                        <Input
                          defaultValue={r[k]}
                          onBlur={(e) => e.target.value !== String(r[k]) && saveRate(r, { [k]: e.target.value })}
                        />
                      </TD>
                    ))}
                    <TD align="center">
                      <input
                        type="checkbox"
                        checked={r.shipping}
                        onChange={(e) => saveRate(r, { shipping: e.target.checked })}
                        className="size-4 accent-[var(--yz-agate)]"
                      />
                    </TD>
                    <TD align="end">
                      <button
                        type="button"
                        onClick={() => removeRate(r)}
                        className="text-muted hover:text-danger transition-colors"
                        aria-label="Delete rate"
                      >
                        ✕
                      </button>
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
        )}
        <p className="px-4 py-2 text-xs text-faint border-t border-edge">
          Use <code>*</code > for “any”. Separate multiple postcodes or cities with <code>;</code>.
          Changes save when you leave a field.
        </p>
      </Card>
    </div>
      {confirmDialog}
    </>
  )
}
