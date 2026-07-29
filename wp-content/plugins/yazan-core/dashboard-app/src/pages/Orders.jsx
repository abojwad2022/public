import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ordersApi } from '../api/endpoints.js'
import { useMeta } from '../context/MetaContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import {
  Badge,
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  Field,
  IconButton,
  Input,
  Modal,
  Pagination,
  SearchInput,
  Select,
  Skeleton,
  SkeletonTable,
  Table,
  TBody,
  TD,
  TH,
  THead,
  TR,
} from '../components/ui/index.js'
import { Eye, Plus, Printer, ShoppingCart } from '../components/ui/icons.js'

/**
 * Print an invoice from a hidden, off-screen iframe — no new tab, so pop-up blockers
 * never interfere. Under Chrome's `--kiosk-printing` this is silent (straight to the
 * default printer); otherwise the normal print dialog appears.
 *
 * The frame is deliberately NOT removed on `afterprint` — in Chrome that fires early and
 * tearing it down closes the preview.
 */
function printInvoice(url) {
  if (!url) return
  const previous = document.getElementById('yz-print-frame')
  if (previous) previous.remove()

  const frame = document.createElement('iframe')
  frame.id = 'yz-print-frame'
  frame.setAttribute('aria-hidden', 'true')
  Object.assign(frame.style, {
    position: 'fixed',
    top: '0',
    left: '-10000px',
    width: '820px',
    height: '1123px',
    border: '0',
  })
  frame.onload = () => {
    const win = frame.contentWindow
    setTimeout(() => {
      try {
        win.focus()
        win.print()
      } catch {
        /* ignore — nothing else we can do from here */
      }
      setTimeout(() => frame.remove(), 15000)
    }, 300)
  }
  frame.src = url
  document.body.appendChild(frame)
}

/**
 * Maps a WooCommerce order status to a badge tone.
 *
 * `processing` is informational, not a warning — it previously wore the gold
 * accent, which read as "needs attention" beside the genuinely-warning on-hold.
 */
export function statusTone(status) {
  switch (status) {
    case 'completed':
      return 'ok'
    case 'processing':
      return 'info'
    case 'on-hold':
    case 'pending':
      return 'warn'
    case 'cancelled':
    case 'failed':
    case 'refunded':
      return 'danger'
    default:
      return 'muted'
  }
}

const SORTABLE = [
  { key: 'id', label: 'Order' },
  { key: 'date', label: 'Date' },
  { key: 'total', label: 'Total' },
]

const EMPTY = { search: '', status: '', date_from: '', date_to: '' }

export default function Orders() {
  const { meta } = useMeta()
  const toast = useToast()
  const navigate = useNavigate()

  const [filters, setFilters] = useState(EMPTY)
  const [searchInput, setSearchInput] = useState('')
  const [sort, setSort] = useState({ orderby: 'date', order: 'desc' })
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState([])
  const [bulkStatus, setBulkStatus] = useState('')
  const [confirm, setConfirm] = useState(null)
  const [busy, setBusy] = useState(false)

  // Quick-view (eye) state.
  const [preview, setPreview] = useState(null) // { id, number } while loading
  const [previewData, setPreviewData] = useState(null)

  const openPreview = async (order) => {
    setPreview({ id: order.id, number: order.number })
    setPreviewData(null)
    try {
      setPreviewData(await ordersApi.get(order.id))
    } catch (err) {
      toast.error(err.message)
      setPreview(null)
    }
  }

  const closePreview = () => {
    setPreview(null)
    setPreviewData(null)
  }

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await ordersApi.list({ ...filters, ...sort, page, per_page: 20 })
      setData(result)
      setSelected([])
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [filters, sort, page, toast])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    const timer = setTimeout(() => {
      setFilters((current) => (current.search === searchInput ? current : { ...current, search: searchInput }))
      setPage(1)
    }, 350)
    return () => clearTimeout(timer)
  }, [searchInput])

  const setFilter = (key, value) => {
    setFilters((current) => ({ ...current, [key]: value }))
    setPage(1)
  }

  const toggleSort = (key) => {
    setSort((current) =>
      current.orderby === key
        ? { orderby: key, order: current.order === 'asc' ? 'desc' : 'asc' }
        : { orderby: key, order: 'desc' },
    )
    setPage(1)
  }

  const items = data?.items || []
  const allSelected = items.length > 0 && selected.length === items.length
  const toggleAll = () => setSelected(allSelected ? [] : items.map((item) => item.id))
  const toggleOne = (id) =>
    setSelected((current) => (current.includes(id) ? current.filter((e) => e !== id) : [...current, id]))

  const runBulk = () => {
    if (!bulkStatus || !selected.length) return
    const label = meta?.order_statuses?.[bulkStatus] || bulkStatus
    setConfirm({
      title: `Change ${selected.length} order${selected.length === 1 ? '' : 's'} to “${label}”?`,
      // Kept explicit: a status change can trigger a customer email, and that is
      // not reversible once sent.
      body: 'Customer emails may be sent as a result of this change.',
      confirmLabel: 'Change status',
      tone: 'primary',
      onConfirm: async () => {
        setBusy(true)
        try {
          const result = await ordersApi.bulkStatus(bulkStatus, selected)
          toast.success(`${result.count} order(s) updated.`)
          setBulkStatus('')
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

  const hasFilters = Object.values(filters).some(Boolean)
  const clearFilters = () => {
    setFilters(EMPTY)
    setSearchInput('')
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Orders"
        subtitle={data ? `${data.total} order${data.total === 1 ? '' : 's'}` : ' '}
        breadcrumbs={[{ label: 'Sales' }, { label: 'Orders' }]}
        actions={
          <Button variant="primary" icon={Plus} onClick={() => navigate('/orders/new')}>
            Add order
          </Button>
        }
      />

      <Card className="mb-4" bodyClass="p-3">
        <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
          <SearchInput
            value={searchInput}
            onChange={setSearchInput}
            placeholder="Search orders or enter an order number…"
            className="col-span-2 min-w-56 flex-1"
          />

          <Select value={filters.status} onChange={(e) => setFilter('status', e.target.value)} className="w-full sm:w-auto">
            <option value="">All statuses</option>
            {Object.entries(meta?.order_statuses || {}).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </Select>

          <label className="flex items-center gap-1.5 text-xs text-muted">
            From
            <Input
              type="date"
              value={filters.date_from}
              onChange={(e) => setFilter('date_from', e.target.value)}
              className="w-full sm:w-auto"
            />
          </label>
          <label className="flex items-center gap-1.5 text-xs text-muted">
            To
            <Input
              type="date"
              value={filters.date_to}
              onChange={(e) => setFilter('date_to', e.target.value)}
              className="w-full sm:w-auto"
            />
          </label>

          {hasFilters && (
            <Button size="sm" onClick={clearFilters}>
              Clear
            </Button>
          )}
        </div>
      </Card>

      {selected.length > 0 && (
        <Card className="mb-4" bodyClass="p-3">
          <div className="flex flex-wrap items-center gap-2">
            <span className="yz-num text-sm font-medium text-fg">{selected.length}</span>
            <span className="text-sm text-muted">selected</span>
            <Select value={bulkStatus} onChange={(e) => setBulkStatus(e.target.value)} className="w-full sm:w-auto">
              <option value="">Change status to…</option>
              {Object.entries(meta?.order_statuses || {}).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </Select>
            <Button size="sm" variant="primary" onClick={runBulk} disabled={!bulkStatus}>
              Apply
            </Button>
            <Button size="sm" variant="quiet" onClick={() => setSelected([])}>
              Clear selection
            </Button>
          </div>
        </Card>
      )}

      <Card bodyClass="p-0">
        {loading ? (
          <SkeletonTable rows={8} cols={6} />
        ) : items.length === 0 ? (
          <EmptyState
            title="No orders found"
            icon={ShoppingCart}
            action={hasFilters ? <Button onClick={clearFilters}>Clear filters</Button> : null}
          >
            {hasFilters ? 'No orders match the current filters.' : 'Orders will appear here as they come in.'}
          </EmptyState>
        ) : (
          <>
            <Table>
              <THead>
                <TR>
                  <TH className="w-8">
                    <input
                      type="checkbox"
                      checked={allSelected}
                      onChange={toggleAll}
                      aria-label="Select all orders"
                      className="size-4 rounded-xs accent-[var(--yz-agate)]"
                    />
                  </TH>
                  {SORTABLE.map((column) => (
                    <TH
                      key={column.key}
                      sortKey={column.key}
                      activeSort={sort.orderby}
                      direction={sort.order}
                      onSort={toggleSort}
                    >
                      {column.label}
                    </TH>
                  ))}
                  <TH>Customer</TH>
                  <TH align="end">Items</TH>
                  <TH>Payment</TH>
                  <TH>Status</TH>
                  <TH align="end">Actions</TH>
                </TR>
              </THead>
              <TBody>
                {items.map((order) => (
                  <TR key={order.id} selected={selected.includes(order.id)}>
                    <TD>
                      <input
                        type="checkbox"
                        checked={selected.includes(order.id)}
                        onChange={() => toggleOne(order.id)}
                        aria-label={`Select order ${order.number}`}
                        className="size-4 rounded-xs accent-[var(--yz-agate)]"
                      />
                    </TD>
                    <TD primary>
                      <Link
                        to={`/orders/${order.id}`}
                        className="yz-num font-medium text-fg transition-colors hover:text-agate-fg"
                      >
                        #{order.number}
                      </Link>
                    </TD>
                    <TD className="yz-num whitespace-nowrap text-muted">{order.date} label="Date"</TD>
                    <TD className="yz-num whitespace-nowrap" label="Total">
                      <span className={Number(order.refunded)> 0 ? 'text-muted line-through' : 'text-fg'}>
                        {order.total_html}
                      </span>
                      {Number(order.refunded)> 0 && (
                        <span className="block text-xs text-danger">refunded {order.refunded}</span>
                      )}
                    </TD>
                    <TD label="Customer">
                      <span className="text-fg">{order.customer}</span>
                      {order.email && <span className="block text-xs text-faint">{order.email}</span>}
                    </TD>
                    <TD align="end" className="yz-num text-muted" label="Items">
                      {order.items}
                    </TD>
                    <TD className="text-muted" label="Payment">{order.payment || '—'}</TD>
                    <TD label="Status">
                      <Badge tone={statusTone(order.status)}>{order.status_label}</Badge>
                    </TD>
                    <TD align="end">
                      <span className="inline-flex items-center justify-end gap-1">
                        <IconButton
                          icon={Eye}
                          label={`Preview order ${order.number}`}
                          size="sm"
                          onClick={() => openPreview(order)}
                        />
                        <IconButton
                          icon={Printer}
                          label={`Print invoice for order ${order.number}`}
                          size="sm"
                          onClick={() => printInvoice(order.print_url)}
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
              label="orders"
            />
          </>
        )}
      </Card>

      {/* Quick view */}
      <Modal
        open={!!preview}
        onClose={closePreview}
        title={preview ? `Order #${preview.number}` : ''}
        size="lg"
        footer={
          previewData ? (
            <>
              <Button
                icon={Printer}
                onClick={() => printInvoice(preview && items.find((o) => o.id === preview.id)?.print_url)}
              >
                Print invoice
              </Button>
              <Button
                variant="primary"
                onClick={() => {
                  const id = preview.id
                  closePreview()
                  navigate(`/orders/${id}`)
                }}
              >
                Open order
              </Button>
            </>
          ) : null
        }
      >
        {!previewData ? (
          <div className="space-y-3">
            <Skeleton w="40%" h={20} rounded="md" />
            <Skeleton w="100%" h={80} rounded="md" />
            <Skeleton w="100%" h={120} rounded="md" />
          </div>
        ) : (
          <div className="space-y-5 text-sm">
            <div className="flex flex-wrap items-center gap-3">
              <Badge tone={statusTone(previewData.status)}>{previewData.status_label}</Badge>
              <span className="text-muted">{previewData.date_created}</span>
              <span className="text-muted">{previewData.payment_method || '—'}</span>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <p className="yz-label">Billing</p>
                <p className="whitespace-pre-line text-fg">{previewData.billing_formatted || '—'}</p>
                {previewData.billing?.email && <p className="text-muted">{previewData.billing.email}</p>}
                {previewData.billing?.phone && <p className="text-muted">{previewData.billing.phone}</p>}
              </div>
              <div>
                <p className="yz-label">Shipping</p>
                <p className="whitespace-pre-line text-fg">{previewData.shipping_formatted || '—'}</p>
              </div>
            </div>

            <div>
              <p className="yz-label">Items</p>
              <Table>
                <TBody>
                  {(previewData.items || []).map((item) => (
                    <TR key={item.id}>
                      <TD>
                        <span className="text-fg">{item.name}</span>
                        {item.sku && <span className="block text-xs text-faint">SKU: {item.sku}</span>}
                        {item.serial && <span className="block text-xs text-gold">Serial: {item.serial}</span>}
                      </TD>
                      <TD align="end" className="yz-num whitespace-nowrap text-muted">
                        × {item.quantity}
                      </TD>
                      <TD align="end" className="yz-num whitespace-nowrap text-fg">
                        {previewData.currency_symbol}
                        {Number(item.total).toFixed(2)}
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>

            <div className="flex justify-end border-t border-divider pt-3">
              <span className="yz-num text-md font-medium text-fg">
                Total: {previewData.currency_symbol}
                {Number(previewData.totals?.total || 0).toFixed(2)}
              </span>
            </div>

            {previewData.customer_note && (
              <p className="rounded-lg border border-edge bg-sunken p-3 text-muted">
                <span className="text-fg">Customer note: </span>
                {previewData.customer_note}
              </p>
            )}
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={Boolean(confirm)}
        title={confirm?.title}
        confirmLabel={confirm?.confirmLabel}
        tone={confirm?.tone}
        busy={busy}
        onCancel={() => setConfirm(null)}
        onConfirm={() => confirm?.onConfirm?.()}
      >
        {confirm?.body}
      </ConfirmDialog>
    </>
  )
}
