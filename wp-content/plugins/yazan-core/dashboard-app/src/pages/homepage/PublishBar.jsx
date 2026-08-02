/**
 * Save state, undo/redo, and the two buttons that change what visitors see.
 *
 * Publish is visually separated from everything else on purpose: every other control in this
 * screen edits a draft nobody can see, and that one does not.
 */
import { useState } from 'react'
import { Badge, Button, IconButton, Input, Modal, Icons } from '../../components/ui/index.js'
import { Can } from '../../components/Protected.jsx'

export default function PublishBar({
  title,
  isHome,
  hasLiveContent,
  status,
  dirty,
  saving,
  savedAt,
  hasUnpublishedChanges,
  canUndo,
  canRedo,
  onUndo,
  onRedo,
  onSave,
  onPublish,
  onSchedule,
  onHistory,
  onExperiment,
  onExport,
  onImport,
  onRevertPublish,
  canRevertPublish,
  scheduledAt,
}) {
  const [scheduling, setScheduling] = useState(false)
  const [reverting, setReverting] = useState(false)
  const [when, setWhen] = useState('')

  return (
    <div className="flex items-center justify-between gap-3 border-b border-edge bg-surface px-3 py-2">
      <div className="flex items-center gap-2">
        {/* The name of the thing being edited, not the name of the screen. Editing a landing
            page while the bar says "Homepage" is how someone publishes to the wrong place. */}
        <span className="text-sm font-medium text-fg">{isHome ? 'Homepage' : title || 'Page layout'}</span>

        {status === 'scheduled' && scheduledAt ? (
          <Badge tone="info">scheduled</Badge>
        ) : !hasLiveContent ? (
          // Never published is not the same as up to date. The old code fell through to "live"
          // for a layout that had never gone anywhere.
          <Badge tone="warn">not published yet</Badge>
        ) : hasUnpublishedChanges ? (
          <Badge tone="warn">unpublished changes</Badge>
        ) : (
          <Badge tone="ok">live</Badge>
        )}

        <span className="text-2xs text-faint">
          {saving ? 'Saving…' : dirty ? 'Unsaved' : savedAt ? `Saved ${savedAt}` : ''}
        </span>
      </div>

      <div className="flex items-center gap-1">
        {/* Labelled, not bare icons: two arrows next to each other are a coin toss, and this is
            the control people reach for when they have just done something they regret. */}
        <Button variant="secondary" onClick={onUndo} disabled={!canUndo} title="Undo (Ctrl+Z)">
          <span className="flex items-center gap-1">
            <Icons.RotateCcw size={14} /> Undo
          </span>
        </Button>
        <Button variant="secondary" onClick={onRedo} disabled={!canRedo} title="Redo (Ctrl+Shift+Z)">
          <span className="flex items-center gap-1">
            <Icons.RefreshCw size={14} /> Redo
          </span>
        </Button>

        <span className="mx-1 h-5 w-px bg-divider" aria-hidden="true" />

        <Can perm="homepage.export">
          <IconButton label="Export this homepage as a file" onClick={onExport}>
            <Icons.Download />
          </IconButton>
        </Can>
        <Can perm="homepage.import">
          <IconButton label="Import a homepage file" onClick={onImport}>
            <Icons.Upload />
          </IconButton>
        </Can>
        <IconButton label="History" onClick={onHistory}>
          <Icons.ScrollText />
        </IconButton>
        <Can perm="homepage.experiment">
          {/* Columns3 rather than a new glyph: the icon set is a curated list on purpose, and two
              layouts side by side is exactly what this opens. */}
          <IconButton label="A/B test" onClick={onExperiment}>
            <Icons.Columns3 />
          </IconButton>
        </Can>

        <Button variant="secondary" onClick={onSave} disabled={!dirty || saving}>
          Save draft
        </Button>

        <Can perm="homepage.publish">
          <Button variant="secondary" onClick={() => setScheduling(true)}>
            Schedule
          </Button>
        </Can>

        {/* Only drawn when there is an earlier published version to go back to — the server says
            so, rather than the button failing on click. */}
        {canRevertPublish ? (
          <Can perm="homepage.publish">
            <Button variant="secondary" onClick={() => setReverting(true)} title="Put the previous published version back">
              <span className="flex items-center gap-1">
                <Icons.Undo2 size={14} /> Undo publish
              </span>
            </Button>
          </Can>
        ) : null}

        <Can perm="homepage.publish">
          <Button onClick={onPublish} disabled={saving}>
            Publish
          </Button>
        </Can>
      </div>

      <Modal
        open={reverting}
        onClose={() => setReverting(false)}
        title="Undo the last publish?"
        description="Visitors go back to the previous published version straight away. Your draft is not touched — whatever you are working on stays exactly as it is."
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setReverting(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => {
                onRevertPublish()
                setReverting(false)
              }}
            >
              Undo publish
            </Button>
          </div>
        }
      >
        <p className="text-sm text-muted">
          This is the fast fix for something that went live by mistake. To go further back, open
          History and restore a specific version into the draft.
        </p>
      </Modal>

      <Modal
        open={scheduling}
        onClose={() => setScheduling(false)}
        title="Publish later"
        description="The current draft goes live at this moment. Times are in the site's timezone."
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setScheduling(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => {
                onSchedule(when)
                setScheduling(false)
              }}
              disabled={!when}
            >
              Schedule
            </Button>
          </div>
        }
      >
        <Input type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} />
      </Modal>
    </div>
  )
}
