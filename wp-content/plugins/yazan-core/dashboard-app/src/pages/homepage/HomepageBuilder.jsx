/**
 * The Homepage Manager screen.
 *
 * Three panes: the section list, a live preview of the draft rendered by the real theme, and an
 * inspector generated from the selected component's schema.
 *
 * Editing is LOCAL and instant — add, remove, reorder, toggle and every field change go into the
 * reducer, which is what makes undo/redo work across all of them. One debounced PUT then saves the
 * whole draft. The server re-checks every permission on that path (create, edit, delete AND sort),
 * so a fast local edit is never a way around authorization; it just means the round trip does not
 * sit between the user and their own typing.
 */
import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react'
import { boot } from '../../api/client.js'
import { homepageApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Alert, Spinner } from '../../components/ui/index.js'
import AddSectionPanel from './AddSectionPanel.jsx'
import Inspector from './Inspector.jsx'
import PreviewFrame from './PreviewFrame.jsx'
import DocumentSwitcher from './DocumentSwitcher.jsx'
import ExperimentPanel from './ExperimentPanel.jsx'
import PublishBar from './PublishBar.jsx'
import RevisionsPanel from './RevisionsPanel.jsx'
import SectionTree from './SectionTree.jsx'
import { documentReducer, initialState } from './state/documentReducer.js'

const AUTOSAVE_MS = 1500

export default function HomepageBuilder() {
  const toast = useToast()

  const [state, dispatch] = useReducer(documentReducer, initialState)
  const [docKey, setDocKey] = useState('default')
  const [reloadToken, setReloadToken] = useState(0)
  const [components, setComponents] = useState([])
  const [designSchema, setDesignSchema] = useState([])
  const [rights, setRights] = useState({})
  const [serverCan, setServerCan] = useState({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [savedAt, setSavedAt] = useState('')
  const [device, setDevice] = useState('desktop')
  const [adding, setAdding] = useState(false)
  const [blocked, setBlocked] = useState([])
  const [history, setHistory] = useState(false)
  const [experiment, setExperiment] = useState(false)
  const [documents, setDocuments] = useState([])
  const [canRevertPublish, setCanRevertPublish] = useState(false)
  const importInput = useRef(null)
  const [error, setError] = useState('')

  const versionRef = useRef(0)
  versionRef.current = state.version

  /*
   * What the server last confirmed, kept so a save can say WHICH sections changed. The preview
   * patches only those; without this it would have to reload the whole page to find out.
   */
  const syncedRef = useRef([])
  const [previewSignal, setPreviewSignal] = useState(null)

  // Every request names the document it means. Reading it from a ref rather than closing over the
  // state avoids the classic bug where an autosave fired mid-switch writes one layout's sections
  // into another one's row.
  const docRef = useRef('default')
  docRef.current = docKey

  /* ------------------------------------------------------------------ load */

  useEffect(() => {
    let cancelled = false

    ;(async () => {
      try {
        const data = await homepageApi.get({ doc: docKey })
        if (cancelled) return
        dispatch({ type: 'load', document: data.document })
        // Seeded on load, so the first save diffs against what is actually on screen.
        syncedRef.current = data.document.sections || []
        setPreviewSignal({
          version: data.document.version,
          ids: [],
          structure: (data.document.sections || []).map((section) => section.id).join('|'),
        })
        setCanRevertPublish(Boolean(data.document.can_revert_publish))
        setComponents(data.components || [])
        setDesignSchema(data.design || [])
        setRights(data.sections || {})
        setServerCan(data.can || {})
      } catch (err) {
        if (!cancelled) setError(err.message)
      } finally {
        if (!cancelled) setLoading(false)
      }
    })()

    return () => {
      cancelled = true
    }
  }, [docKey, reloadToken])

  // The layout list, for the A/B panel's variant picker. Loaded here rather than inside the panel
  // so opening it is instant and a permission failure is silent — a role without layouts simply
  // sees an empty picker instead of an error it cannot act on.
  useEffect(() => {
    homepageApi
      .documents()
      .then((data) => setDocuments(data.documents || []))
      .catch(() => setDocuments([]))
  }, [])

  /* ------------------------------------------------------------------ save */

  /*
   * Tell the preview what moved. `structure` is the id order: when it differs the preview cannot
   * be patched at all — a section was added, removed or dragged — and the frame reloads instead.
   */
  const signalPreview = useCallback((sections, version) => {
    const next = Array.isArray(sections) ? sections : []
    const before = new Map(syncedRef.current.map((section) => [section.id, JSON.stringify(section)]))

    const ids = next
      .filter((section) => before.get(section.id) !== JSON.stringify(section))
      .map((section) => section.id)

    setPreviewSignal({
      version,
      ids,
      structure: next.map((section) => section.id).join('|'),
    })

    syncedRef.current = next
  }, [])

  const save = useCallback(
    async (sections) => {
      setSaving(true)
      try {
        const result = await homepageApi.save({ doc: docRef.current, version: versionRef.current, sections })

        dispatch({
          type: 'synced',
          version: result.version,
          status: result.status,
          hasUnpublishedChanges: result.has_unpublished_changes,
          unavailable: result.unavailable,
          // The server's copy is authoritative: a field the user may not write comes back with
          // its stored value, and new sections come back with their real ids.
          sections: result.sections,
        })

        setBlocked(result.blocked || [])
        setSavedAt(new Date().toLocaleTimeString())
        signalPreview(result.sections, result.version)

        if (result.blocked?.length) {
          // ToastContext exposes success / error / info only. `warning` does not exist and would
          // have thrown the first time a field was refused — exactly when the operator most needs
          // to be told something.
          toast.info(`${result.blocked.length} change(s) were not saved — you do not have permission for those fields.`)
        }
      } catch (err) {
        if (err.code === 'yazan_homepage_version_conflict') {
          toast.error('Someone else saved the homepage. Reload to see their version before continuing.')
        } else {
          toast.error(err.message)
        }
      } finally {
        setSaving(false)
      }
    },
    [toast, signalPreview],
  )

  // Debounced autosave. The timer is reset by every keystroke, so a burst of typing is one write.
  useEffect(() => {
    if (!state.dirty || loading) return undefined

    const timer = setTimeout(() => save(state.sections), AUTOSAVE_MS)
    return () => clearTimeout(timer)
  }, [state.dirty, state.sections, loading, save])

  // Ctrl/Cmd+Z and Ctrl+Shift+Z. Skipped while a text field has focus, so the browser's own
  // undo still works where people expect it — inside the box they are typing in.
  useEffect(() => {
    const onKey = (event) => {
      if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'z') return

      const tag = event.target?.tagName
      if (tag === 'INPUT' || tag === 'TEXTAREA' || event.target?.isContentEditable) return

      event.preventDefault()
      dispatch({ type: event.shiftKey ? 'redo' : 'undo' })
    }

    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  /* ------------------------------------------------------------------ actions */

  const selected = useMemo(
    () => state.sections.find((section) => section.id === state.selectedId) || null,
    [state.sections, state.selectedId],
  )

  const component = useMemo(
    () => components.find((entry) => entry.type === selected?.type) || null,
    [components, selected],
  )

  const canEditSection = useCallback(
    (type) => rights?.[type]?.edit !== false,
    [rights],
  )

  const addSection = (type) => {
    const definition = components.find((entry) => entry.type === type)
    if (!definition) return

    // A temporary id, never a UUID: the server mints the real one, and its shape makes an
    // unsaved section obvious in any log.
    dispatch({
      type: 'add',
      section: {
        id: `tmp-${Math.random().toString(36).slice(2, 10)}`,
        type,
        state: 'enabled',
        label: '',
        content: { ...(definition.defaults || {}) },
        visibility: { desktop: true, tablet: true, mobile: true },
        schedule: { from: null, to: null },
        design: {},
      },
    })
    setAdding(false)
  }

  const duplicateSection = (id) => {
    const source = state.sections.find((section) => section.id === id)
    if (!source) return

    dispatch({
      type: 'add',
      position: state.sections.findIndex((section) => section.id === id) + 1,
      section: {
        ...source,
        id: `tmp-${Math.random().toString(36).slice(2, 10)}`,
        content: JSON.parse(JSON.stringify(source.content || {})),
      },
    })
  }

  const removeSection = (id) => {
    const index = state.sections.findIndex((section) => section.id === id)
    const removed = state.sections[index]
    if (!removed) return

    dispatch({ type: 'remove', id })

    // Re-adding the exact section at its exact position, rather than relying on the undo stack:
    // by the time someone reaches for this they may already have changed something else, and
    // popping "the last thing" would undo the wrong one.
    toast.info('Section removed.', {
      action: {
        label: 'Undo',
        onClick: () => dispatch({ type: 'add', position: index, section: removed }),
      },
    })
  }

  const revertPublish = async () => {
    setSaving(true)
    try {
      const result = await homepageApi.revertPublish({ doc: docRef.current, version: versionRef.current })

      dispatch({
        type: 'synced',
        version: result.version,
        status: result.status,
        hasUnpublishedChanges: result.has_unpublished_changes,
      })

      // Whether another undo is possible is a server question — ask it rather than guessing.
      const fresh = await homepageApi.get({ doc: docRef.current })
      setCanRevertPublish(Boolean(fresh.document.can_revert_publish))
      dispatch({ type: 'synced', version: fresh.document.version, hasLiveContent: Boolean(fresh.document.has_live_content) })

      toast.success('The previous version is live again. Your draft is untouched.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  const saveTemplate = async (sectionId) => {
    // eslint-disable-next-line no-alert
    const name = window.prompt(sectionId ? 'Name for this section template:' : 'Name for this homepage template:')
    if (!name) return

    try {
      if (state.dirty) await save(state.sections)
      await homepageApi.saveTemplate({ doc: docRef.current, name, section: sectionId || undefined })
      toast.success('Saved to the template library.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  const shareSection = async (sectionId) => {
    // eslint-disable-next-line no-alert
    const name = window.prompt('Name for this shared section:')
    if (!name) return

    try {
      if (state.dirty) await save(state.sections)

      const result = await homepageApi.shareSection(sectionId, {
        doc: docRef.current,
        name,
        version: versionRef.current,
      })

      dispatch({
        type: 'synced',
        version: result.version,
        hasUnpublishedChanges: result.has_unpublished_changes,
        sections: result.sections,
      })

      signalPreview(result.sections, result.version)
      toast.success('Shared. Every page using it now shows the same content.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  const detachSection = async (sectionId) => {
    try {
      const result = await homepageApi.detachSection(sectionId, {
        doc: docRef.current,
        version: versionRef.current,
      })

      dispatch({
        type: 'synced',
        version: result.version,
        hasUnpublishedChanges: result.has_unpublished_changes,
        sections: result.sections,
      })

      signalPreview(result.sections, result.version)
      toast.success('This page now has its own copy.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  const publish = async () => {
    setSaving(true)
    try {
      if (state.dirty) await save(state.sections)
      const result = await homepageApi.publish({ doc: docRef.current, version: versionRef.current })
      dispatch({
        type: 'synced',
        version: result.version,
        status: result.status,
        hasUnpublishedChanges: result.has_unpublished_changes,
        hasLiveContent: true,
      })
      setCanRevertPublish(true)
      toast.success(docRef.current === 'default' ? 'The homepage is live.' : 'This page layout is live.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  const schedule = async (when) => {
    try {
      if (state.dirty) await save(state.sections)
      const result = await homepageApi.schedule({ doc: docRef.current, version: versionRef.current, when })
      dispatch({ type: 'synced', version: result.version, status: result.status })
      toast.success('Scheduled.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  const switchDocument = (next) => {
    setLoading(true)
    setBlocked([])
    setSavedAt('')
    setDocKey(next)
  }

  /* ------------------------------------------------------------------ packages */

  const exportDocument = async () => {
    try {
      const pkg = await homepageApi.export({ doc: docRef.current })
      // Built in the browser: the file never becomes a URL anyone could stumble onto, and the
      // download is the same bytes the API just returned.
      const blob = new Blob([JSON.stringify(pkg, null, 2)], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `yazan-homepage-${new Date().toISOString().slice(0, 10)}.json`
      link.click()
      URL.revokeObjectURL(url)
    } catch (err) {
      toast.error(err.message)
    }
  }

  const importDocument = async (file) => {
    if (!file) return

    try {
      const text = await file.text()

      // Dry run first, always. The operator is told exactly what would happen before anything
      // changes — the same rule the CSV importer follows.
      const report = await homepageApi.import({ doc: docRef.current, package: text })

      const summary = [
        `${report.sections} section(s)`,
        report.media_dropped?.length ? `${report.media_dropped.length} image(s) will be cleared` : '',
      ]
        .filter(Boolean)
        .join(' · ')

      // eslint-disable-next-line no-alert
      if (!window.confirm(`Import this package?\n\n${summary}\n\nIt replaces the whole draft. The live homepage does not change.`)) {
        return
      }

      const result = await homepageApi.import({ doc: docRef.current, package: text, confirm: true, version: versionRef.current })

      dispatch({
        type: 'synced',
        version: result.version,
        status: result.status,
        hasUnpublishedChanges: result.has_unpublished_changes,
        sections: result.sections,
      })

      signalPreview(result.sections, result.version)
      toast.success('Imported into the draft. Review it, then publish.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      if (importInput.current) importInput.current.value = ''
    }
  }

  /* ------------------------------------------------------------------ render */

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner />
      </div>
    )
  }

  if (error) {
    return <Alert tone="danger" title="The homepage could not be loaded">{error}</Alert>
  }

  return (
    <div className="flex h-[calc(100vh-6rem)] flex-col overflow-hidden rounded border border-edge bg-canvas">
      <div className="flex items-center gap-2 border-b border-edge bg-surface px-3 py-1.5">
        <span className="text-2xs uppercase tracking-wide text-faint">Editing</span>
        <DocumentSwitcher
          docKey={docKey}
          onSwitch={switchDocument}
          onDocumentChanged={() => setReloadToken((n) => n + 1)}
          dirty={state.dirty}
        />
      </div>

      <PublishBar
        title={state.title}
        isHome={docKey === 'default'}
        hasLiveContent={state.hasLiveContent}
        status={state.status}
        dirty={state.dirty}
        saving={saving}
        savedAt={savedAt}
        scheduledAt={state.scheduledAt}
        hasUnpublishedChanges={state.hasUnpublishedChanges}
        canUndo={state.past.length > 0}
        canRedo={state.future.length > 0}
        onUndo={() => dispatch({ type: 'undo' })}
        onRedo={() => dispatch({ type: 'redo' })}
        onSave={() => save(state.sections)}
        onPublish={publish}
        onSchedule={schedule}
        onHistory={() => setHistory(true)}
        onExperiment={() => setExperiment(true)}
        onRevertPublish={revertPublish}
        canRevertPublish={canRevertPublish}
        onExport={exportDocument}
        onImport={() => importInput.current?.click()}
      />

      <input
        ref={importInput}
        type="file"
        accept="application/json,.json"
        className="hidden"
        onChange={(e) => importDocument(e.target.files?.[0])}
      />

      <div className="grid flex-1 grid-cols-[16rem_1fr_20rem] overflow-hidden">
        <aside className="overflow-hidden border-e border-edge bg-surface">
          <SectionTree
            sections={state.sections}
            components={components}
            selectedId={state.selectedId}
            unavailable={state.unavailable}
            canEditSection={canEditSection}
            onSelect={(id) => dispatch({ type: 'select', id })}
            onMove={(from, to) => dispatch({ type: 'move', from, to })}
            onToggle={(id, enable) =>
              dispatch({ type: 'setSection', id, changes: { state: enable ? 'enabled' : 'disabled' } })
            }
            onDuplicate={duplicateSection}
            onRemove={removeSection}
            onAdd={() => setAdding(true)}
            isHome={docKey === 'default'}
          />
        </aside>

        <main className="overflow-hidden">
              <PreviewFrame
            device={device}
            onDevice={setDevice}
            signal={previewSignal}
            docKey={docKey}
            homeUrl={state.previewUrl || boot.homeUrl || '/'}
          />
        </main>

        <aside className="overflow-hidden border-s border-edge bg-surface">
          <Inspector
            section={selected}
            component={component}
            designSchema={designSchema}
            can={serverCan}
            device={device}
            blocked={blocked.filter((entry) => !entry.section || entry.section === selected?.id)}
            readOnly={selected ? !canEditSection(selected.type) : true}
            onField={(path, value) =>
              dispatch({ type: 'setField', id: selected.id, path, value })
            }
            onDesignField={(path, value) =>
              dispatch({ type: 'setDesignField', id: selected.id, path, value })
            }
            onSection={(changes) => dispatch({ type: 'setSection', id: selected.id, changes })}
            onSaveTemplate={() => saveTemplate(selected?.id)}
            onShare={() => shareSection(selected?.id)}
            onDetach={() => detachSection(selected?.id)}
          />
        </aside>
      </div>

      <RevisionsPanel
        open={history}
        onClose={() => setHistory(false)}
        version={state.version}
        onRestored={(result) => {
          dispatch({
            type: 'synced',
            version: result.version,
            status: result.status,
            hasUnpublishedChanges: result.has_unpublished_changes,
            sections: result.sections,
          })
          signalPreview(result.sections, result.version)
        }}
      />

      <ExperimentPanel
        open={experiment}
        onClose={() => setExperiment(false)}
        docKey={docKey}
        documents={documents}
        // A promotion publishes new sections into THIS document, so the editor has to reload or it
        // would keep showing — and next save, write back — the layout that just lost.
        onPromoted={() => setReloadToken((n) => n + 1)}
      />

      <AddSectionPanel
        open={adding}
        onClose={() => setAdding(false)}
        components={components}
        sections={state.sections}
        sectionRights={rights}
        onAdd={addSection}
        version={state.version}
        onSaveHomepageTemplate={() => saveTemplate(null)}
        onTemplateApplied={(result) => {
          dispatch({
            type: 'synced',
            version: result.version,
            status: result.status,
            hasUnpublishedChanges: result.has_unpublished_changes,
            sections: result.sections || undefined,
          })
          if (result.sections) signalPreview(result.sections, result.version)
        }}
      />
    </div>
  )
}
