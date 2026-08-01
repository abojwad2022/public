import { useCallback, useEffect, useState } from 'react'
import { attributesApi, termsApi } from '../api/endpoints.js'
import { useAuth } from '../context/AuthContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  Field,
  IconButton,
  Input,
  Modal,
  Skeleton,
  SkeletonText,
  Select,
} from '../components/ui/index.js'
import { Pencil, Plus, Tags, Trash2 } from '../components/ui/icons.js'

export default function Attributes() {
  const toast = useToast()
  const { can } = useAuth()
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(null)
  const [termsFor, setTermsFor] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [busy, setBusy] = useState(false)

  const canManage = can('attributes.edit')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setItems((await attributesApi.list()).items)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const remove = (attribute) => {
    const count = (attribute.terms || []).length
    setConfirm({
      title: `Delete “${attribute.name}”?`,
      body: `This also deletes its ${count} term${count === 1 ? '' : 's'} and unlinks the attribute from every product. This cannot be undone.`,
      onConfirm: async () => {
        setBusy(true)
        try {
          await attributesApi.remove(attribute.id)
          toast.success('Attribute deleted.')
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

  return (
    <>
      <PageHeader
        title="Attributes"
        subtitle="Global product attributes and their terms"
        breadcrumbs={[{ label: 'Catalog' }, { label: 'Attributes' }]}
        actions={
          canManage && (
            <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
              Add attribute
            </Button>
          )
        }
      />

      {!canManage && (
        <Alert tone="info" className="mb-4">
          You can manage terms, but creating or deleting attributes themselves needs the{' '}
          <code className="font-mono text-xs">manage_woocommerce</code > capability.
        </Alert>
      )}

      {loading ? (
        <div className="space-y-4">
          {[0, 1, 2].map((i) => (
            <Card key={i}>
              <Skeleton w="30%" h={14} />
              <SkeletonText lines={2} className="mt-3" />
            </Card>
          ))}
        </div>
      ) : items.length === 0 ? (
        <Card>
          <EmptyState
            title="No attributes yet"
            icon={Tags}
            action={
              canManage ? (
                <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                  Add your first attribute
                </Button>
              ) : null
            }
          >
            Attributes describe your rings — stone, ring size, finish — and power the store's filters.
          </EmptyState>
        </Card>
      ) : (
        <div className="space-y-4">
          {items.map((attribute) => (
            <Card
              key={attribute.taxonomy}
              title={attribute.name}
              actions={
                <div className="flex flex-wrap items-center gap-1.5">
                  {attribute.jewelry ? (
                    <Badge tone="gold">Jewelry field</Badge>
                  ) : (
                    <Badge>{attribute.taxonomy}</Badge>
                  )}
                  <Button size="sm" variant="quiet" onClick={() => setTermsFor(attribute)}>
                    Manage terms
                  </Button>
                  {canManage && (
                    <>
                      <IconButton
                        icon={Pencil}
                        label={`Edit ${attribute.name}`}
                        size="sm"
                        onClick={() => setEditing(attribute)}
                      />
                      <IconButton
                        icon={Trash2}
                        label={
                          attribute.jewelry
                            ? `${attribute.name} is protected and cannot be deleted`
                            : `Delete ${attribute.name}`
                        }
                        size="sm"
                        onClick={() => remove(attribute)}
                        disabled={attribute.jewelry}
                      />
                    </>
                  )}
                </div>
              }
            >
              <div className="flex flex-wrap gap-1.5">
                {(attribute.terms || []).length === 0 ? (
                  <span className="text-sm text-muted">No terms yet.</span>
                ) : (
                  (attribute.terms || []).map((term) => (
                    <Badge key={term.id}>
                      {term.name}
                      <span className="yz-num ms-1 text-faint">{term.count}</span>
                    </Badge>
                  ))
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      {editing && (
        <AttributeEditor
          attribute={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            load()
          }}
        />
      )}

      {termsFor && (
        <TermManager
          attribute={termsFor}
          onClose={() => {
            setTermsFor(null)
            load()
          }}
        />
      )}

      <ConfirmDialog
        open={Boolean(confirm)}
        title={confirm?.title}
        confirmLabel="Delete attribute"
        busy={busy}
        onCancel={() => setConfirm(null)}
        onConfirm={() => confirm?.onConfirm?.()}
      >
        {confirm?.body}
      </ConfirmDialog>
    </>
  )
}

/* -------------------------------------------------- attribute definition */

function AttributeEditor({ attribute, onClose, onSaved }) {
  const toast = useToast()
  const isNew = !attribute.id
  const [name, setName] = useState(attribute.name || '')
  const [slug, setSlug] = useState(attribute.slug || '')
  const [type, setType] = useState(attribute.type || 'select')
  const [orderBy, setOrderBy] = useState(attribute.order_by || 'menu_order')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const submit = async () => {
    if (!name.trim()) {
      setError('A name is required.')
      return
    }
    setError('')
    setSaving(true)
    try {
      if (isNew) {
        await attributesApi.create({ name, slug, type, order_by: orderBy })
        toast.success('Attribute created.')
      } else {
        await attributesApi.update(attribute.id, { name, type, order_by: orderBy })
        toast.success('Attribute updated.')
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
      title={isNew ? 'New attribute' : 'Edit attribute'}
      description={isNew ? undefined : attribute.name}
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

        <Field
          label="Slug"
          help={
            isNew
              ? 'Becomes pa_{slug}. Max 28 characters.'
              : 'The slug cannot be changed — renaming it would unlink every product.'
          }
        >
          <Input value={slug} maxLength={28} disabled={!isNew} onChange={(e) => setSlug(e.target.value)} />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Type">
            <Select value={type} onChange={(e) => setType(e.target.value)}>
              <option value="select">Select</option>
              <option value="text">Text</option>
            </Select>
          </Field>
          <Field label="Sort terms by">
            <Select value={orderBy} onChange={(e) => setOrderBy(e.target.value)}>
              <option value="menu_order">Custom order</option>
              <option value="name">Name</option>
              <option value="name_num">Name (numeric)</option>
              <option value="id">Term ID</option>
            </Select>
          </Field>
        </div>
      </div>
    </Modal>
  )
}

/* ------------------------------------------------------- attribute terms */

function TermManager({ attribute, onClose }) {
  const toast = useToast()
  const [terms, setTerms] = useState([])
  const [loading, setLoading] = useState(true)
  const [newName, setNewName] = useState('')
  const [busy, setBusy] = useState(false)
  const [renaming, setRenaming] = useState(null)
  const [confirm, setConfirm] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setTerms((await termsApi.list(attribute.taxonomy)).items)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [attribute.taxonomy, toast])

  useEffect(() => {
    load()
  }, [load])

  const add = async () => {
    if (!newName.trim()) return
    setBusy(true)
    try {
      await termsApi.create(attribute.taxonomy, { name: newName })
      setNewName('')
      toast.success('Term added.')
      load()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const rename = async (term, name) => {
    if (!name.trim() || name === term.name) {
      setRenaming(null)
      return
    }
    try {
      await termsApi.update(attribute.taxonomy, term.id, { name })
      toast.success('Term renamed.')
      load()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setRenaming(null)
    }
  }

  const remove = (term) => {
    setConfirm({
      title: `Delete “${term.name}”?`,
      body:
        term.count > 0
          ? `${term.count} product${term.count === 1 ? '' : 's'} use this value and will lose it. This cannot be undone.`
          : 'This cannot be undone.',
      onConfirm: async () => {
        setBusy(true)
        try {
          await termsApi.remove(attribute.taxonomy, term.id)
          toast.success('Term deleted.')
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

  return (
    <>
      <Modal
        open
        onClose={onClose}
        title="Manage terms"
        description={attribute.name}
        footer={
          <Button variant="primary" onClick={onClose}>
            Done
          </Button>
        }
      >
        <div className="mb-4 flex gap-2">
          <Input
            value={newName}
            placeholder="New term name…"
            aria-label="New term name"
            onChange={(e) => setNewName(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && add()}
            data-autofocus
          />
          <Button variant="primary" icon={Plus} onClick={add} loading={busy} disabled={!newName.trim()}>
            Add
          </Button>
        </div>

        {loading ? (
          <SkeletonText lines={4} />
        ) : terms.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted">No terms yet.</p>
        ) : (
          <ul className="yz-scroll-y max-h-[50vh] divide-y divide-divider overflow-y-auto">
            {terms.map((term) => (
              <li key={term.id} className="flex items-center gap-2 py-2">
                {renaming === term.id ? (
                  <Input
                    defaultValue={term.name}
                    autoFocus
                    aria-label={`Rename ${term.name}`}
                    onBlur={(e) => rename(term, e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') rename(term, e.target.value)
                      if (e.key === 'Escape') setRenaming(null)
                    }}
                  />
                ) : (
                  <>
                    <span className="min-w-0 flex-1">
                      <span className="text-sm text-fg">{term.name}</span>
                      <span className="block font-mono text-xs text-faint">{term.slug}</span>
                    </span>
                    <span className="yz-num text-xs text-faint">{term.count} used</span>
                    <IconButton
                      icon={Pencil}
                      label={`Rename ${term.name}`}
                      size="sm"
                      onClick={() => setRenaming(term.id)}
                    />
                    <IconButton
                      icon={Trash2}
                      label={`Delete ${term.name}`}
                      size="sm"
                      onClick={() => remove(term)}
                    />
                  </>
                )}
              </li>
            ))}
          </ul>
        )}
      </Modal>

      <ConfirmDialog
        open={Boolean(confirm)}
        title={confirm?.title}
        confirmLabel="Delete term"
        busy={busy}
        onCancel={() => setConfirm(null)}
        onConfirm={() => confirm?.onConfirm?.()}
      >
        {confirm?.body}
      </ConfirmDialog>
    </>
  )
}
