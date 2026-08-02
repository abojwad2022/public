/**
 * History: every publish, plus the automatic snapshots taken before a restore or an import.
 *
 * Restoring writes into the DRAFT and snapshots what it replaced, so nothing here is a one-way
 * door — the panel says so plainly, because "restore" reads as destructive and people hesitate.
 */
import { useCallback, useEffect, useState } from 'react'
import { homepageApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Button, Drawer, EmptyState, Spinner, Badge, Icons } from '../../components/ui/index.js'
import { Can } from '../../components/Protected.jsx'

/**
 * The API returns 'Y-m-d H:i:s' in UTC. Safari refuses that with a space, so it is normalised to
 * ISO before parsing — otherwise every timestamp in this panel reads "Invalid Date" on a Mac.
 */
function formatStamp(value) {
  if (!value) return ''
  const date = new Date(`${String(value).replace(' ', 'T')}Z`)
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString()
}

export default function RevisionsPanel({ open, onClose, version, onRestored }) {
  const toast = useToast()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(false)
  const [diff, setDiff] = useState(null)
  const [busy, setBusy] = useState(0)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await homepageApi.revisions({ per_page: 40 })
      setRows(data.revisions || [])
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    if (open) {
      setDiff(null)
      load()
    }
  }, [open, load])

  const compare = async (id) => {
    try {
      const data = await homepageApi.revisionDiff(id)
      setDiff({ id, ...data.diff })
    } catch (err) {
      toast.error(err.message)
    }
  }

  const restore = async (id) => {
    setBusy(id)
    try {
      const result = await homepageApi.restore(id, { version })
      toast.success('Restored into the draft. Review it, then publish when you are ready.')
      onRestored(result)
      onClose()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(0)
    }
  }

  return (
    <Drawer open={open} onClose={onClose} title="History" width="max-w-lg">
      {loading ? (
        <div className="flex justify-center py-8">
          <Spinner />
        </div>
      ) : rows.length === 0 ? (
        <EmptyState title="Nothing here yet" icon={Icons.ScrollText}>
          A snapshot is taken every time the homepage is published, and before any restore or
          import.
        </EmptyState>
      ) : (
        <ul className="space-y-2">
          {rows.map((row) => (
            <li key={row.id} className="rounded border border-edge p-3">
              <div className="flex items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate text-sm text-fg">
                    #{row.revision_no}
                    {row.is_publish ? (
                      <span className="ms-2 inline-block align-middle">
                        <Badge tone="ok">published</Badge>
                      </span>
                    ) : null}
                    {row.note ? <span className="ms-2 text-2xs text-faint">{row.note}</span> : null}
                  </p>
                  <p className="text-2xs text-faint">
                    {row.author} · {formatStamp(row.created_at)}
                  </p>
                </div>

                <div className="flex shrink-0 gap-1">
                  <Button variant="secondary" onClick={() => compare(row.id)}>
                    Compare
                  </Button>
                  <Can perm="homepage.rollback">
                    <Button onClick={() => restore(row.id)} disabled={busy === row.id}>
                      Restore
                    </Button>
                  </Can>
                </div>
              </div>

              {diff?.id === row.id ? (
                <div className="mt-2 rounded bg-sunken p-2 text-2xs text-muted">
                  {diff.total === 0 ? (
                    <span>Identical to the current draft.</span>
                  ) : (
                    <ul className="space-y-0.5">
                      {diff.added.map((entry) => (
                        <li key={`a-${entry.id}`} className="text-ok">+ {entry.type} added since</li>
                      ))}
                      {diff.removed.map((entry) => (
                        <li key={`r-${entry.id}`} className="text-danger">− {entry.type} removed since</li>
                      ))}
                      {diff.changed.map((entry) => (
                        <li key={`c-${entry.id}`}>
                          ~ {entry.type}: {entry.fields.slice(0, 4).join(', ') || 'shown/hidden'}
                          {entry.fields.length > 4 ? ` +${entry.fields.length - 4} more` : ''}
                        </li>
                      ))}
                      {diff.moved.map((entry) => (
                        <li key={`m-${entry.id}`}>
                          ↕ {entry.type} moved {entry.from + 1} → {entry.to + 1}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      <p className="mt-4 text-2xs text-faint">
        Restoring replaces the draft only. The live homepage does not change until you publish, and
        the draft it replaced is snapshotted first.
      </p>
    </Drawer>
  )
}
