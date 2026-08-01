import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router'
import { inventoryApi, productsApi } from '../api/endpoints.js'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Input,
  SearchInput,
  SegmentedControl,
  Select,
  SkeletonTable,
  Table,
  TBody,
  TD,
  TH,
  THead,
  TR,
} from '../components/ui/index.js'
import { Boxes, RotateCcw, Save } from '../components/ui/icons.js'

const FILTERS = [
  { value: '', label: 'All' },
  { value: 'outofstock', label: 'Out of stock' },
  { value: 'onbackorder', label: 'On backorder' },
  { value: 'instock', label: 'In stock' },
]

/**
 * Spreadsheet-style stock editing: change several rows, then save them all in one request.
 * Only rows the user actually touched are sent, and the server's response is used to re-sync
 * the table so what you see always matches what WooCommerce stored.
 */
export default function Inventory() {
  const toast = useToast()
  const [rows, setRows] = useState([])
  const [edits, setEdits] = useState({}) // id -> partial changes
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [filter, setFilter] = useState('')
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await productsApi.list({
        stock_status: filter,
        search,
        per_page: 100,
        orderby: 'title',
        order: 'asc',
      })
      setRows(data.items)
      setEdits({})
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [filter, search, toast])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchInput), 350)
    return () => clearTimeout(timer)
  }, [searchInput])

  const dirtyIds = useMemo(() => Object.keys(edits).map(Number), [edits])

  const setField = (id, key, value) => {
    setEdits((current) => ({ ...current, [id]: { ...current[id], [key]: value } }))
  }

  /** Effective value: the pending edit if there is one, else the stored value. */
  const valueOf = (row, key) => (edits[row.id] && key in edits[row.id] ? edits[row.id][key] : row[key])

  const save = async () => {
    if (dirtyIds.length === 0) return
    setSaving(true)
    try {
      const payload = dirtyIds.map((id) => ({ id, ...edits[id] }))
      const result = await inventoryApi.bulkSave(payload)

      // Re-sync only the affected rows from the server's own values.
      setRows((current) =>
        current.map((row) => {
          const updated = result.updated.find((u) => u.id === row.id)
          return updated
            ? {
                ...row,
                manage_stock: updated.manage_stock,
                stock_qty: updated.stock_quantity,
                stock_status: updated.stock_status,
              }
            : row
        }),
      )
      setEdits({})

      if (result.skipped.length > 0) {
        const variable = result.skipped.filter((s) => s.reason === 'variable_parent')
        if (variable.length > 0) {
          toast.error(
            `${result.count} saved. ${variable.length} variable product(s) skipped — their stock lives on the variations.`,
          )
        } else {
          toast.error(`${result.count} saved, ${result.skipped.length} skipped.`)
        }
      } else {
        toast.success(`${result.count} product(s) updated.`)
      }
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <PageHeader
        title="Inventory"
        subtitle="Edit stock across the catalogue, then save once"
        breadcrumbs={[{ label: 'Catalog' }, { label: 'Inventory' }]}
        actions={
          <>
            {dirtyIds.length > 0 && (
              <Button icon={RotateCcw} onClick={() => setEdits({})} disabled={saving}>
                Discard
              </Button>
            )}
            <Button
              variant="primary"
              icon={Save}
              onClick={save}
              loading={saving}
              disabled={dirtyIds.length === 0}
            >
              {dirtyIds.length > 0
                ? `Save ${dirtyIds.length} change${dirtyIds.length === 1 ? '' : 's'}`
                : 'Save'}
            </Button>
          </>
        }
      />

      <Card className="mb-4" bodyClass="p-3">
        <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
          <SearchInput
            value={searchInput}
            onChange={setSearchInput}
            placeholder="Search products…"
            className="col-span-2 min-w-52 flex-1"
          />
          <SegmentedControl items={FILTERS} value={filter} onChange={setFilter} label="Stock filter" />
        </div>
      </Card>

      {dirtyIds.length > 0 && (
        <Alert tone="warn" title={`${dirtyIds.length} unsaved change${dirtyIds.length === 1 ? '' : 's'}`} className="mb-4">
          Nothing is written to WooCommerce until you press Save.
        </Alert>
      )}

      <Card bodyClass="p-0">
        {loading ? (
          <SkeletonTable rows={10} cols={5} />
        ) : rows.length === 0 ? (
          <EmptyState title="Nothing here" icon={Boxes}>
            No products match this filter.
          </EmptyState>
        ) : (
          <Table>
            <THead>
              <TR>
                <TH>Product</TH>
                <TH>SKU</TH>
                <TH align="center" className="w-28">
                  Manage stock
                </TH>
                <TH align="center" className="w-28">
                  Quantity
                </TH>
                <TH className="w-44">Status</TH>
              </TR>
            </THead>
            <TBody>
              {rows.map((row) => {
                const dirty = Boolean(edits[row.id])
                const managed = Boolean(valueOf(row, 'manage_stock'))
                const isVariable = row.type === 'variable'

                return (
                  <TR key={row.id} className={dirty ? 'bg-warn-bg' : 'yz-tr-hover'}>
                    <TD>
                      <span className="flex items-center gap-2">
                        {/* A dirty row is marked by more than colour: the bar and the
                            "edited" tag survive both greyscale and colour blindness. */}
                        {dirty && (
                          <span
                            aria-hidden="true"
                            className="inline-block h-4 w-0.5 shrink-0 rounded-full bg-warn"
                          />
                        )}
                        <Link
                          to={`/products/${row.id}`}
                          className="text-fg transition-colors hover:text-agate-fg"
                        >
                          {row.name}
                        </Link>
                        {isVariable && <Badge>variable</Badge>}
                        {dirty && <Badge tone="warn">edited</Badge>}
                      </span>
                    </TD>
                    <TD className="font-mono text-xs text-muted">{row.sku || '—'}</TD>

                    <TD align="center">
                      <input
                        type="checkbox"
                        checked={managed}
                        disabled={isVariable}
                        aria-label={`Manage stock for ${row.name}`}
                        onChange={(e) => setField(row.id, 'manage_stock', e.target.checked)}
                        className="size-4 rounded-xs accent-[var(--yz-agate)] disabled:opacity-40"
                      />
                    </TD>

                    <TD align="center">
                      {managed && !isVariable ? (
                        <Input
                          type="number"
                          inputMode="numeric"
                          aria-label={`Stock quantity for ${row.name}`}
                          value={valueOf(row, 'stock_qty') ?? ''}
                          onChange={(e) =>
                            setField(
                              row.id,
                              'stock_quantity',
                              e.target.value === '' ? '' : Number(e.target.value),
                            )
                          }
                          className="mx-auto w-20 text-center"
                        />
                      ) : (
                        <span className="text-faint">—</span>
                      )}
                    </TD>

                    <TD>
                      <Select
                        value={valueOf(row, 'stock_status')}
                        disabled={isVariable}
                        aria-label={`Stock status for ${row.name}`}
                        onChange={(e) => setField(row.id, 'stock_status', e.target.value)}
                      >
                        <option value="instock">In stock</option>
                        <option value="outofstock">Out of stock</option>
                        <option value="onbackorder">On backorder</option>
                      </Select>
                    </TD>
                  </TR>
                )
              })}
            </TBody>
          </Table>
        )}
      </Card>

      <p className="mt-4 text-xs text-faint">
        Variable products are read-only here — WooCommerce keeps their stock on each variation, so
        edit those in the product's Variations tab. Showing up to 100 products; narrow with search or
        a filter if you need more.
      </p>
    </>
  )
}
