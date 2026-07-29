import { useCallback, useEffect, useState } from 'react'
import { couponsApi } from '../api/endpoints.js'
import { useMeta } from '../context/MetaContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import {
  Badge,
  Button,
  Card,
  Checkbox,
  ConfirmDialog,
  EmptyState,
  Field,
  IconButton,
  Input,
  Modal,
  Pagination,
  SearchInput,
  Select,
  SkeletonTable,
  Table,
  Tabs,
  TBody,
  TD,
  TH,
  THead,
  TR,
  Textarea,
} from '../components/ui/index.js'
import { Pencil, Plus, Ticket, Trash2 } from '../components/ui/icons.js'

const TYPE_LABELS = {
  percent: 'Percentage discount',
  fixed_cart: 'Fixed cart discount',
  fixed_product: 'Fixed product discount',
}

export default function Coupons() {
  const toast = useToast()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await couponsApi.list({ search, page, per_page: 20 }))
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [search, page, toast])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput)
      setPage(1)
    }, 350)
    return () => clearTimeout(t)
  }, [searchInput])

  const remove = (coupon) => {
    setConfirm({
      title: `Delete “${coupon.code}”?`,
      body:
        coupon.usage_count > 0
          ? `This coupon has been used ${coupon.usage_count} time${coupon.usage_count === 1 ? '' : 's'}. Deleting it does not affect past orders, but customers can no longer redeem it.`
          : 'Customers will no longer be able to redeem this coupon.',
      onConfirm: async () => {
        setBusy(true)
        try {
          await couponsApi.remove(coupon.id)
          toast.success('Coupon deleted.')
          setConfirm(null)
          load()
        } catch (err) {
          toast.error(err.message)
        } finally {
          setBusy(false)
        }
      },
    })
  }

  const items = data?.items || []

  return (
    <>
      <PageHeader
        title="Coupons"
        subtitle={data ? `${data.total} coupon${data.total === 1 ? '' : 's'}` : ' '}
        breadcrumbs={[{ label: 'Sales' }, { label: 'Coupons' }]}
        actions={
          <Button variant="primary" icon={Plus} onClick={() => setEditing({ isNew: true })}>
            Add coupon
          </Button>
        }
      />

      <Card className="mb-4" bodyClass="p-3">
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Search coupons…" />
      </Card>

      <Card bodyClass="p-0">
        {loading ? (
          <SkeletonTable rows={6} cols={5} />
        ) : items.length === 0 ? (
          <EmptyState
            title="No coupons"
            icon={Ticket}
            action={
              <Button variant="primary" icon={Plus} onClick={() => setEditing({ isNew: true })}>
                Create your first coupon
              </Button>
            }
          >
            Coupons created here work everywhere WooCommerce accepts them — cart, checkout and the
            CartFlows funnel.
          </EmptyState>
        ) : (
          <>
            <Table>
              <THead>
                <TR>
                  <TH>Code</TH>
                  <TH>Type</TH>
                  <TH align="end" className="w-28">
                    Amount
                  </TH>
                  <TH align="center" className="w-24">
                    Used
                  </TH>
                  <TH className="w-32">Expires</TH>
                  <TH align="end" className="w-28">
                    Actions
                  </TH>
                </TR>
              </THead>
              <TBody>
                {items.map((c) => (
                  <TR key={c.id}>
                    <TD>
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="font-mono font-medium uppercase text-fg">{c.code}</span>
                        {c.free_shipping && <Badge tone="gold">Free shipping</Badge>}
                        {c.expired && <Badge tone="danger">Expired</Badge>}
                      </span>
                      {c.description && (
                        <span className="mt-0.5 block text-xs text-faint">{c.description}</span>
                      )}
                    </TD>
                    <TD className="text-muted">{TYPE_LABELS[c.discount_type] || c.discount_type}</TD>
                    <TD align="end" className="yz-num text-fg">
                      {c.amount_html}
                    </TD>
                    <TD align="center" className="yz-num text-muted">
                      {c.usage_count}
                      {c.usage_limit ? <span className="text-faint"> / {c.usage_limit}</span> : null}
                    </TD>
                    <TD className="yz-num">
                      {c.expires ? (
                        <span className={c.expired ? 'text-danger' : 'text-muted'}>{c.expires}</span>
                      ) : (
                        <span className="text-faint">Never</span>
                      )}
                    </TD>
                    <TD align="end" className="whitespace-nowrap">
                      <span className="inline-flex items-center justify-end gap-1">
                        <IconButton
                          icon={Pencil}
                          label={`Edit coupon ${c.code}`}
                          size="sm"
                          onClick={() => setEditing({ id: c.id })}
                        />
                        <IconButton
                          icon={Trash2}
                          label={`Delete coupon ${c.code}`}
                          size="sm"
                          onClick={() => remove(c)}
                        />
                      </span>
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>

            <Pagination
              page={data.page}
              pages={data.total_pages}
              total={data.total}
              perPage={20}
              onPage={setPage}
              label="coupons"
            />
          </>
        )}
      </Card>


      <ConfirmDialog
        open={Boolean(confirm)}
        title={confirm?.title}
        confirmLabel="Delete coupon"
        busy={busy}
        onCancel={() => setConfirm(null)}
        onConfirm={() => confirm?.onConfirm?.()}
      >
        {confirm?.body}
      </ConfirmDialog>

      {editing && (
        <CouponEditor
          couponId={editing.id}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            load()
          }}
        />
      )}
    </>
  )
}

const BLANK = {
  code: '',
  description: '',
  discount_type: 'percent',
  amount: '',
  date_expires: '',
  free_shipping: false,
  minimum_amount: '',
  maximum_amount: '',
  individual_use: false,
  exclude_sale_items: false,
  usage_limit: '',
  usage_limit_per_user: '',
  limit_usage_to_x_items: '',
  product_categories: [],
  excluded_product_categories: [],
  email_restrictions: [],
}

function CouponEditor({ couponId, onClose, onSaved }) {
  const toast = useToast()
  const { meta } = useMeta()
  const isNew = !couponId
  const [form, setForm] = useState(isNew ? BLANK : null)
  const [tab, setTab] = useState('general')
  const [saving, setSaving] = useState(false)
  const [emails, setEmails] = useState('')

  useEffect(() => {
    if (isNew) return
    couponsApi
      .get(couponId)
      .then((d) => {
        setForm({ ...BLANK, ...d })
        setEmails((d.email_restrictions || []).join(', '))
      })
      .catch((err) => toast.error(err.message))
  }, [couponId, isNew, toast])

  const set = (key, value) => setForm((c) => ({ ...c, [key]: value }))

  const submit = async () => {
    if (!form.code.trim()) {
      toast.error('A coupon code is required.')
      return
    }
    setSaving(true)
    const payload = {
      ...form,
      email_restrictions: emails
        .split(',')
        .map((e) => e.trim())
        .filter(Boolean),
    }
    try {
      if (isNew) {
        await couponsApi.create(payload)
        toast.success('Coupon created.')
      } else {
        await couponsApi.update(couponId, payload)
        toast.success('Coupon updated.')
      }
      onSaved()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  if (!form) return null

  const symbol = meta?.currency?.symbol ?? '$'
  const isPercent = form.discount_type === 'percent'

  const TABS = [
    { key: 'general', label: 'General' },
    { key: 'restrictions', label: 'Usage restrictions' },
    { key: 'limits', label: 'Usage limits' },
  ]

  return (
    <Modal
      open
      onClose={onClose}
      title={isNew ? 'New coupon' : 'Edit coupon'}
      description={isNew ? undefined : form.code}
      size="lg"
    >
      <Tabs items={TABS} value={tab} onChange={setTab} className="mb-4" />

      {tab === 'general' && (
        <div className="space-y-4">
          <div className="grid sm:grid-cols-2 gap-4">
            <Field label="Coupon code" help="Customers type this at checkout. Case-insensitive.">
              <Input
                value={form.code}
                onChange={(e) => set('code', e.target.value.toUpperCase())}
                className="font-mono uppercase"
                autoFocus
              />
            </Field>
            <Field label="Discount type">
              <Select value={form.discount_type} onChange={(e) => set('discount_type', e.target.value)}>
                {Object.entries(TYPE_LABELS).map(([k, v]) => (
                  <option key={k} value={k}>
                    {v}
                  </option>
                ))}
              </Select>
            </Field>
          </div>

          <div className="grid sm:grid-cols-2 gap-4">
            <Field label={isPercent ? 'Amount (%)' : `Amount (${symbol})`} help={isPercent ? 'Between 0 and 100.' : undefined}>
              <Input
                type="number"
                step="0.01"
                min="0"
                max={isPercent ? 100 : undefined}
                value={form.amount}
                onChange={(e) => set('amount', e.target.value)}
              />
            </Field>
            <Field label="Expires on" help="Leave blank for no expiry.">
              <Input type="date" value={form.date_expires} onChange={(e) => set('date_expires', e.target.value)} />
            </Field>
          </div>

          <Field label="Description" help="Internal note — customers never see this.">
            <Textarea rows={2} value={form.description} onChange={(e) => set('description', e.target.value)} />
          </Field>

          <Checkbox
            label="Grant free shipping"
            checked={form.free_shipping}
            onChange={(v) => set('free_shipping', v)}
            help="Requires a free-shipping method that is set to require a coupon."
          />
        </div>
      )}

      {tab === 'restrictions' && (
        <div className="space-y-4">
          <div className="grid sm:grid-cols-2 gap-4">
            <Field label={`Minimum spend (${symbol})`}>
              <Input
                type="number"
                step="0.01"
                min="0"
                value={form.minimum_amount}
                onChange={(e) => set('minimum_amount', e.target.value)}
              />
            </Field>
            <Field label={`Maximum spend (${symbol})`}>
              <Input
                type="number"
                step="0.01"
                min="0"
                value={form.maximum_amount}
                onChange={(e) => set('maximum_amount', e.target.value)}
              />
            </Field>
          </div>

          <Checkbox
            label="Individual use only"
            checked={form.individual_use}
            onChange={(v) => set('individual_use', v)}
            help="Cannot be combined with other coupons."
          />
          <Checkbox
            label="Exclude sale items"
            checked={form.exclude_sale_items}
            onChange={(v) => set('exclude_sale_items', v)}
            help="Products already on sale will not be discounted."
          />

          <div className="grid sm:grid-cols-2 gap-4">
            <CategoryPicker
              label="Only these categories"
              categories={meta?.categories || []}
              selected={form.product_categories}
              onChange={(v) => set('product_categories', v)}
            />
            <CategoryPicker
              label="Exclude these categories"
              categories={meta?.categories || []}
              selected={form.excluded_product_categories}
              onChange={(v) => set('excluded_product_categories', v)}
            />
          </div>

          <Field
            label="Allowed emails"
            help="Comma separated. Wildcards allowed, e.g. *@example.com. Blank = anyone."
          >
            <Input value={emails} onChange={(e) => setEmails(e.target.value)} />
          </Field>
        </div>
      )}

      {tab === 'limits' && (
        <div className="space-y-4">
          <div className="grid sm:grid-cols-3 gap-4">
            <Field label="Usage limit per coupon" help="Blank = unlimited.">
              <Input
                type="number"
                min="0"
                value={form.usage_limit ?? ''}
                onChange={(e) => set('usage_limit', e.target.value)}
              />
            </Field>
            <Field label="Usage limit per user" help="Blank = unlimited.">
              <Input
                type="number"
                min="0"
                value={form.usage_limit_per_user ?? ''}
                onChange={(e) => set('usage_limit_per_user', e.target.value)}
              />
            </Field>
            <Field label="Limit to X items" help="For per-product discounts.">
              <Input
                type="number"
                min="0"
                value={form.limit_usage_to_x_items ?? ''}
                onChange={(e) => set('limit_usage_to_x_items', e.target.value)}
              />
            </Field>
          </div>

          {!isNew && (
            <p className="text-sm text-muted">
              Used <strong className="text-fg">{form.usage_count}</strong > time
              {form.usage_count === 1 ? '' : 's'} so far.
            </p>
          )}
        </div>
      )}

      <div className="flex justify-end gap-2 pt-4 mt-4 border-t border-edge">
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={saving}>
          {saving ? 'Saving…' : isNew ? 'Create coupon' : 'Save'}
        </Button>
      </div>
    </Modal>
  )
}

function CategoryPicker({ label, categories, selected, onChange }) {
  const toggle = (id) =>
    onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id])

  return (
    <div>
      <span className="yz-label">{label}</span>
      <div className="border border-edge max-h-40 overflow-y-auto p-2 space-y-1">
        {categories.length === 0 && <span className="text-xs text-muted">None available.</span>}
        {categories.map((t) => (
          <label key={t.id} className="flex items-center gap-2 cursor-pointer text-sm">
            <input
              type="checkbox"
              checked={selected.includes(t.id)}
              onChange={() => toggle(t.id)}
              className="size-4 accent-[var(--yz-agate)]"
            />
            <span className={selected.includes(t.id) ? 'text-fg' : 'text-muted'}>
              {t.parent ? '— ' : ''}
              {t.name}
            </span>
          </label>
        ))}
      </div>
    </div>
  )
}
