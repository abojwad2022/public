/**
 * Saved templates, shown inside the "Add a section" library.
 *
 * Two kinds, and the difference is stated rather than implied: a SECTION template adds one block,
 * a HOMEPAGE template replaces everything. The second is destructive, so it confirms and its
 * button is worded as a replacement, not as an "apply".
 */
import { useCallback, useEffect, useState } from 'react'
import { homepageApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Button, IconButton, Spinner, Icons } from '../../components/ui/index.js'
import { Can } from '../../components/Protected.jsx'

export default function TemplatesList({ open, version, onApplied }) {
  const toast = useToast()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await homepageApi.templates()
      setRows(data.templates || [])
    } catch (err) {
      // A missing templates permission is not an error worth shouting about — the list just
      // stays empty for that role.
      if (err.code !== 'rest_forbidden' && err.code !== 'yazan_homepage_forbidden') {
        toast.error(err.message)
      }
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    if (open) load()
  }, [open, load])

  const apply = async (row) => {
    if (row.kind === 'document') {
      // eslint-disable-next-line no-alert
      if (!window.confirm(`Replace the whole draft with "${row.name}"?\n\nThe live homepage does not change, and the draft is snapshotted first.`)) {
        return
      }
    }

    try {
      const result = await homepageApi.applyTemplate(row.id, { version })
      onApplied(result)
      toast.success(row.kind === 'document' ? 'Homepage template applied to the draft.' : 'Section added.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  const remove = async (row) => {
    try {
      await homepageApi.removeTemplate(row.id)
      setRows((current) => current.filter((entry) => entry.id !== row.id))
    } catch (err) {
      toast.error(err.message)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-4">
        <Spinner />
      </div>
    )
  }

  if (rows.length === 0) return null

  return (
    <div>
      <p className="mb-2 text-2xs font-medium uppercase tracking-wide text-faint">Saved templates</p>
      <div className="grid gap-2 sm:grid-cols-2">
        {rows.map((row) => (
          <div key={row.id} className="flex items-start justify-between gap-2 rounded border border-edge p-3">
            <div className="min-w-0">
              <p className="truncate text-sm text-fg">{row.name}</p>
              <p className="text-2xs text-muted">
                {row.kind === 'document' ? 'Whole homepage' : row.label}
                {!row.available ? ' · component unavailable' : ''}
              </p>
            </div>
            <div className="flex shrink-0 gap-1">
              <Button variant="secondary" onClick={() => apply(row)} disabled={!row.available}>
                {row.kind === 'document' ? 'Replace' : 'Add'}
              </Button>
              <Can perm="homepage.templates.delete">
                <IconButton label="Delete template" onClick={() => remove(row)}>
                  <Icons.Trash2 />
                </IconButton>
              </Can>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
