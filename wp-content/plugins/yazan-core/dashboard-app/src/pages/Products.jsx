import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { productsApi } from '../api/endpoints.js'
import { useMeta } from '../context/MetaContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { useAuth } from '../context/AuthContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import { Can } from '../components/Protected.jsx'
import ProductStatusTabs from '../components/product/ProductStatusTabs.jsx'
import ProductFilters from '../components/product/ProductFilters.jsx'
import ProductsTable from '../components/product/ProductsTable.jsx'
import ScreenOptions, { useScreenOptions } from '../components/product/ScreenOptions.jsx'
import ProductQuickEdit, { quickEditStateFrom } from '../components/product/ProductQuickEdit.jsx'
import ProductBulkEditPanel from '../components/product/ProductBulkEditPanel.jsx'
import {
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  Pagination,
  Select,
  SkeletonTable,
} from '../components/ui/index.js'
import { Package, Plus, Trash2 } from '../components/ui/icons.js'

const EMPTY_FILTERS = { search: '', category: '', tag: '', stock_status: '', type: '', featured: '' }

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
    () =>
      Object.fromEntries(Object.keys(EMPTY_FILTERS).map((key) => [key, params.get(key) || ''])),
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
  const { columns: visibleColumns, toggleColumn, perPage, setPerPage } = useScreenOptions()
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
      const result = await productsApi.list({ ...filters, status: view, ...sort, page, per_page: perPage })
      setData(result)
      setSelected([])
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [filters, view, sort, page, perPage, toast])

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

  // Bulk Edit is not a one-shot action like the rest — it opens a panel of fields.
  const [bulkEditOpen, setBulkEditOpen] = useState(false)
  const applyBulkEdit = async (changes) => {
    setBusy(true)
    try {
      const result = await productsApi.bulkEdit(selected, changes)
      if (result.blocked?.length) {
        toast.info(
          `${result.count} product(s) updated, but some fields were not changed: ${result.blocked.join(', ')}.`,
        )
      } else {
        toast.success(`${result.count} product${result.count === 1 ? '' : 's'} updated.`)
      }
      setBulkEditOpen(false)
      setBulkAction('')
      load()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const runBulk = () => {
    if (!bulkAction || !selected.length) return
    if (bulkAction === 'edit') {
      setBulkEditOpen(true)
      return
    }
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

  /**
   * Flip Featured straight from the row, the way wp-admin's star column does.
   * Optimistic: the star moves at once and rolls back if the save is refused, because a
   * round trip before any feedback makes a one-click control feel broken.
   */
  const toggleFeatured = async (product) => {
    const next = !product.featured
    setData((current) => ({
      ...current,
      items: current.items.map((row) => (row.id === product.id ? { ...row, featured: next } : row)),
    }))
    try {
      await productsApi.update(product.id, { featured: next })
    } catch (err) {
      setData((current) => ({
        ...current,
        items: current.items.map((row) =>
          row.id === product.id ? { ...row, featured: !next } : row,
        ),
      }))
      toast.error(err.message)
    }
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

  // Quick edit: patch fields inline without opening the full editor. The row already
  // carries every value the panel needs, so opening it costs no request.
  const [quick, setQuick] = useState(null)
  const saveQuick = async () => {
    setBusy(true)
    try {
      const result = await productsApi.quickEdit([{ id: quick.id, ...quick.changes }])
      if (result.skipped.length) {
        toast.error(`Not saved: ${result.skipped[0].reason.replace(/_/g, ' ')}.`)
        return
      }
      // The server reports which fields the caller's own permissions dropped, so the
      // operator is told rather than watching an edit quietly revert.
      if (result.blocked?.length) {
        toast.info(`Saved, but some fields were not changed: ${result.blocked.join(', ')}.`)
      } else {
        toast.success('Product updated.')
      }
      setQuick(null)
      load()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const openQuick = (product) => setQuick(quickEditStateFrom(product))

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
          <>
            <ScreenOptions
              columns={visibleColumns}
              onToggleColumn={toggleColumn}
              perPage={perPage}
              onPerPage={(next) => {
                setPerPage(next)
                setPage(1)
              }}
            />
            <Can perm="products.create">
              <Button variant="primary" icon={Plus} onClick={() => navigate('/products/new')}>
                Add product
              </Button>
            </Can>
          </>
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
        <ProductFilters
          meta={meta}
          filters={filters}
          searchInput={searchInput}
          onSearchInput={setSearchInput}
          onFilter={setFilter}
          hasFilters={hasFilters}
          onClear={clearFilters}
          inTrash={inTrash}
          trashCount={data?.counts?.trash ?? 0}
          canEmptyTrash={can('products.delete')}
          onEmptyTrash={emptyTrash}
        />
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
            <ProductsTable
              items={items}
              meta={meta}
              columns={visibleColumns}
              sort={sort}
              onSort={toggleSort}
              selected={selected}
              onToggleOne={toggleOne}
              onToggleAll={toggleAll}
              allSelected={allSelected}
              someSelected={selected.length > 0}
              inTrash={inTrash}
              can={can}
              onFilterBy={setFilter}
              onEdit={(product) => navigate(`/products/${product.id}`)}
              onQuickEdit={openQuick}
              onDuplicate={duplicate}
              onToggleFeatured={toggleFeatured}
              onTrash={removeOne}
              onRestore={restoreOne}
              onDeleteForever={deleteOne}
            />

            <Pagination
              page={data.page}
              pages={data.total_pages}
              total={data.total}
              perPage={perPage}
              onPage={setPage}
              label="products"
            />
          </>
        )}
      </Card>

      {quick && (
        <ProductQuickEdit
          product={quick}
          meta={meta}
          can={can}
          busy={busy}
          onChange={setQuick}
          onClose={() => setQuick(null)}
          onSave={saveQuick}
        />
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
