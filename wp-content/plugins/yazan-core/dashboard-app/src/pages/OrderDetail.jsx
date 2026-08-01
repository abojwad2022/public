import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { ordersApi } from '../api/endpoints.js'
import { useMeta } from '../context/MetaContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import {
  Badge,
  Button,
  Card,
  Checkbox,
  Field,
  Input,
  Modal,
  Select,
  Skeleton,
  Table,
  TBody,
  TD,
  TH,
  THead,
  TR,
  Textarea,
  useConfirm,
} from '../components/ui/index.js'
import ProductPicker from '../components/order/ProductPicker.jsx'
import { statusTone } from './Orders.jsx'

export default function OrderDetail() {
  const [confirm, confirmDialog] = useConfirm()
  const { id } = useParams()
  const { meta } = useMeta()
  const toast = useToast()
  const navigate = useNavigate()

  const [order, setOrder] = useState(null)
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState('')
  const [saving, setSaving] = useState(false)
  const [noteText, setNoteText] = useState('')
  const [noteToCustomer, setNoteToCustomer] = useState(false)
  const [addingNote, setAddingNote] = useState(false)
  const [picking, setPicking] = useState(false)
  const [busyItems, setBusyItems] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await ordersApi.get(id)
      setOrder(data)
      setStatus(data.status)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [id, toast])

  useEffect(() => {
    load()
  }, [load])

  const money = (value) => {
    const symbol = order?.currency_symbol ?? '$'
    return `${symbol}${Number(value || 0).toFixed(2)}`
  }

  const saveStatus = async () => {
    if (status === order.status) return
    const label = meta?.order_statuses?.[status] || status
    if (!(await confirm({ title: `Change this order to “${label}”? WooCommerce may email the customer.` }))) return
    setSaving(true)
    try {
      const updated = await ordersApi.update(order.id, { status })
      setOrder(updated)
      setStatus(updated.status)
      toast.success('Order status updated.')
    } catch (err) {
      toast.error(err.message)
      setStatus(order.status)
    } finally {
      setSaving(false)
    }
  }

  const addNote = async () => {
    if (!noteText.trim()) return
    setAddingNote(true)
    try {
      const notes = await ordersApi.addNote(order.id, noteText, noteToCustomer)
      setOrder((current) => ({ ...current, notes }))
      setNoteText('')
      setNoteToCustomer(false)
      toast.success(noteToCustomer ? 'Note added and emailed to the customer.' : 'Private note added.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setAddingNote(false)
    }
  }

  /* --- line items (only while the order is editable) -------------------- */
  const mutateItems = async (payload, successMessage) => {
    setBusyItems(true)
    try {
      const updated = await ordersApi.updateItems(order.id, payload)
      setOrder(updated)
      toast.success(successMessage)
    } catch (err) {
      toast.error(err.message)
      load() // Re-sync so the UI never drifts from the server after a rejection.
    } finally {
      setBusyItems(false)
    }
  }

  const changeQty = (item, quantity) => {
    if (quantity === item.quantity || quantity < 1) return
    mutateItems({ items: [{ id: item.id, quantity }] }, 'Quantity updated.')
  }

  const removeItem = async (item) => {
    if (!(await confirm({ title: `Remove “${item.name}” from this order?` }))) return
    mutateItems({ remove: [item.id] }, 'Item removed.')
  }

  const addItem = (product) => mutateItems({ add: [{ product_id: product.id, quantity: 1 }] }, 'Product added.')

  if (loading || !order) {
    return (
      <div aria-busy="true" aria-label="Loading order">
        <Skeleton w="220px" h={28} rounded="md" />
        <Skeleton w="300px" h={14} className="mt-2" />
        <div className="mt-5 grid items-start gap-5 lg:grid-cols-[1fr_20rem]">
          <Skeleton w="100%" h={320} rounded="lg" />
          <Skeleton w="100%" h={200} rounded="lg" />
        </div>
      </div>
    )
  }

  const editable = Boolean(order.is_editable)

  return (
    <>
      <PageHeader
        title={`Order #${order.number}`}
        subtitle={`${order.date_created}${order.payment_method ? ` · ${order.payment_method}` : ''}`}
        actions={
          <>
            <Button onClick={() => navigate('/orders')}>Back</Button>
            <a href={order.edit_url} target="_blank" rel="noreferrer" className="yz-btn yz-btn-ghost">
              Open in wp-admin
            </a>
          </>
        }
      />

      <div className="grid lg:grid-cols-[1fr_20rem] gap-5 items-start">
        {/* Main */}
        <div className="space-y-5 min-w-0">
          <Card
            title="Items"
            actions={
              editable ? (
                <button
                  type="button"
                  onClick={() => setPicking(true)}
                  disabled={busyItems}
                  className="text-xs text-muted hover:text-agate-fg transition-colors disabled:opacity-50"
                >
                  + Add product
                </button>
              ) : (
                <Badge tone="muted">Locked</Badge>
              )
            }
            bodyClass="p-0"
          >
            <div className="overflow-x-auto">
              <Table>
                <THead>
                  <TR>
                    <TH className="w-12" />
                    <TH>Product</TH>
                    <TH align="center" className="w-24">Qty</TH>
                    <TH align="end" className="w-28">Total</TH>
                    {editable && <TH className="w-10" />}
                  </TR>
                </THead>
                <TBody>
                  {(order.items || []).map((item) => (
                    <TR key={item.id}>
                      <TD>
                        {item.thumb ? (
                          <img src={item.thumb} alt="" className="size-10 object-cover border border-edge" />
                        ) : (
                          <div className="size-10 border border-edge bg-sunken" />
                        )}
                      </TD>
                      <TD>
                        {item.product_id ? (
                          <Link
                            to={`/products/${item.product_id}`}
                            className="text-fg hover:text-agate-fg transition-colors"
                          >
                            {item.name}
                          </Link>
                        ) : (
                          <span className="text-fg">{item.name}</span>
                        )}
                        <div className="text-xs text-faint mt-0.5 space-x-2">
                          {item.sku && <span>SKU {item.sku}</span>}
                          {item.serial && <span className="text-gold">Serial {item.serial}</span>}
                        </div>
                        {(item.meta || []).map((m, index) => (
                          <div key={index} className="text-xs text-muted">
                            {m.label}: {m.value}
                          </div>
                        ))}
                      </TD>
                      <TD align="center">
                        {editable ? (
                          <Input
                            type="number"
                            min="1"
                            defaultValue={item.quantity}
                            disabled={busyItems}
                            onBlur={(event) => changeQty(item, Number(event.target.value))}
                            className="w-16 text-center mx-auto"
                          />
                        ) : (
                          <span className="text-muted">×{item.quantity}</span>
                        )}
                      </TD>
                      <TD align="end" className="text-fg whitespace-nowrap">{money(item.total)}</TD>
                      {editable && (
                        <TD align="end">
                          <button
                            type="button"
                            onClick={() => removeItem(item)}
                            disabled={busyItems}
                            className="text-muted hover:text-danger transition-colors disabled:opacity-50"
                            aria-label="Remove item"
                          >
                            ✕
                          </button>
                        </TD>
                      )}
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>

            {!editable && (
              <p className="px-4 py-2 text-xs text-faint border-t border-edge">
                This order is {order.status_label.toLowerCase()}, so its items are locked — the same
                rule WooCommerce applies. Issue a refund instead of editing.
              </p>
            )}

            {/* Totals */}
            <div className="border-t border-edge p-4">
              <dl className="ml-auto max-w-xs space-y-1.5 text-sm">
                <Row label="Subtotal" value={money(order.totals.subtotal)} />
                {Number(order.totals.discount_total)> 0 && (
                  <Row label="Discount" value={`−${money(order.totals.discount_total)}`} />
                )}
                {(order.shipping_lines || []).map((line, index) => (
                  <Row key={index} label={line.name || 'Shipping'} value={money(line.total)} />
                ))}
                {(order.fee_lines || []).map((line, index) => (
                  <Row key={index} label={line.name || 'Fee'} value={money(line.total)} />
                ))}
                {Number(order.totals.total_tax)> 0 && <Row label="Tax" value={money(order.totals.total_tax)} />}
                <div className="pt-2 border-t border-edge">
                  <Row label="Total" value={money(order.totals.total)} strong />
                </div>
                {Number(order.totals.refunded)> 0 && (
                  <Row label="Refunded" value={`−${money(order.totals.refunded)}`} tone="danger" />
                )}
              </dl>
              {(order.coupons || []).length > 0 && (
                <p className="text-xs text-muted mt-3">Coupons: {order.coupons.join(', ')}</p>
              )}
            </div>
          </Card>

          <RefundCard order={order} onChange={setOrder} money={money} />

          {order.customer_note && (
            <Card title="Customer note">
              <p className="text-sm text-fg whitespace-pre-line">{order.customer_note}</p>
            </Card>
          )}

          <AddressCards order={order} onChange={setOrder} />

          <CouponCard order={order} onChange={setOrder} money={money} />

          <Card title="Order notes">
            <div className="space-y-3 mb-4">
              <Textarea
                rows={3}
                value={noteText}
                onChange={(event) => setNoteText(event.target.value)}
                placeholder="Add a note about this order…"
              />
              <div className="flex flex-wrap items-center justify-between gap-3">
                <Checkbox
                  label="Email this note to the customer"
                  checked={noteToCustomer}
                  onChange={setNoteToCustomer}
                />
                <Button variant="primary" small onClick={addNote} disabled={addingNote || !noteText.trim()}>
                  {addingNote ? 'Adding…' : 'Add note'}
                </Button>
              </div>
            </div>

            <ul className="space-y-2 border-t border-edge pt-3">
              {(order.notes || []).length === 0 && <li className="text-sm text-muted">No notes yet.</li>}
              {(order.notes || []).map((note) => (
                <li
                  key={note.id}
                  className={`border-s-2 ps-3 py-1 ${
                    note.customer_note ? 'border-gold' : note.system ? 'border-edge' : 'border-agate'
                  }`}
                >
                  <div
                    className="text-sm text-fg [&_p]:m-0"
                    dangerouslySetInnerHTML={{ __html: note.content }}
                  />
                  <span className="text-xs text-faint">
                    {note.date} · {note.author}
                    {note.customer_note && <span className="text-gold"> · emailed to customer</span>}
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        </div>

        {/* Sidebar */}
        <div className="space-y-5">
          <Card title="Status" actions={<Badge tone={statusTone(order.status)}>{order.status_label}</Badge>}>
            <div className="space-y-3">
              <Select value={status} onChange={(event) => setStatus(event.target.value)}>
                {Object.entries(meta?.order_statuses || {}).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </Select>
              <Button
                variant="primary"
                className="w-full"
                onClick={saveStatus}
                disabled={saving || status === order.status}
              >
                {saving ? 'Updating…' : 'Update status'}
              </Button>
              <p className="text-xs text-faint">
                Changing status runs WooCommerce's normal actions — customer emails, stock changes and
                the Yazan invoice auto-print all still fire.
              </p>
            </div>
          </Card>

          <Card title="Details">
            <dl className="space-y-2 text-sm">
              <Row label="Payment" value={order.payment_method || '—'} />
              <Row label="Paid" value={order.date_paid || 'Not paid'} />
              {order.date_completed && <Row label="Completed" value={order.date_completed} />}
              {order.transaction_id && <Row label="Transaction" value={order.transaction_id} />}
              <Row label="Customer" value={order.customer_id ? `#${order.customer_id}` : 'Guest'} />
            </dl>
          </Card>
        </div>
      </div>

      <ProductPicker open={picking} onClose={() => setPicking(false)} onSelect={addItem} />
      {confirmDialog}
    </>
  )
}

/* ---------------------------------------------------------------- Refunds */

function RefundCard({ order, onChange, money }) {
  const [confirm, confirmDialog] = useConfirm()
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [restock, setRestock] = useState(true)
  const [viaGateway, setViaGateway] = useState(false)
  const [busy, setBusy] = useState(false)

  const remaining = Number(order.remaining_refund || 0)
  const canGateway = order.gateway_refund_supported && order.can_gateway_refund

  const submit = async () => {
    const value = Number(amount)
    if (!(value > 0)) {
      toast.error('Enter an amount greater than zero.')
      return
    }
    if (value > remaining + 0.001) {
      toast.error(`The most you can refund on this order is ${money(remaining)}.`)
      return
    }

    // Manual refunds are reversible (the record can be deleted). Gateway refunds are not,
    // so they get a second, explicit confirmation.
    const first = viaGateway
      ? `Send ${money(value)} back to the customer through ${order.payment_method}? This moves real money and CANNOT be undone.`
      : `Record a manual refund of ${money(value)}? No money is moved — you settle it yourself.`
    if (!(await confirm({ title: first }))) return
    // Second confirmation, deliberately kept: the first asks about the refund,
    // this one asks about actually moving money through the gateway.
    if (
      viaGateway &&
      !(await confirm({
        title: `Refund ${money(value)} through the payment gateway?`,
        body: 'This sends money back to the customer now and cannot be undone from here.',
        confirmLabel: 'Refund now',
      }))
    )
      return

    setBusy(true)
    try {
      const updated = await ordersApi.createRefund(order.id, {
        amount: String(value),
        reason,
        restock,
        refund_payment: viaGateway,
      })
      onChange(updated)
      setAmount('')
      setReason('')
      setViaGateway(false)
      setOpen(false)
      toast.success(viaGateway ? 'Refund sent through the gateway.' : 'Manual refund recorded.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const removeRefund = async (refund) => {
    if (
      !(await confirm({
        title: `Delete the ${money(refund.amount)} refund record?`,
        body:
          'This removes the record only. Any money already sent through the payment gateway is NOT reversed.',
        confirmLabel: 'Delete record',
      }))
    )
      return
    try {
      const updated = await ordersApi.deleteRefund(order.id, refund.id)
      onChange(updated)
      toast.success('Refund record deleted.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  return (
    <Card
      title="Refunds"
      actions={
        remaining > 0 ? (
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            className="text-xs text-muted hover:text-agate-fg transition-colors"
          >
            {open ? 'Cancel' : 'Issue refund'}
          </button>
        ) : (
          <Badge tone="muted">Fully refunded</Badge>
        )
      }
    >
      {(order.refunds || []).length > 0 && (
        <ul className="space-y-2 mb-4">
          {(order.refunds || []).map((refund) => (
            <li key={refund.id} className="flex items-start justify-between gap-3 border-s-2 border-danger ps-3">
              <span className="min-w-0">
                <span className="block text-sm text-danger">−{money(refund.amount)}</span>
                <span className="block text-xs text-faint">
                  {refund.date}
                  {refund.by ? ` · ${refund.by}` : ''}
                  {refund.reason ? ` · ${refund.reason}` : ''}
                </span>
              </span>
              {order.can_gateway_refund && (
                <button
                  type="button"
                  onClick={() => removeRefund(refund)}
                  className="text-xs text-muted hover:text-danger transition-colors shrink-0"
                >
                  Delete
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {(order.refunds || []).length === 0 && !open && (
        <p className="text-sm text-muted">No refunds on this order.</p>
      )}

      {open && (
        <div className="space-y-3 border-t border-edge pt-3">
          <div className="grid sm:grid-cols-2 gap-3">
            <Field label="Amount" help={`Up to ${money(remaining)} remaining.`}>
              <Input
                type="number"
                step="0.01"
                min="0"
                max={remaining}
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
                autoFocus
              />
            </Field>
            <Field label="Reason (optional)">
              <Input value={reason} onChange={(event) => setReason(event.target.value)} />
            </Field>
          </div>

          <Checkbox
            label="Restock the refunded items"
            checked={restock}
            onChange={setRestock}
            help="Returns the ring to inventory."
          />

          <Checkbox
            label="Refund through the payment gateway"
            checked={viaGateway}
            onChange={setViaGateway}
            disabled={!canGateway}
            help={
              !order.gateway_refund_supported
                ? `${order.payment_method || 'This payment method'} cannot process automatic refunds — record a manual refund and settle it yourself.`
                : !order.can_gateway_refund
                  ? 'Requires the manage_woocommerce capability.'
                  : 'Sends real money back to the customer. This cannot be undone.'
            }
          />

          {viaGateway && (
            <p className="border border-danger text-danger text-xs px-3 py-2">
              This will move real money through {order.payment_method}. It cannot be reversed from
              here.
            </p>
          )}

          <div className="flex justify-end">
            <Button variant={viaGateway ? 'danger' : 'primary'} onClick={submit} disabled={busy}>
              {busy ? 'Processing…' : viaGateway ? 'Refund via gateway' : 'Record manual refund'}
            </Button>
          </div>
        </div>
      )}
      {confirmDialog}
    </Card>
  )
}

/* ------------------------------------------------------- addresses */

const ADDRESS_FIELDS = [
  ['first_name', 'First name'],
  ['last_name', 'Last name'],
  ['company', 'Company'],
  ['address_1', 'Address'],
  ['address_2', 'Address line 2'],
  ['city', 'City'],
  ['state', 'State / Region'],
  ['postcode', 'Postcode'],
  ['country', 'Country code'],
]

/**
 * Billing/shipping stay editable even after payment — a corrected delivery address is routine
 * and moves no money. The server only recalculates totals if the country/state changed.
 */
function AddressCards({ order, onChange }) {
  const toast = useToast()
  const [editing, setEditing] = useState(null) // 'billing' | 'shipping'
  const [draft, setDraft] = useState({})
  const [saving, setSaving] = useState(false)

  const open = (type) => {
    setDraft({ ...order[type] })
    setEditing(type)
  }

  const save = async () => {
    setSaving(true)
    try {
      const updated = await ordersApi.updateAddresses(order.id, { [editing]: draft })
      onChange(updated)
      toast.success('Address updated.')
      setEditing(null)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <div className="grid sm:grid-cols-2 gap-5">
        {['billing', 'shipping'].map((type) => (
          <Card
            key={type}
            title={type === 'billing' ? 'Billing' : 'Shipping'}
            actions={
              <button
                type="button"
                onClick={() => open(type)}
                className="text-xs text-muted hover:text-agate-fg transition-colors"
              >
                Edit
              </button>
            }
          >
            <address className="not-italic text-sm text-fg whitespace-pre-line">
              {order[`${type}_formatted`]}
            </address>
            {type === 'billing' && (
              <div className="mt-3 space-y-1 text-sm">
                {order.billing.email && (
                  <a href={`mailto:${order.billing.email}`} className="block text-gold hover:underline">
                    {order.billing.email}
                  </a>
                )}
                {order.billing.phone && <span className="block text-muted">{order.billing.phone}</span>}
              </div>
            )}
          </Card>
        ))}
      </div>

      <Modal
        open={Boolean(editing)}
        onClose={() => setEditing(null)}
        title={editing === 'billing' ? 'Edit billing address' : 'Edit shipping address'}
      >
        <div className="grid sm:grid-cols-2 gap-3">
          {ADDRESS_FIELDS.map(([key, label]) => (
            <Field key={key} label={label} className={key.startsWith('address') ? 'sm:col-span-2' : ''}>
              <Input
                value={draft[key] || ''}
                maxLength={key === 'country' ? 2 : undefined}
                onChange={(e) =>
                  setDraft({ ...draft, [key]: key === 'country' ? e.target.value.toUpperCase() : e.target.value })
                }
              />
            </Field>
          ))}
          {editing === 'billing' && (
            <>
              <Field label="Email">
                <Input
                  type="email"
                  value={draft.email || ''}
                  onChange={(e) => setDraft({ ...draft, email: e.target.value })}
                />
              </Field>
              <Field label="Phone">
                <Input value={draft.phone || ''} onChange={(e) => setDraft({ ...draft, phone: e.target.value })} />
              </Field>
            </>
          )}
        </div>
        <div className="flex justify-end gap-2 pt-4 mt-4 border-t border-edge">
          <Button onClick={() => setEditing(null)}>Cancel</Button>
          <Button variant="primary" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save address'}
          </Button>
        </div>
      </Modal>
    </>
  )
}

/* ---------------------------------------------------------- coupons */

function CouponCard({ order, onChange }) {
  const [confirm, confirmDialog] = useConfirm()
  const toast = useToast()
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const editable = Boolean(order.is_editable)

  const add = async () => {
    if (!code.trim()) return
    setBusy(true)
    try {
      onChange(await ordersApi.addCoupon(order.id, code.trim()))
      setCode('')
      toast.success('Coupon applied.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const remove = async (c) => {
    if (!(await confirm({ title: `Remove coupon “${c}” from this order?` }))) return
    try {
      onChange(await ordersApi.removeCoupon(order.id, c))
      toast.success('Coupon removed.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  if (!editable && (order.coupons || []).length === 0) return null

  return (
    <Card title="Coupons">
      {(order.coupons || []).length > 0 ? (
        <ul className="flex flex-wrap gap-2 mb-3">
          {(order.coupons || []).map((c) => (
            <li key={c} className="yz-badge border-gold text-gold flex items-center gap-2">
              <span className="font-mono">{c}</span>
              {editable && (
                <button
                  type="button"
                  onClick={() => remove(c)}
                  className="hover:text-danger"
                  aria-label={`Remove ${c}`}
                >
                  ✕
                </button>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted mb-3">No coupons on this order.</p>
      )}

      {editable ? (
        <div className="flex gap-2">
          <Input
            value={code}
            placeholder="Coupon code…"
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            onKeyDown={(e) => e.key === 'Enter' && add()}
            className="font-mono uppercase"
          />
          <Button variant="primary" onClick={add} disabled={busy || !code.trim()}>
            Apply
          </Button>
        </div>
      ) : (
        <p className="text-xs text-faint">
          Coupons can only be changed while the order is still editable — the total must keep
          matching the amount captured.
        </p>
      )}
      {confirmDialog}
    </Card>
  )
}

function Row({ label, value, strong, tone }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-muted">{label}</dt>
      <dd
        className={`text-end ${strong ? 'text-fg font-medium' : tone === 'danger' ? 'text-danger' : 'text-fg'}`}
      >
        {value}
      </dd>
    </div>
  )
}
