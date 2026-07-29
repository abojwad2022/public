import { useCallback, useEffect, useState } from 'react'
import { termsApi } from '../api/endpoints.js'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import {
  Badge,
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  Field,
  Input,
  Modal,
  Select,
  SkeletonTable,
  Table,
  Tabs,
  TBody,
  TD,
  TH,
  THead,
  TR,
  Textarea,
} from '../components/ui/index.js'
import { FolderTree, ImagePlus, Pencil, Plus, Trash2 } from '../components/ui/icons.js'
import MediaPicker from '../components/media/MediaPicker.jsx'

/** The three term groups this screen manages, all through the same /terms endpoint. */
const TABS = [
  { key: 'product_cat', label: 'Product categories', hierarchical: true, images: true },
  { key: 'collection', label: 'Collections', hierarchical: false, images: false },
  { key: 'product_tag', label: 'Tags', hierarchical: false, images: false },
]

export default function Categories() {
  const toast = useToast()
  const [tabKey, setTabKey] = useState(TABS[0].key)
  const [terms, setTerms] = useState([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(null) // term object, or {} for "new"
  const [confirm, setConfirm] = useState(null)
  const [busy, setBusy] = useState(false)

  const tab = TABS.find((entry) => entry.key === tabKey) || TABS[0]

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await termsApi.list(tab.key)
      setTerms(data.items)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [tab.key, toast])

  useEffect(() => {
    load()
  }, [load])

  const remove = (term) => {
    setConfirm({
      title: `Delete “${term.name}”?`,
      // The consequence is stated up front rather than buried in a \n\n of a
      // browser alert — losing a term on live products is worth a clear sentence.
      body:
        term.count > 0
          ? `${term.count} product${term.count === 1 ? '' : 's'} use this term and will lose it. This cannot be undone.`
          : 'This cannot be undone.',
      onConfirm: async () => {
        setBusy(true)
        try {
          const result = await termsApi.remove(tab.key, term.id)
          toast.success(
            result.children_moved > 0
              ? `Deleted. ${result.children_moved} sub-categor${result.children_moved === 1 ? 'y was' : 'ies were'} moved up a level.`
              : 'Term deleted.',
          )
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

  // Order hierarchically: parents followed by their children.
  const ordered = tab.hierarchical ? sortTree(terms) : terms

  return (
    <>
      <PageHeader
        title="Categories"
        subtitle="Organise the catalogue — categories, collections and tags"
        breadcrumbs={[{ label: 'Catalog' }, { label: 'Categories' }]}
        actions={
          <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
            Add {tab.hierarchical ? 'category' : 'term'}
          </Button>
        }
      />

      <Card bodyClass="p-0">
        <Tabs
          items={TABS.map((entry) => ({ key: entry.key, label: entry.label }))}
          value={tabKey}
          onChange={setTabKey}
        />

        {loading ? (
          <SkeletonTable rows={7} cols={4} />
        ) : ordered.length === 0 ? (
          <EmptyState
            title="No terms yet"
            icon={FolderTree}
            action={
              <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                Add {tab.hierarchical ? 'category' : 'term'}
              </Button>
            }
          >
            Terms you add here organise the catalogue and appear on the store.
          </EmptyState>
        ) : (
          <Table>
            <THead>
              <TR>
                {tab.images && (
                  <TH className="w-12">
                    <span className="sr-only">Image</span>
                  </TH>
                )}
                <TH>Name</TH>
                <TH>Slug</TH>
                <TH align="end" className="w-24">
                  Products
                </TH>
                <TH align="end" className="w-32">
                  Actions
                </TH>
              </TR>
            </THead>
            <TBody>
              {ordered.map((term) => (
                <TR key={term.id}>
                  {tab.images && (
                    <TD>
                      {term.image_url ? (
                        <img
                          src={term.image_url}
                          alt=""
                          className="size-9 rounded-sm border border-edge object-cover"
                        />
                      ) : (
                        <div className="size-9 rounded-sm border border-edge bg-sunken" />
                      )}
                    </TD>
                  )}
                  <TD>
                    {/* Logical padding so the hierarchy indents from the correct
                        side in Arabic. */}
                    <span
                      className="flex items-center gap-2"
                      style={term.depth ? { paddingInlineStart: `${term.depth * 1.25}rem` } : undefined}
                    >
                      {term.depth ? <span className="text-faint">—</span> : null}
                      <span className="text-fg">{term.name}</span>
                      {term.is_default && <Badge tone="gold">Default</Badge>}
                    </span>
                    {term.description && (
                      <span className="mt-0.5 block text-xs text-faint">{term.description}</span>
                    )}
                  </TD>
                  <TD className="font-mono text-xs text-muted">{term.slug}</TD>
                  <TD align="end" className="yz-num text-muted">
                    {term.count}
                  </TD>
                  <TD align="end" className="whitespace-nowrap">
                    <span className="inline-flex items-center justify-end gap-1">
                      <Button size="sm" variant="quiet" icon={Pencil} onClick={() => setEditing(term)}>
                        Edit
                      </Button>
                      <Button
                        size="sm"
                        variant="quiet"
                        icon={Trash2}
                        onClick={() => remove(term)}
                        disabled={term.is_default}
                        title={term.is_default ? 'The default category cannot be deleted' : undefined}
                      >
                        Delete
                      </Button>
                    </span>
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      {editing && (
        <TermEditor
          tab={tab}
          term={editing}
          allTerms={terms}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            load()
          }}
        />
      )}

      <ConfirmDialog
        open={Boolean(confirm)}
        title={confirm?.title}
        confirmLabel="Delete"
        busy={busy}
        onCancel={() => setConfirm(null)}
        onConfirm={() => confirm?.onConfirm?.()}
      >
        {confirm?.body}
      </ConfirmDialog>
    </>
  )
}

function TermEditor({ tab, term, allTerms, onClose, onSaved }) {
  const toast = useToast()
  const isNew = !term.id
  const [name, setName] = useState(term.name || '')
  const [slug, setSlug] = useState(term.slug || '')
  const [parent, setParent] = useState(term.parent || 0)
  const [description, setDescription] = useState(term.description || '')
  const [imageId, setImageId] = useState(term.image_id || 0)
  const [imageUrl, setImageUrl] = useState(term.image_url || '')
  const [picking, setPicking] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  // A term may not be its own parent, nor sit under one of its own descendants.
  const descendants = isNew ? [] : collectDescendants(allTerms, term.id)
  const parentOptions = allTerms.filter((t) => t.id !== term.id && !descendants.includes(t.id))

  const submit = async () => {
    if (!name.trim()) {
      // Shown on the field itself rather than as a toast that floats away from
      // the input it is talking about.
      setError('A name is required.')
      return
    }
    setError('')
    setSaving(true)
    const payload = { name, slug, description }
    if (tab.hierarchical) payload.parent = parent
    if (tab.images) payload.image_id = imageId

    try {
      if (isNew) {
        await termsApi.create(tab.key, payload)
        toast.success('Term created.')
      } else {
        await termsApi.update(tab.key, term.id, payload)
        toast.success('Term updated.')
      }
      onSaved()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={isNew ? `New ${tab.label.toLowerCase()}` : 'Edit term'}
      description={isNew ? undefined : term.name}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" onClick={submit} loading={saving}>
            {isNew ? 'Create' : 'Save'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Name" required error={error}>
          <Input value={name} onChange={(e) => setName(e.target.value)} data-autofocus />
        </Field>

        <Field label="Slug" help="Leave blank to generate it from the name.">
          <Input value={slug} onChange={(e) => setSlug(e.target.value)} />
        </Field>

        {tab.hierarchical && (
          <Field label="Parent">
            <Select value={parent} onChange={(e) => setParent(Number(e.target.value))}>
              <option value={0}>— None (top level) —</option>
              {parentOptions.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </Select>
          </Field>
        )}

        <Field label="Description" help="Used for the Arabic name on Yazan categories.">
          <Textarea rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        </Field>

        {tab.images && (
          <div>
            <span className="yz-label">Image</span>
            {imageUrl ? (
              <div className="flex items-center gap-3">
                <img src={imageUrl} alt="" className="size-16 rounded-md border border-edge object-cover" />
                <Button size="sm" onClick={() => setPicking(true)}>
                  Replace
                </Button>
                <Button
                  size="sm"
                  variant="danger"
                  onClick={() => {
                    setImageId(0)
                    setImageUrl('')
                  }}
                >
                  Remove
                </Button>
              </div>
            ) : (
              <Button size="sm" icon={ImagePlus} onClick={() => setPicking(true)}>
                Choose image
              </Button>
            )}
            <MediaPicker
              open={picking}
              onClose={() => setPicking(false)}
              onSelect={([item]) => {
                setImageId(item.id)
                setImageUrl(item.thumb || item.url)
              }}
            />
          </div>
        )}
      </div>
    </Modal>
  )
}

/* ------------------------------------------------------------------ utils */

/** Flatten a parent/child term list into display order, tagging each with its depth. */
function sortTree(terms, parent = 0, depth = 0) {
  const out = []
  terms
    .filter((t) => t.parent === parent)
    .forEach((t) => {
      out.push({ ...t, depth })
      out.push(...sortTree(terms, t.id, depth + 1))
    })
  // Any term whose parent is missing (orphan) still needs to appear.
  if (parent === 0) {
    const seen = new Set(out.map((t) => t.id))
    terms.filter((t) => !seen.has(t.id)).forEach((t) => out.push({ ...t, depth: 0 }))
  }
  return out
}

function collectDescendants(terms, id) {
  const direct = terms.filter((t) => t.parent === id).map((t) => t.id)
  return direct.reduce((acc, childId) => [...acc, childId, ...collectDescendants(terms, childId)], [])
}
