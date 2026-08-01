import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { customersApi, ordersApi } from '../api/endpoints.js'
import { useMeta } from '../context/MetaContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import { Button, Card, Field, Input, Select, Table, TBody, TD, TH, THead, TR, Textarea } from '../components/ui/index.js'
import ProductPicker from '../components/order/ProductPicker.jsx'

const BLANK_ADDRESS = {
  first_name: '',
  last_name: '',
  company: '',
  address_1: '',
  address_2: '',
  city: '',
  state: '',
  postcode: '',
  country: '',
  email: '',
  phone: '',
}

export default function NewOrder() {
  const navigate = useNavigate()
  const toast = useToast()
  const { meta } = useMeta()

  const [lines, setLines] = useState([])
  const [picking, setPicking] = useState(false)
  const [billing, setBilling] = useState(BLANK_ADDRESS)
  const [shippingSame, setShippingSame] = useState(true)
  const [shipping, setShipping] = useState(BLANK_ADDRESS)
  const [shippingCost, setShippingCost] = useState('')
  const [status, setStatus] = useState('pending')
  const [note, setNote] = useState('')
  const [saving, setSaving] = useState(false)

  // Customer lookup
  const [customerTerm, setCustomerTerm] = useState('')
  const [customerResults, setCustomerResults] = useState([])
  const [customer, setCustomer] = useState(null)

  useEffect(() => {
    if (customerTerm.trim().length < 2) {
      setCustomerResults([])
      return undefined
    }
    let cancelled = false
    const timer = setTimeout(() => {
      customersApi
        .list({ search: customerTerm, per_page: 8 })
        .then((data) => !cancelled && setCustomerResults(data.items))
        .catch(() => {})
    }, 300)
    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [customerTerm])

  const symbol = meta?.currency?.symbol ?? '$'
  const itemsTotal = lines.reduce((sum, line) => sum + Number(line.price || 0) * line.quantity, 0)
  const grandTotal = itemsTotal + Number(shippingCost || 0)

  const addProduct = (product) => {
    setLines((current) => {
      const existing = current.find((line) => line.id === product.id)
      if (existing) {
        return current.map((line) =>
          line.id === product.id ? { ...line, quantity: line.quantity + 1 } : line,
        )
      }
      return [
        ...current,
        {
          id: product.id,
          name: product.name,
          price: product.price,
          sku: product.sku,
          thumb: product.thumb,
          quantity: 1,
        },
      ]
    })
  }

  const setQty = (id, quantity) =>
    setLines((current) =>
      current.map((line) => (line.id === id ? { ...line, quantity: Math.max(1, quantity) } : line)),
    )

  const pickCustomer = (user) => {
    setCustomer(user)
    setCustomerTerm('')
    setCustomerResults([])
    setBilling((current) => ({
      ...current,
      email: current.email || user.email,
      first_name: current.first_name || user.name.split(' ')[0] || '',
    }))
  }

  const submit = async () => {
    if (lines.length === 0) {
      toast.error('Add at least one product to the order.')
      return
    }
    setSaving(true)
    try {
      const payload = {
        status,
        customer_id: customer?.id || 0,
        line_items: lines.map((line) => ({ product_id: line.id, quantity: line.quantity })),
        billing,
        shipping: shippingSame ? billing : shipping,
        customer_note: note,
      }
      if (Number(shippingCost)> 0) {
        payload.shipping_lines = [{ method_title: 'Shipping', total: shippingCost }]
      }
      const order = await ordersApi.create(payload)
      toast.success(`Order #${order.number} created.`)
      navigate(`/orders/${order.id}`, { replace: true })
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <PageHeader
        title="New order"
        subtitle="Create an order manually — for phone, WhatsApp or in-person sales"
        actions={
          <>
            <Button onClick={() => navigate('/orders')}>Cancel</Button>
            <Button variant="primary" onClick={submit} disabled={saving || lines.length === 0}>
              {saving ? 'Creating…' : 'Create order'}
            </Button>
          </>
        }
      />

      <div className="grid lg:grid-cols-[1fr_20rem] gap-5 items-start">
        <div className="space-y-5 min-w-0">
          {/* Items */}
          <Card
            title="Products"
            actions={
              <button
                type="button"
                onClick={() => setPicking(true)}
                className="text-xs text-muted hover:text-agate-fg transition-colors"
              >
                + Add product
              </button>
            }
            bodyClass="p-0"
          >
            {lines.length === 0 ? (
              <div className="p-8 text-center">
                <p className="text-sm text-muted mb-3">No products on this order yet.</p>
                <Button small onClick={() => setPicking(true)}>
                  Add the first product
                </Button>
              </div>
            ) : (
              <Table>
                <THead>
                  <TR>
                    <TH className="w-12" />
                    <TH>Product</TH>
                    <TH className="w-24 text-end">Price</TH>
                    <TH className="w-24 text-center">Qty</TH>
                    <TH className="w-28 text-end">Total</TH>
                    <TH className="w-10" />
                  </TR>
                </THead>
                <TBody>
                  {lines.map((line) => (
                    <TR key={line.id}>
                      <TD>
                        {line.thumb ? (
                          <img src={line.thumb} alt="" className="size-9 object-cover border border-edge" />
                        ) : (
                          <div className="size-9 border border-edge bg-sunken" />
                        )}
                      </TD>
                      <TD>
                        <span className="text-fg">{line.name}</span>
                        {line.sku && <span className="block text-xs text-faint">SKU {line.sku}</span>}
                      </TD>
                      <TD align="end" className="text-muted">
                        {symbol}
                        {Number(line.price || 0).toFixed(2)}
                      </TD>
                      <TD align="center">
                        <Input
                          type="number"
                          min="1"
                          value={line.quantity}
                          onChange={(event) => setQty(line.id, Number(event.target.value))}
                          className="w-16 text-center mx-auto"
                        />
                      </TD>
                      <TD align="end" className="text-fg">
                        {symbol}
                        {(Number(line.price || 0) * line.quantity).toFixed(2)}
                      </TD>
                      <TD align="end">
                        <button
                          type="button"
                          onClick={() => setLines((c) => c.filter((l) => l.id !== line.id))}
                          className="text-muted hover:text-danger transition-colors"
                          aria-label="Remove"
                        >
                          ✕
                        </button>
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            )}
          </Card>

          {/* Billing */}
          <Card title="Billing address">
            <AddressForm value={billing} onChange={setBilling} withContact />
          </Card>

          <Card title="Shipping address">
            <label className="flex items-center gap-2 mb-3 cursor-pointer text-sm">
              <input
                type="checkbox"
                checked={shippingSame}
                onChange={(event) => setShippingSame(event.target.checked)}
                className="size-4 accent-[var(--yz-agate)]"
              />
              <span className="text-fg">Same as billing address</span>
            </label>
            {!shippingSame && <AddressForm value={shipping} onChange={setShipping} />}
          </Card>
        </div>

        {/* Sidebar */}
        <div className="space-y-5">
          <Card title="Customer">
            {customer ? (
              <div className="flex items-center gap-2">
                <img src={customer.avatar} alt="" className="size-8 rounded-full border border-edge" />
                <span className="flex-1 min-w-0">
                  <span className="block text-sm text-fg truncate">{customer.name}</span>
                  <span className="block text-xs text-faint truncate">{customer.email}</span>
                </span>
                <button
                  type="button"
                  onClick={() => setCustomer(null)}
                  className="text-muted hover:text-danger"
                  aria-label="Clear customer"
                >
                  ✕
                </button>
              </div>
            ) : (
              <div className="relative">
                <Input
                  value={customerTerm}
                  placeholder="Search users…"
                  onChange={(event) => setCustomerTerm(event.target.value)}
                />
                {customerResults.length > 0 && (
                  <ul className="absolute z-10 left-0 right-0 mt-1 yz-card max-h-56 overflow-y-auto">
                    {customerResults.map((user) => (
                      <li key={user.id}>
                        <button
                          type="button"
                          onClick={() => pickCustomer(user)}
                          className="w-full text-start px-3 py-2 hover:bg-surface2 transition-colors"
                        >
                          <span className="block text-sm text-fg">{user.name}</span>
                          <span className="block text-xs text-faint">{user.email}</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
                <p className="yz-help">Leave empty to create a guest order.</p>
              </div>
            )}
          </Card>

          <Card title="Order">
            <div className="space-y-4">
              <Field label="Status" help="“Pending” sends no email. Other statuses may notify the customer.">
                <Select value={status} onChange={(event) => setStatus(event.target.value)}>
                  {Object.entries(meta?.order_statuses || {}).map(([key, label]) => (
                    <option key={key} value={key}>
                      {label}
                    </option>
                  ))}
                </Select>
              </Field>

              <Field label={`Shipping cost (${symbol})`}>
                <Input
                  type="number"
                  step="0.01"
                  min="0"
                  value={shippingCost}
                  onChange={(event) => setShippingCost(event.target.value)}
                />
              </Field>

              <Field label="Customer note">
                <Textarea rows={2} value={note} onChange={(event) => setNote(event.target.value)} />
              </Field>

              <dl className="pt-3 border-t border-edge space-y-1.5 text-sm">
                <div className="flex justify-between">
                  <dt className="text-muted">Items</dt>
                  <dd className="text-fg">
                    {symbol}
                    {itemsTotal.toFixed(2)}
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Shipping</dt>
                  <dd className="text-fg">
                    {symbol}
                    {Number(shippingCost || 0).toFixed(2)}
                  </dd>
                </div>
                <div className="flex justify-between pt-1.5 border-t border-edge">
                  <dt className="text-muted">Estimated total</dt>
                  <dd className="text-fg font-medium">
                    {symbol}
                    {grandTotal.toFixed(2)}
                  </dd>
                </div>
              </dl>
              <p className="text-xs text-faint">
                WooCommerce recalculates the final total (including tax) when the order is created.
              </p>
            </div>
          </Card>
        </div>
      </div>

      <ProductPicker open={picking} onClose={() => setPicking(false)} onSelect={addProduct} />
    </>
  )
}

function AddressForm({ value, onChange, withContact }) {
  const set = (key, val) => onChange({ ...value, [key]: val })
  return (
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label="First name">
        <Input value={value.first_name} onChange={(e) => set('first_name', e.target.value)} />
      </Field>
      <Field label="Last name">
        <Input value={value.last_name} onChange={(e) => set('last_name', e.target.value)} />
      </Field>
      <Field label="Address" className="sm:col-span-2">
        <Input value={value.address_1} onChange={(e) => set('address_1', e.target.value)} />
      </Field>
      <Field label="City">
        <Input value={value.city} onChange={(e) => set('city', e.target.value)} />
      </Field>
      <Field label="State / Region">
        <Input value={value.state} onChange={(e) => set('state', e.target.value)} />
      </Field>
      <Field label="Postcode">
        <Input value={value.postcode} onChange={(e) => set('postcode', e.target.value)} />
      </Field>
      <Field label="Country code" help="Two letters, e.g. YE, SA, AE.">
        <Input
          value={value.country}
          maxLength={2}
          onChange={(e) => set('country', e.target.value.toUpperCase())}
        />
      </Field>
      {withContact && (
        <>
          <Field label="Email">
            <Input type="email" value={value.email} onChange={(e) => set('email', e.target.value)} />
          </Field>
          <Field label="Phone">
            <Input value={value.phone} onChange={(e) => set('phone', e.target.value)} />
          </Field>
        </>
      )}
    </div>
  )
}
