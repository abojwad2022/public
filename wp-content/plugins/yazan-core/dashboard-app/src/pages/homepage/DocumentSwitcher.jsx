/**
 * Which layout is being edited: the homepage, or one of the landing pages.
 *
 * The homepage is pinned to the top and can never be deleted or re-bound, because it is the one
 * layout whose destination is not a choice.
 */
import { useCallback, useEffect, useState } from 'react'
import { homepageApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Button, Field, Input, Modal, Select, Icons, IconButton } from '../../components/ui/index.js'
import { Can } from '../../components/Protected.jsx'

export default function DocumentSwitcher({ docKey, onSwitch, onDocumentChanged, dirty }) {
  const toast = useToast()
  const [documents, setDocuments] = useState([])
  const [pages, setPages] = useState([])
  const [managing, setManaging] = useState(false)
  const [creating, setCreating] = useState({ key: '', title: '' })

  const load = useCallback(async () => {
    try {
      const data = await homepageApi.documents()
      setDocuments(data.documents || [])
    } catch (err) {
      if (err.code !== 'yazan_homepage_forbidden') toast.error(err.message)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    if (!managing) return
    homepageApi
      .pages()
      .then((data) => setPages(data.pages || []))
      .catch(() => setPages([]))
  }, [managing])

  const switchTo = (next) => {
    if (next === docKey) return

    // Switching throws away anything unsaved, because the editor holds one document at a time.
    // Asking is cheaper than an apology.
    // eslint-disable-next-line no-alert
    if (dirty && !window.confirm('You have unsaved changes. Switch anyway and lose them?')) {
      return
    }

    onSwitch(next)
  }

  const create = async () => {
    try {
      const made = await homepageApi.createDocument(creating)
      setCreating({ key: '', title: '' })
      await load()
      onSwitch(made.key)
      setManaging(false)
      toast.success('Page layout created. It is empty until you add sections and publish.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  const bind = async (key, page) => {
    try {
      await homepageApi.bindDocument(key, { page: Number(page) || 0 })
      await load()

      // The open editor is holding a copy of this document, and its preview URL just changed.
      // Telling it to refetch is cheaper than leaving the operator previewing the wrong page.
      if (key === docKey) onDocumentChanged?.()

      toast.success(page ? 'Bound to that page.' : 'Detached. The page shows its own content again.')
    } catch (err) {
      toast.error(err.message)
    }
  }

  const remove = async (key) => {
    // eslint-disable-next-line no-alert
    if (!window.confirm('Delete this page layout and its history? The WordPress page itself is not touched.')) {
      return
    }

    try {
      await homepageApi.removeDocument(key)
      await load()
      if (key === docKey) onSwitch('default')
    } catch (err) {
      toast.error(err.message)
    }
  }

  return (
    <>
      <div className="flex items-center gap-1">
        <Select value={docKey} onChange={(e) => switchTo(e.target.value)} aria-label="Layout">
          {documents.map((entry) => (
            <option key={entry.doc_key} value={entry.doc_key}>
              {entry.doc_key === 'default' ? 'Homepage' : entry.title || entry.doc_key}
            </option>
          ))}
        </Select>

        <Can perm="homepage.settings">
          <IconButton label="Manage page layouts" onClick={() => setManaging(true)} icon={Icons.SlidersHorizontal} />
        </Can>
      </div>

      <Modal open={managing} onClose={() => setManaging(false)} title="Page layouts" size="lg">
        <div className="space-y-4">
          <p className="text-2xs text-muted">
            A page layout is this same builder pointed at a WordPress page. The page keeps its own
            content until a layout bound to it has something published.
          </p>

          <ul className="space-y-2">
            {documents.map((entry) => (
              <li key={entry.doc_key} className="rounded border border-edge p-3">
                <div className="flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-sm text-fg">
                      {entry.doc_key === 'default' ? 'Homepage' : entry.title || entry.doc_key}
                    </p>
                    <p className="text-2xs text-faint">
                      {entry.doc_key === 'default'
                        ? 'Always the front page'
                        : entry.page
                          ? `Bound to “${entry.page}”`
                          : 'Not bound to a page yet'}
                    </p>
                  </div>

                  {entry.doc_key === 'default' ? null : (
                    <div className="flex shrink-0 items-center gap-1">
                      <Select
                        value={String(entry.bound_page_id || 0)}
                        onChange={(e) => bind(entry.doc_key, e.target.value)}
                        aria-label="Bind to page"
                      >
                        <option value="0">Not bound</option>
                        {pages.map((page) => (
                          <option key={page.id} value={page.id}>
                            {page.title}
                          </option>
                        ))}
                      </Select>
                      <IconButton label="Delete layout" onClick={() => remove(entry.doc_key)} icon={Icons.Trash2} />
                    </div>
                  )}
                </div>
              </li>
            ))}
          </ul>

          <div className="rounded border border-divider p-3">
            <p className="mb-2 text-2xs font-medium uppercase tracking-wide text-faint">New layout</p>
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label="Name">
                <Input
                  value={creating.title}
                  placeholder="Eid campaign"
                  onChange={(e) => setCreating({ ...creating, title: e.target.value })}
                />
              </Field>
              <Field label="Key" help="Lowercase letters, numbers and dashes. Cannot be changed later.">
                <Input
                  value={creating.key}
                  placeholder="eid-campaign"
                  onChange={(e) =>
                    setCreating({
                      ...creating,
                      key: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'),
                    })
                  }
                />
              </Field>
            </div>
            <Button onClick={create} disabled={!creating.key || !creating.title}>
              Create
            </Button>
          </div>
        </div>

        <div className="mt-4 flex justify-end">
          <Button variant="secondary" onClick={() => setManaging(false)}>
            Close
          </Button>
        </div>
      </Modal>
    </>
  )
}
