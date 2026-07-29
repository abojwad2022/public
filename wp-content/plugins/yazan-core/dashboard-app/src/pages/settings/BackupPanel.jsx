import { useCallback, useEffect, useState } from 'react'
import { backupApi } from '../../api/endpoints.js'
import { boot } from '../../api/client.js'
import { useToast } from '../../context/ToastContext.jsx'
import {
  useConfirm,
  Badge,
  Button,
  Card,
  Checkbox,
  EmptyState,
  Select,
  Spinner,
  TBody,
  TD,
  TH,
  THead,
  TR,
  Table,
} from '../../components/ui/index.js'

/**
 * Full-site backup & restore. Creates a compressed archive (database + wp-content) that is stored on
 * the server and can be downloaded, restored, or deleted. Restore is deliberately guarded — it
 * overwrites the live site — and always takes an automatic database safety snapshot first.
 */
export default function BackupPanel() {
  const [confirm, confirmDialog] = useConfirm()
  const toast = useToast()
  const [state, setState] = useState(null)
  const [loading, setLoading] = useState(true)
  const [scope, setScope] = useState('full')
  const [keep, setKeep] = useState('')
  const [creating, setCreating] = useState(false)
  const [busyId, setBusyId] = useState(null)
  const [safety, setSafety] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setState(await backupApi.index())
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const create = async () => {
    setCreating(true)
    try {
      const keepN = keep === '' ? undefined : Math.max(1, parseInt(keep, 10) || 0)
      const result = await backupApi.create(scope, keepN)
      setState((s) => ({ ...s, backups: result.backups }))
      toast.success(`Backup created (${formatBytes(result.backup.size)}).`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setCreating(false)
    }
  }

  const download = async (id) => {
    setBusyId(id)
    try {
      const { url } = await backupApi.downloadToken(id)
      // A single-use token URL — navigating to it streams the file without unloading the SPA.
      const link = document.createElement('a')
      link.href = url
      document.body.appendChild(link)
      link.click()
      link.remove()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusyId(null)
    }
  }

  const remove = async (id) => {
    if (!(await confirm({ title: 'Delete this backup permanently?' }))) return
    setBusyId(id)
    try {
      const result = await backupApi.remove(id)
      setState((s) => ({ ...s, backups: result.backups }))
      toast.success('Backup deleted.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusyId(null)
    }
  }

  const restore = async (backup) => {
    const first = window.confirm(
      `Restore the site from the backup taken on ${backup.created_at} UTC?\n\n` +
        'This OVERWRITES your live database' +
        (backup.scope === 'db' ? '' : ' and files') +
        ' and cannot be undone.',
    )
    if (!first) return
    const typed = window.prompt('Type RESTORE (in capitals) to confirm this irreversible action:')
    if (typed !== 'RESTORE') {
      if (typed !== null) toast.error('Restore cancelled — confirmation did not match.')
      return
    }

    setBusyId(backup.id)
    try {
      const result = await backupApi.restore(backup.id, safety)
      const s = result.summary || {}
      const parts = []
      if (s.db_restored) parts.push('database restored')
      if (s.files_restored) parts.push(`${s.files_restored} files restored`)
      if (s.safety_backup) parts.push('safety snapshot saved')
      toast.success(`Restore complete — ${parts.join(', ') || 'done'}.`)
      await load()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusyId(null)
    }
  }

  if (loading) return <Spinner label="Loading backups…" />

  const backups = state?.backups || []
  const supported = state?.supported

  return (
    <>
    <div className="space-y-5">
      {!supported && (
        <p className="yz-card p-3 text-sm text-danger border-s-2 border-danger">
          The PHP <code>ZipArchive</code > extension is not available on this server, so backups cannot
          be created or restored.
        </p>
      )}

      <Card title="Create a backup">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <span className="yz-label">Contents</span>
            <Select value={scope} onChange={(e) => setScope(e.target.value)} className="w-auto">
              <option value="full">Full site — database + wp-content</option>
              <option value="db">Database only</option>
            </Select>
          </div>
          <div>
            <span className="yz-label">Keep last</span>
            <input
              type="number"
              min="1"
              placeholder="all"
              value={keep}
              onChange={(e) => setKeep(e.target.value)}
              className="yz-input w-24"
            />
          </div>
          <Button variant="primary" onClick={create} disabled={creating || !supported}>
            {creating ? 'Building… please wait' : 'Create backup now'}
          </Button>
        </div>
        <p className="yz-help">
          A full backup of a large store can take a minute or two — keep this tab open until it
          finishes. Optionally keep only the most recent N archives to save disk space.
        </p>
      </Card>

      <Card
        title="Stored backups"
        bodyClass="p-0"
        actions={<Badge tone="muted">{backups.length}</Badge>}
      >
        {backups.length === 0 ? (
          <EmptyState title="No backups yet">
            Create your first backup above. Archives are stored on the server and can also be managed
            from wp-admin under Tools → Yazan Backup.
          </EmptyState>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <THead>
                <TR>
                  <TH>Created (UTC)</TH>
                  <TH>Scope</TH>
                  <TH>Size</TH>
                  <TH>Contents</TH>
                  <TH align="end">Actions</TH>
                </TR>
              </THead>
              <TBody>
                {backups.map((b) => (
                  <TR key={b.id}>
                    <TD className="text-fg">
                      {b.created_at}
                      {b.note && <span className="block text-xs text-faint">{b.note}</span>}
                    </TD>
                    <TD>
                      <Badge tone={b.scope === 'db' ? 'muted' : 'gold'}>
                        {b.scope === 'db' ? 'Database' : 'Full site'}
                      </Badge>
                    </TD>
                    <TD className="text-muted">{formatBytes(b.size)}</TD>
                    <TD className="text-xs text-muted">
                      {b.tables ?? '—'} tables · {b.files ?? '—'} files
                    </TD>
                    <TD>
                      <div className="flex justify-end gap-2">
                        <Button small onClick={() => download(b.id)} disabled={busyId === b.id}>
                          Download
                        </Button>
                        <Button
                          small
                          variant="primary"
                          onClick={() => restore(b)}
                          disabled={busyId === b.id}
                        >
                          {busyId === b.id ? 'Working…' : 'Restore'}
                        </Button>
                        <Button
                          small
                          variant="danger"
                          onClick={() => remove(b.id)}
                          disabled={busyId === b.id}
                        >
                          Delete
                        </Button>
                      </div>
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
        )}
      </Card>

      <Card title="Restore options">
        <Checkbox
          label="Take an automatic database safety snapshot before restoring"
          checked={safety}
          onChange={setSafety}
          help="Recommended — lets you roll back a mistaken restore. Adds a database-only backup to the list above."
        />
        <p className="yz-help mt-3">
          Restoring overwrites your live site from the chosen archive and cannot be undone. To restore
          from a file taken on another machine, use{' '}
          <a
            href={state?.admin_url || '/wp-admin/tools.php?page=yazan-backup'}
            target="_blank"
            rel="noreferrer"
            className="text-gold hover:underline"
          >
            wp-admin → Tools → Yazan Backup
          </a>
          .
        </p>
      </Card>
    </div>
      {confirmDialog}
    </>
  )
}

function formatBytes(bytes) {
  const n = Number(bytes) || 0
  if (n < 1024) return `${n} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = n / 1024
  let i = 0
  while (value>= 1024 && i < units.length - 1) {
    value /= 1024
    i += 1
  }
  return `${value.toFixed(value>= 10 || i === 0 ? 0 : 1)} ${units[i]}`
}
