import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { productsApi } from '../api/endpoints.js'
import { useMeta, formatPrice } from '../context/MetaContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { useAuth } from '../context/AuthContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import { Can } from '../components/Protected.jsx'
import ProductStatusTabs from '../components/product/ProductStatusTabs.jsx'
import {
  Badge,
  Button,
  Card,
  ConfirmDialog,
  Dropdown,
  EmptyState,
  Field,
  Input,
  Modal,
  Pagination,
  SearchInput,
  Select,
  SkeletonTable,
  Table,
  TBody,
  TD,
  TH,
  THead,
  TR,
} from '../components/ui/index.js'
import {
  Copy,
  ExternalLink,
  Package,
  Pencil,
  Plus,
  RotateCcw,
  SlidersHorizontal,
  Trash2,
} from '../components/ui/icons.js'

const SORTABLE = [
  { key: 'title', label: 'Product' },
  { key: 'sku', label: 'SKU' },
  { key: 'price', label: 'Price' },
  { key: 'date', label: 'Date' },
]

const EMPTY_FILTERS = { search: '', category: '', stock_status: '', type: '' }

/** Past-tense wording per bulk action, so the toast says what actually happened. */
const BULK_PAST = {
  trash: 'moved to the trash',
  restore: 'restored',
  delete: 'deleted permanently',
  publish: 'published',
  draft: 'set to draft',
  set_instock: 'marked in stock',
  set_outofstock: 'marked out of stock',
  feature: 'featured',
  unfeature: 'unfeatured',
}

export default function Products() {
  const { meta } = useMeta()
  const toast = useToast()
  const { can } = useAuth()
  const navigate = useNavigate()

  /*
   * The whole list state lives in the query string, as it does in wp-admin. That is not
   * decoration: it makes the browser Back button step through filter changes, makes a
   * filtered view a shareable link, and survives a reload — none of which component state does.
   */
  const [params, setParams] = useSearchParams()
  const filters = useMemo(
    () => ({
      search: params.get('search') || '',
      category: params.get('category') || '',
      stock_status: params.get('stock_status') || '',
      type: params.get('type') || '',
    }),
    [params],
  )
  // The status view is a tab, not a filter — it is never cleared by "Clear filters",
  // and the trash view changes which actions a row even offers.
  const view = params.get('status') || ''
  const sort = useMemo(
    () => ({
      orderby: params.get('orderby') || 'date',
      order: params.get('order') === 'asc' ? 'asc' : 'desc',
    }),
    [params],
  )
  const page = Math.max(1, Number(params.get('page')) || 1)

  const [searchInput, setSearchInput] = useState(params.get('search') || '')
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState([])
  const [bulkAction, setBulkAction] = useState('')
  const [confirm, setConfirm] = useState(null)
  const [busy, setBusy] = useState(false)

  const inTrash = view === 'trash'

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await productsApi.list({ ...filters, status: view, ...sort, page, per_page: 20 })
      setData(result)
      setSelected([])
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [filters, view, sort, page, toast])

  useEffect(() => {
    load()
  }, [load])

  /**
   * Merge changes into the query string. Anything falsy is removed rather than written
   * as an empty value, so a cleared filter leaves a clean URL. Every change but paging
   * resets to page 1 — landing on page 7 of a 2-page result is the classic filter bug.
   */
  const patchParams = useCallback(
    (changes, { keepPage = false } = {}) => {
      setParams(
        (current) => {
          const next = new URLSearchParams(current)
          for (const [key, value] of Object.entries(changes)) {
            if (value === '' || value === null || value === undefined) next.delete(key)
            else next.set(key, String(value))
          }
          if (!keepPage && !('page' in changes)) next.delete('page')
          return next
        },
        { replace: true },
      )
    },
    [setParams],
  )

  // Debounce the free-text search so we aren't querying on every keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      if (searchInput !== (params.get('search') || '')) patchParams({ search: searchInput })
    }, 350)
    return () => clearTimeout(timer)
  }, [searchInput, params, patchParams])

  const setFilter = (key, value) => patchParams({ [key]: value })
  const setPage = (next) => patchParams({ page: next > 1 ? next : '' }, { keepPage: true })

  const toggleSort = (key) =>
    patchParams(
      sort.orderby === key
        ? { orderby: key, order: sort.order === 'asc' ? 'desc' : 'asc' }
        : { orderby: key, order: 'asc' },
    )

  const items = data?.items || []
  const allSelected = items.length > 0 && selected.length === items.length

  const toggleAll = () => setSelected(allSelected ? [] : items.map((item) => item.id))
  const toggleOne = (id) =>
    setSelected((current) =>
      current.includes(id) ? current.filter((entry) => entry !== id) : [...current, id],
    )

  const applyBulk = async () => {
    setBusy(true)
    const ids = selected
    const action = bulkAction
    try {
      const result = await productsApi.bulk(action, ids)
      // Trashing is the one bulk action that is both destructive and cheaply reversible,
      // so it gets the same Undo affordance wp-admin offers after a bulk trash.
      toast.success(
        `${result.count} product${result.count === 1 ? '' : 's'} ${BULK_PAST[action] || 'updated'}.`,
        action === 'trash'
          ? { action: { label: 'Undo', onClick: () => undoTrash(result.ids || ids) } }
          : undefined,
      )
      setBulkAction('')
      setConfirm(null)
      load()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  /** Put back what a trash action just removed — used by every "Undo" we offer. */
  const undoTrash = async (ids) => {
    try {
      const result = await productsApi.bulk('restore', ids)
      toast.success(`${result.count} product${result.count === 1 ? '' : 's'} restored.`)
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const runBulk = () => {
    if (!bulkAction || !selected.length) return
    if (bulkAction !== 'delete') {
      applyBulk()
      return
    }
    // A styled dialog rather than window.confirm: it can be themed, translated
    // and dismissed with Escape, and it does not freeze the whole tab. Only the
    // irreversible action stops the user — trashing is undoable, so it does not.
    setConfirm({
      title: 'Delete permanently?',
      body: `${selected.length} product(s) will be deleted permanently. This cannot be undone.`,
      confirmLabel: 'Delete permanently',
      onConfirm: applyBulk,
    })
  }

  const removeOne = (product) => {
    // WooCommerce warns before trashing a product that has sold, because it is
    // referenced by existing orders. Same rule, same reason.
    const sold = Number(product.total_sales) > 0
    setConfirm({
      title: 'Move to trash?',
      body: sold
        ? `“${product.name}” has produced sales and may be linked to existing orders. Move it to the trash anyway?`
        : `“${product.name}” will be moved to the trash. You can restore it from the Trash tab.`,
      confirmLabel: 'Move to trash',
      onConfirm: async () => {
        setBusy(true)
        try {
          await productsApi.remove(product.id)
          toast.success('Product moved to trash.', {
            action: { label: 'Undo', onClick: () => undoTrash([product.id]) },
          })
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

  const restoreOne = async (product) => {
    try {
      await productsApi.restore(product.id)
      toast.success(`“${product.name}” restored.`)
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const deleteOne = (product) => {
    setConfirm({
      title: 'Delete permanently?',
      body: `“${product.name}” will be deleted permanently. This cannot be undone.`,
      confirmLabel: 'Delete permanently',
      onConfirm: async () => {
        setBusy(true)
        try {
          await productsApi.remove(product.id, true)
          toast.success('Product deleted permanently.')
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

  const emptyTrash = () => {
    setConfirm({
      title: 'Empty the trash?',
      body: `All ${data?.counts?.trash ?? 0} product(s) in the trash will be deleted permanently. This cannot be undone.`,
      confirmLabel: 'Empty trash',
      onConfirm: async () => {
        setBusy(true)
        try {
          const result = await productsApi.emptyTrash()
          toast.success(`${result.count} product(s) deleted permanently.`)
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

  /**
   * Copy a product. WooCommerce's own duplicator runs server-side, so variations and
   * attributes come along; the copy is a draft with SKU and certificate data cleared.
   */
  const duplicate = async (product) => {
    try {
      const copy = await productsApi.duplicate(product.id)
      toast.success(`Duplicated as a draft — SKU and serial cleared.`)
      navigate(`/products/${copy.id}`)
    } catch (err) {
      toast.error(err.message)
    }
  }

  // Quick edit: patch a few fields inline without opening the full editor.
  const [quick, setQuick] = useState(null)
  const saveQuick = async () => {
    try {
      const result = await productsApi.quickEdit([{ id: quick.id, ...quick.changes }])
      if (result.skipped.length) {
        toast.error(`Not saved: ${result.skipped[0].reason.replace(/_/g, ' ')}.`)
      } else {
        toast.success('Product updated.')
        setQuick(null)
        load()
      }
    } catch (err) {
      toast.error(err.message)
    }
  }

  const openQuick = (product) =>
    setQuick({
      id: product.id,
      name: product.name,
      changes: {
        name: product.name,
        sku: product.sku || '',
        regular_price: product.regular_price || '',
        sale_price: product.sale_price || '',
        status: product.status,
        stock_status: product.stock_status,
      },
    })

  const hasFilters = Object.values(filters).some(Boolean)
  const clearFilters = () => {
    setSearchInput('')
    patchParams(EMPTY_FILTERS)
  }

  return (
    <>
      <PageHeader
        title="Products"
        subtitle={data ? `${data.total} product${data.total === 1 ? '' : 's'}` : ' '}
        breadcrumbs={[{ label: 'Catalog' }, { label: 'Products' }]}
        actions={
          <Can perm="products.create">
            <Button variant="primary" icon={Plus} onClick={() => navigate('/products/new')}>
              Add product
            </Button>
          </Can>
        }
      />

      <ProductStatusTabs
        counts={data?.counts}
        value={view}
        onChange={(next) => {
          setBulkAction('')
          patchParams({ status: next })
        }}
      />

      <Card className="mb-4" bodyClass="p-3">
        <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
          <SearchInput
            value={searchInput}
            onChange={setSearchInput}
            placeholder="Search products…"
            className="col-span-2 min-w-52 flex-1"
          />

          <Select value={filters.category} onChange={(e) => setFilter('category', e.target.value)} className="w-full sm:w-auto">
            <option value="">All categories</option>
            {(meta?.categories || []).map((term) => (
              <option key={term.id} value={term.slug}>
                {term.parent ? '— ' : ''}
                {term.name} ({term.count})
              </option>
            ))}
          </Select>

          <Select value={filters.stock_status} onChange={(e) => setFilter('stock_status', e.target.value)} className="w-full sm:w-auto">
            <option value="">Any stock</option>
            {Object.entries(meta?.stock_statuses || {}).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </Select>

          <Select value={filters.type} onChange={(e) => setFilter('type', e.target.value)} className="w-full sm:w-auto">
            <option value="">Any type</option>
            {Object.entries(meta?.product_types || {}).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </Select>

          {hasFilters && (
            <Button size="sm" onClick={clearFilters}>
              Clear
            </Button>
          )}

          {inTrash && (data?.counts?.trash ?? 0) > 0 && can('products.delete') && (
            <Button size="sm" variant="danger" icon={Trash2} onClick={emptyTrash} className="ms-auto">
              Empty trash
            </Button>
          )}
        </div>
      </Card>

      {selected.length > 0 && (
        <Card className="mb-4 border-agate/40" bodyClass="p-3">
          <div className="flex flex-wrap items-center gap-2">
            <span className="yz-num text-sm font-medium text-fg">{selected.length}</span>
            <span className="text-sm text-muted">selected</span>
            <Select value={bulkAction} onChange={(e) => setBulkAction(e.target.value)} className="w-full sm:w-auto">
              <option value="">Bulk actions…</option>
              {inTrash ? (
                <>
                  {can('products.restore') && <option value="restore">Restore</option>}
                  {can('products.delete') && <option value="delete">Delete permanently</option>}
                </>
              ) : (
                <>
                  <option value="publish">Publish</option>
                  <option value="draft">Set to draft</option>
                  <option value="feature">Mark as featured</option>
                  <option value="unfeature">Remove featured</option>
                  <option value="set_instock">Mark in stock</option>
                  <option value="set_outofstock">Mark out of stock</option>
                  {can('products.delete') && <option value="trash">Move to trash</option>}
                </>
              )}
            </Select>
            <Button size="sm" variant="primary" onClick={runBulk} disabled={!bulkAction}>
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
            title={inTrash ? 'The trash is empty' : 'No products found'}
            icon={inTrash ? Trash2 : Package}
            action={
              inTrash ? (
                <Button onClick={() => patchParams({ status: '' })}>Back to all products</Button>
              ) : hasFilters ? (
                <Button onClick={clearFilters}>Clear filters</Button>
              ) : (
                <Button variant="primary" icon={Plus} onClick={() => navigate('/products/new')}>
                  Add your first product
                </Button>
              )
            }
          >
            {inTrash
              ? 'Nothing has been moved to the trash.'
              : hasFilters
                ? 'No products match the current filters.'
                : 'Products you create here appear in WooCommerce and on the store immediately.'}
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
                      aria-label="Select all products"
                      className="size-4 rounded-xs accent-[var(--yz-agate)]"
                    />
                  </TH>
                  <TH className="w-12">
                    <span className="sr-only">Image</span>
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
                  <TH>Stock</TH>
                  <TH>Categories</TH>
                  <TH>Status</TH>
                  <TH align="end">Actions</TH>
                </TR>
              </THead>
              <TBody>
                {items.map((product) => (
                  <TR key={product.id} selected={selected.includes(product.id)}>
                    <TD>
                      <input
                        type="checkbox"
                        checked={selected.includes(product.id)}
                        onChange={() => toggleOne(product.id)}
                        aria-label={`Select ${product.name}`}
                        className="size-4 rounded-xs accent-[var(--yz-agate)]"
                      />
                    </TD>
                    <TD>
                      {product.thumb ? (
                        <img
                          src={product.thumb}
                          alt=""
                          className="size-10 rounded-sm border border-edge object-cover"
                        />
                      ) : (
                        <div className="size-10 rounded-sm border border-edge bg-sunken" />
                      )}
                    </TD>
                    <TD primary>
                      <Link
                        to={`/products/${product.id}`}
                        className="font-medium text-fg transition-colors hover:text-agate-fg"
                      >
                        {product.name || '(no title)'}
                      </Link>
                      <div className="mt-1 flex flex-wrap gap-1.5">
                        {product.type !== 'simple' && <Badge>{product.type}</Badge>}
                        {product.edit_serial && <Badge tone="gold">Certified</Badge>}
                        {product.on_sale && <Badge tone="warn">Sale</Badge>}
                      </div>
                    </TD>
                    <TD className="yz-num font-mono text-xs text-muted" label="SKU">{product.sku || '—'}</TD>
                    <TD className="yz-num whitespace-nowrap" label="Price">
                      {product.on_sale && product.regular_price ? (
                        <>
                          <span className="me-1 text-faint line-through">
                            {formatPrice(meta, product.regular_price)}
                          </span>
                          <span className="text-fg">{formatPrice(meta, product.price)}</span>
                        </>
                      ) : (
                        <span className="text-fg">{formatPrice(meta, product.price)}</span>
                      )}
                    </TD>
                    <TD className="yz-num whitespace-nowrap text-muted" label="Date">{product.date}</TD>
                    <TD className="whitespace-nowrap" label="Stock">
                      <StockCell product={product} />
                    </TD>
                    <TD className="text-muted sm:max-w-40 sm:truncate" title={product.categories} label="Categories">
                      {product.categories || '—'}
                    </TD>
                    <TD label="Status">
                      <Badge tone={product.status === 'publish' ? 'ok' : 'muted'}>{product.status}</Badge>
                    </TD>
                    {/* Two primary actions stay visible; the rest move into a menu.
                        Five equal-weight text links per row read as noise and made
                        every row 40% wider than its content needed. */}
                    <TD align="end" className="whitespace-nowrap">
                      {inTrash ? (
                        /* A trashed row offers only the two things you can do to it — the
                           same pair wp-admin swaps in on its Trash view. */
                        <span className="inline-flex items-center gap-1">
                          <Can perm="products.restore">
                            <Button size="sm" variant="quiet" icon={RotateCcw} onClick={() => restoreOne(product)}>
                              Restore
                            </Button>
                          </Can>
                          <Can perm="products.delete">
                            <Button size="sm" variant="quiet" icon={Trash2} onClick={() => deleteOne(product)}>
                              Delete permanently
                            </Button>
                          </Can>
                        </span>
                      ) : (
                      <span className="inline-flex items-center gap-1">
                        <Button
                          size="sm"
                          variant="quiet"
                          icon={Pencil}
                          onClick={() => navigate(`/products/${product.id}`)}
                        >
                          Edit
                        </Button>
                        <Dropdown
                          label={`More actions for ${product.name}`}
                          trigger={({ toggle, open }) => (
                            <button
                              type="button"
                              onClick={toggle}
                              aria-haspopup="menu"
                              aria-expanded={open}
                              aria-label={`More actions for ${product.name}`}
                              className="yz-btn yz-btn-quiet yz-btn-sm yz-btn-icon"
                            >
                              ⋯
                            </button>
                          )}
                          items={[
                            {
                              key: 'quick',
                              label: 'Quick edit',
                              icon: SlidersHorizontal,
                              onSelect: () => openQuick(product),
                            },
                            { key: 'copy', label: 'Duplicate', icon: Copy, onSelect: () => duplicate(product) },
                            {
                              key: 'view',
                              label: 'View on store',
                              icon: ExternalLink,
                              onSelect: () => window.open(product.permalink, '_blank', 'noreferrer'),
                            },
                            ...(can('products.delete')
                              ? [
                                  {
                                    key: 'trash',
                                    label: 'Move to trash',
                                    icon: Trash2,
                                    tone: 'danger',
                                    separatorBefore: true,
                                    onSelect: () => removeOne(product),
                                  },
                                ]
                              : []),
                          ]}
                        />
                      </span>
                      )}
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
              label="products"
            />
          </>
        )}
      </Card>

      {quick && (
        <Modal
          open
          onClose={() => setQuick(null)}
          title="Quick edit"
          description={quick.name}
          footer={
            <>
              <Button variant="ghost" onClick={() => setQuick(null)}>
                Cancel
              </Button>
              <Button variant="primary" onClick={saveQuick}>
                Save
              </Button>
            </>
          }
        >
          <div className="space-y-3">
            <Field label="Name">
              <Input
                value={quick.changes.name}
                onChange={(e) => setQuick({ ...quick, changes: { ...quick.changes, name: e.target.value } })}
                data-autofocus
              />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="SKU">
                <Input
                  value={quick.changes.sku}
                  onChange={(e) => setQuick({ ...quick, changes: { ...quick.changes, sku: e.target.value } })}
                />
              </Field>
              <Field label="Status">
                <Select
                  value={quick.changes.status}
                  onChange={(e) => setQuick({ ...quick, changes: { ...quick.changes, status: e.target.value } })}
                >
                  {Object.entries(meta?.statuses || {}).map(([k, v]) => (
                    <option key={k} value={k}>
                      {v}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Regular price">
                <Input
                  type="number"
                  step="0.01"
                  inputMode="decimal"
                  value={quick.changes.regular_price}
                  onChange={(e) =>
                    setQuick({ ...quick, changes: { ...quick.changes, regular_price: e.target.value } })
                  }
                />
              </Field>
              <Field label="Sale price">
                <Input
                  type="number"
                  step="0.01"
                  inputMode="decimal"
                  value={quick.changes.sale_price}
                  onChange={(e) => setQuick({ ...quick, changes: { ...quick.changes, sale_price: e.target.value } })}
                />
              </Field>
              <Field label="Stock status" className="sm:col-span-2">
                <Select
                  value={quick.changes.stock_status}
                  onChange={(e) =>
                    setQuick({ ...quick, changes: { ...quick.changes, stock_status: e.target.value } })
                  }
                >
                  <option value="instock">In stock</option>
                  <option value="outofstock">Out of stock</option>
                  <option value="onbackorder">On backorder</option>
                </Select>
              </Field>
            </div>
          </div>
        </Modal>
      )}

      <ConfirmDialog
        open={Boolean(confirm)}
        title={confirm?.title}
        confirmLabel={confirm?.confirmLabel}
        busy={busy}
        onCancel={() => setConfirm(null)}
        onConfirm={() => confirm?.onConfirm?.()}
      >
        {confirm?.body}
      </ConfirmDialog>
    </>
  )
}

function StockCell({ product }) {
  if (product.stock_status === 'outofstock') return <Badge tone="danger">Out of stock</Badge>
  if (product.stock_status === 'onbackorder') return <Badge tone="warn">On backorder</Badge>
  return (
    <Badge tone="ok">
      In stock
      {product.manage_stock && product.stock_qty !== null && product.stock_qty !== undefined
        ? ` · ${product.stock_qty}`
        : ''}
    </Badge>
  )
}
