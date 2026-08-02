/**
 * Administration → Stores.
 *
 * The list every other store screen is reached from. Modelled on access/Roles.jsx, with the search
 * and pagination machinery from access/Users.jsx, so it behaves like the rest of the dashboard
 * rather than like a new thing bolted on.
 */

import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router'

import { storesApi } from '../../api/endpoints.js'
import { Can } from '../../components/Protected.jsx'
import { PageHeader } from '../../components/Layout.jsx'
import { useToast } from '../../context/ToastContext.jsx'
import {
  Alert, Badge, Button, Card, Dropdown, EmptyState, Input, Modal, Pagination,
  SearchInput, Select, SkeletonTable, Table, TBody, TD, TH, THead, TR, useConfirm,
} from '../../components/ui/index.js'
import { Ban, Check, Copy, Plus, Store, Trash2 } from '../../components/ui/icons.js'

/** Status → badge tone. Archived is muted, not danger: it is finished, not broken. */
const TONE = { active: 'ok', suspended: 'warn', draft: 'info', archived: 'muted' }

export default function Stores() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [cloning, setCloning] = useState(null)
  const [cloneSlug, setCloneSlug] = useState('')
  const [cloneName, setCloneName] = useState('')
  const [busy, setBusy] = useState(false)

  const navigate = useNavigate()
  const toast = useToast()
  const [confirm, confirmDialog] = useConfirm()

  const load = useCallback(async () => {
    setError(null)
    try {
      setData(await storesApi.list({ search, status, page, per_page: 20 }))
    } catch (err) {
      setError(err)
    }
  }, [search, status, page])

  // 350ms: long enough not to fire per keystroke, short enough that the table still feels live.
  useEffect(() => {
    const timer = setTimeout(load, search ? 350 : 0)
    return () => clearTimeout(timer)
  }, [load, search])

  /** Run a mutation, report it, and reload. */
  const act = async (fn, okMessage) => {
    setBusy(true)
    try {
      await fn()
      toast.success(okMessage)
      await load()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const onSuspend = async (store) => {
    const ok = await confirm({
      title: `Disable “${store.name}”?`,
      body: 'The store stops answering on its addresses immediately. Nothing is deleted and you can re-enable it at any time.',
      confirmLabel: 'Disable',
      tone: 'danger',
    })
    if (ok) act(() => storesApi.suspend(store.id), `“${store.name}” disabled.`)
  }

  const onArchive = async (store) => {
    const ok = await confirm({
      title: `Archive “${store.name}”?`,
      body: 'Archiving takes the store off the web for good. Its orders, ledgers and audit history are kept — nothing is deleted — but it will no longer appear in the switcher.',
      confirmLabel: 'Archive',
      tone: 'danger',
    })
    if (ok) act(() => storesApi.archive(store.id), `“${store.name}” archived.`)
  }

  const submitClone = async () => {
    setBusy(true)
    try {
      const created = await storesApi.clone(cloning.id, { slug: cloneSlug, name: cloneName })
      toast.success(`Cloned to “${created.name}”. It starts as a draft with no address.`)
      setCloning(null)
      navigate(`/stores/${created.id}`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const rowActions = (store) => [
    { key: 'edit', label: 'Edit', icon: Store, onSelect: () => navigate(`/stores/${store.id}`) },
    {
      key: 'clone',
      label: 'Clone',
      icon: Copy,
      onSelect: () => {
        setCloning(store)
        setCloneSlug(`${store.slug}-copy`)
        setCloneName(`${store.name} (copy)`)
      },
    },
    store.is_active
      ? { key: 'suspend', label: 'Disable', icon: Ban, separatorBefore: true, onSelect: () => onSuspend(store) }
      : { key: 'activate', label: 'Enable', icon: Check, separatorBefore: true, onSelect: () => act(() => storesApi.activate(store.id), `“${store.name}” enabled.`) },
    // An archived store gets no archive entry: the server refuses it and a button that always
    // fails is worse than no button.
    ...(store.status === 'archived'
      ? []
      : [{ key: 'archive', label: 'Archive', icon: Trash2, tone: 'danger', onSelect: () => onArchive(store) }]),
  ]

  const items = data?.items || []

  return (
    <>
      <PageHeader
        title="Stores"
        subtitle="Every storefront this platform serves."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Stores' }]}
        actions={
          <Can perm="stores.create">
            <Button variant="primary" icon={Plus} onClick={() => navigate('/stores/new')}>
              New store
            </Button>
          </Can>
        }
      />

      {error && (
        <Alert tone="danger" title="Could not load stores" onRetry={load} className="mb-4">
          {error.message}
        </Alert>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <SearchInput
          value={search}
          onChange={(value) => { setSearch(value); setPage(1) }}
          placeholder="Search by name or slug…"
          className="w-full sm:w-72"
        />
        <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} className="w-40">
          <option value="">All statuses</option>
          {(data?.statuses || []).map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </Select>
      </div>

      <Card bodyClass="">
        {!data && <SkeletonTable rows={5} cols={5} />}

        {data && items.length === 0 && (
          <EmptyState title="No stores match" icon={Store}>
            {search || status ? 'Try a different search or filter.' : 'Create the first store to get started.'}
          </EmptyState>
        )}

        {data && items.length > 0 && (
          <Table>
            <THead>
              <TR>
                <TH>Store</TH>
                <TH>Status</TH>
                <TH>Currency</TH>
                <TH>Theme</TH>
                <TH align="end">Actions</TH>
              </TR>
            </THead>
            <TBody>
              {items.map((store) => (
                <TR key={store.id}>
                  <TD primary label="Store">
                    <button
                      type="button"
                      className="text-start font-medium hover:underline"
                      onClick={() => navigate(`/stores/${store.id}`)}
                    >
                      {store.name}
                    </button>
                    <div className="text-2xs text-muted">{store.slug}</div>
                  </TD>
                  <TD label="Status">
                    <Badge tone={TONE[store.status] || 'muted'}>{store.status}</Badge>
                  </TD>
                  <TD label="Currency">{store.currency || <span className="text-faint">—</span>}</TD>
                  <TD label="Theme">{store.theme || <span className="text-faint">default</span>}</TD>
                  <TD align="end">
                    <Dropdown label={`Actions for ${store.name}`} align="end" items={rowActions(store)} />
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      {data && (
        <Pagination
          page={data.page}
          pages={data.total_pages}
          total={data.total}
          perPage={20}
          onPage={setPage}
          label="stores"
        />
      )}

      <Modal
        open={Boolean(cloning)}
        onClose={() => setCloning(null)}
        title={cloning ? `Clone “${cloning.name}”` : ''}
        description="The copy takes this store's settings, modules and theme. It gets no address and starts as a draft, so it cannot be reached until you deploy it."
        footer={
          <>
            <Button onClick={() => setCloning(null)}>Cancel</Button>
            <Button variant="primary" icon={Copy} loading={busy} disabled={!cloneSlug || !cloneName} onClick={submitClone}>
              Clone store
            </Button>
          </>
        }
      >
        <div className="grid gap-3">
          <label className="yz-label" htmlFor="clone-name">Name</label>
          <Input id="clone-name" value={cloneName} onChange={(e) => setCloneName(e.target.value)} />
          <label className="yz-label" htmlFor="clone-slug">Slug</label>
          <Input id="clone-slug" value={cloneSlug} onChange={(e) => setCloneSlug(e.target.value)} />
        </div>
      </Modal>

      {confirmDialog}
    </>
  )
}
