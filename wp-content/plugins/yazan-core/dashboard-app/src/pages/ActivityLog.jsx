import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router'
import { auditApi } from '../api/endpoints.js'
import { useAuth } from '../context/AuthContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import {
  Alert,
  Badge,
  Button,
  Card,
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
import { ScrollText, Trash2 } from '../components/ui/icons.js'

/** Colour the action by what it does, so destructive events stand out when scanning. */
function actionTone(action) {
  if (/delete|purge|refund_failed/.test(action)) return 'danger'
  if (/create/.test(action)) return 'ok'
  if (/refund|bulk/.test(action)) return 'warn'
  if (/login|logout/.test(action)) return 'muted'
  return 'info'
}

/** Objects we can deep-link back into the dashboard. */
function objectLink(row) {
  if (!row.object_id) return null
  if (row.object_type === 'product') return `/products/${row.object_id}`
  if (row.object_type === 'order') return `/orders/${row.object_id}`
  return null
}

const EMPTY = { search: '', action_filter: '', object_type: '', date_from: '', date_to: '' }

export default function ActivityLog() {
  const toast = useToast()
  const { can } = useAuth()
  const [filters, setFilters] = useState(EMPTY)
  const [searchInput, setSearchInput] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [purging, setPurging] = useState(false)
  const [purgeOpen, setPurgeOpen] = useState(false)
  const [purgeDays, setPurgeDays] = useState('90')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await auditApi.list({ ...filters, page, per_page: 50 }))
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [filters, page, toast])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    const timer = setTimeout(() => {
      setFilters((c) => (c.search === searchInput ? c : { ...c, search: searchInput }))
      setPage(1)
    }, 350)
    return () => clearTimeout(timer)
  }, [searchInput])

  const setFilter = (key, value) => {
    setFilters((c) => ({ ...c, [key]: value }))
    setPage(1)
  }

  const days = Number(purgeDays)
  const daysValid = Number.isFinite(days) && days>= 1

  /**
   * Purge used to be window.prompt() followed by window.confirm() — two browser
   * dialogs for one irreversible action, neither of which could show what was
   * about to happen. One form, validated inline, with the consequence stated.
   */
  const purge = async () => {
    if (!daysValid) return
    setPurging(true)
    try {
      const result = await auditApi.purge(days)
      toast.success(`${result.removed} entr${result.removed === 1 ? 'y' : 'ies'} removed.`)
      setPurgeOpen(false)
      load()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setPurging(false)
    }
  }

  const facets = data?.facets || { actions: [], object_types: [] }
  const hasFilters = Object.values(filters).some(Boolean)
  const clearFilters = () => {
    setFilters(EMPTY)
    setSearchInput('')
    setPage(1)
  }

  return (
    <>
      <Card className="mb-4" bodyClass="p-3">
        <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
          <SearchInput
            value={searchInput}
            onChange={setSearchInput}
            placeholder="Search the log…"
            className="col-span-2 min-w-48 flex-1"
          />

          <Select
            value={filters.action_filter}
            onChange={(e) => setFilter('action_filter', e.target.value)}
            className="w-full sm:w-auto"
          >
            <option value="">All actions</option>
            {facets.actions.map((a) => (
              <option key={a} value={a}>
                {a}
              </option>
            ))}
          </Select>

          <Select
            value={filters.object_type}
            onChange={(e) => setFilter('object_type', e.target.value)}
            className="w-full sm:w-auto"
          >
            <option value="">All types</option>
            {facets.object_types.map((t) => (
              <option key={t} value={t}>
                {t}
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

          {can('audit.purge') && (
            <Button size="sm" variant="danger" icon={Trash2} onClick={() => setPurgeOpen(true)}>
              Purge old
            </Button>
          )}
        </div>
      </Card>

      <Card bodyClass="p-0">
        {loading ? (
          <SkeletonTable rows={10} cols={5} />
        ) : !data?.items.length ? (
          <EmptyState
            title="No activity"
            icon={ScrollText}
            action={hasFilters ? <Button onClick={clearFilters}>Clear filters</Button> : null}
          >
            {hasFilters ? 'Nothing matches these filters.' : 'Actions taken in the dashboard appear here.'}
          </EmptyState>
        ) : (
          <>
            <Table>
              <THead>
                <TR>
                  <TH className="w-40">When</TH>
                  <TH className="w-36">Action</TH>
                  <TH className="w-28">Object</TH>
                  <TH>Details</TH>
                  <TH className="w-28">By</TH>
                  <TH className="w-28">IP</TH>
                  <TH className="w-32">Browser</TH>
                </TR>
              </THead>
              <TBody>
                {data.items.map((row) => {
                  const link = objectLink(row)
                  return (
                    <TR key={row.id} className="align-top">
                      <TD className="yz-num whitespace-nowrap text-muted">{row.date} label="When"</TD>
                      <TD label="Action">
                        <Badge tone={actionTone(row.action)}>{row.action}</Badge>
                      </TD>
                      <TD className="text-muted" label="Object">
                        {row.object_id ? (
                          link ? (
                            <Link to={link} className="text-agate-fg hover:underline">
                              {row.object_type} #{row.object_id}
                            </Link>
                          ) : (
                            `${row.object_type} #${row.object_id}`
                          )
                        ) : (
                          row.object_type || '—'
                        )}
                      </TD>
                      <TD className="text-xs text-muted" label="Details">
                        {Object.keys(row.changes || {}).length === 0 ? (
                          '—'
                        ) : (
                          <ul className="space-y-0.5">
                            {Object.entries(row.changes).map(([key, value]) => (
                              <li key={key}>
                                <span className="text-faint">{key}:</span>{' '}
                                <span className="text-fg">
                                  {Array.isArray(value) ? value.join(', ') : String(value)}
                                </span>
                              </li>
                            ))}
                          </ul>
                        )}
                      </TD>
                      <TD className="text-muted">{row.user} label="By"</TD>
                      <TD className="font-mono text-xs text-faint" label="IP">{row.ip || '—'}</TD>
                      {/* Truncated with the full string on hover — a user-agent is far too long
                          for a column, but useless if it cannot be read at all. */}
                      <TD className="max-w-32 text-xs text-faint" label="Browser">
                        <span className="block truncate" title={row.user_agent || undefined}>
                          {row.user_agent || '—'}
                        </span>
                      </TD>
                    </TR>
                  )
                })}
              </TBody>
            </Table>

            <Pagination
              page={data.page}
              pages={data.total_pages}
              total={data.total}
              perPage={50}
              onPage={setPage}
              label="entries"
            />
          </>
        )}
      </Card>

      <Modal
        open={purgeOpen}
        onClose={() => setPurgeOpen(false)}
        title="Purge old activity"
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setPurgeOpen(false)} disabled={purging}>
              Cancel
            </Button>
            <Button variant="danger" onClick={purge} loading={purging} disabled={!daysValid}>
              Delete permanently
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Alert tone="warn" title="This cannot be undone">
            The log is append-only — entries can only be aged out in bulk, never edited.
          </Alert>
          <Field
            label="Delete activity older than"
            help="Entries newer than this are kept."
            error={purgeDays !== '' && !daysValid ? 'Enter a number of days (at least 1).' : undefined}
          >
            <Input
              type="number"
              min="1"
              inputMode="numeric"
              value={purgeDays}
              onChange={(e) => setPurgeDays(e.target.value)}
              data-autofocus
            />
          </Field>
        </div>
      </Modal>
    </>
  )
}
