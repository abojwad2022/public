/**
 * Role editor — the permission matrix.
 *
 * The screen has one job: make ~150 checkboxes comprehensible. It does that with three affordances
 * the plan called for — Select all, per-module select, and a search that matches module, slug,
 * label and description — plus two that the data makes necessary:
 *
 *   - `planned` permissions (modules this store has not built yet) render disabled with a badge,
 *     rather than being hidden. Hiding them would make the catalog look arbitrary; showing them
 *     inert explains the gap and they light up on their own when the module ships.
 *   - Permissions the CURRENT user does not hold render disabled. You cannot hand out access you
 *     do not have; the server enforces this regardless, so this is only about not offering a
 *     control that is guaranteed to 403.
 *
 * Saving sends the COMPLETE slug list plus the `updated_at` we loaded. The server replaces the set
 * wholesale and 409s if someone else saved in the meantime.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { permissionsApi, rolesApi } from '../../api/endpoints.js'
import { useAuth } from '../../context/AuthContext.jsx'
import { useToast } from '../../context/ToastContext.jsx'
import { PageHeader } from '../../components/Layout.jsx'
import { Can } from '../../components/Protected.jsx'
import {
  Alert,
  Badge,
  Button,
  Card,
  Field,
  Input,
  SearchInput,
  SegmentedControl,
  Skeleton,
  Textarea,
  Tooltip,
} from '../../components/ui/index.js'
import { Crown, Lock, Save, ShieldCheck } from '../../components/ui/icons.js'

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'granted', label: 'Granted' },
  { value: 'planned', label: 'Planned' },
]

/** Does a permission match the search box? Matches slug, label, description and module name. */
function matches(permission, moduleLabel, needle) {
  if (!needle) return true
  const haystack = `${permission.slug} ${permission.label} ${permission.description} ${moduleLabel}`
  return haystack.toLowerCase().includes(needle)
}

export default function RoleEditor() {
  const { id } = useParams()
  const isNew = !id
  const navigate = useNavigate()
  const toast = useToast()
  const { can, refresh } = useAuth()

  const [catalog, setCatalog] = useState(null)
  const [grantable, setGrantable] = useState(null)
  const [role, setRole] = useState(null)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [granted, setGranted] = useState(() => new Set())
  const [dirty, setDirty] = useState(false)

  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState('all')

  // The updated_at we loaded, echoed back on save for optimistic concurrency.
  const loadedAt = useRef('')

  const load = useCallback(async () => {
    setError(null)
    try {
      const [permissions, roleData] = await Promise.all([
        permissionsApi.list(),
        isNew ? Promise.resolve(null) : rolesApi.get(id),
      ])

      setCatalog(permissions.modules)
      setGrantable(new Set(permissions.grantable))

      if (roleData) {
        setRole(roleData)
        setName(roleData.name)
        setDescription(roleData.description)
        setGranted(new Set(roleData.permissions))
        loadedAt.current = roleData.updated_at
      } else {
        // A new role starts with the one permission every role needs to be usable at all.
        setRole({ id: 0, editable: true, is_super: false, is_system: false, permissions: [] })
        setGranted(new Set(['dashboard.access']))
      }
    } catch (err) {
      setError(err)
    }
  }, [id, isNew])

  useEffect(() => {
    load()
  }, [load])

  /*
   * Browsers will not let a SPA show custom text here, but the native prompt is still the only
   * thing that stops a tab close discarding an unsaved permission set.
   */
  useEffect(() => {
    if (!dirty) return undefined
    const onBeforeUnload = (event) => {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [dirty])

  const editable = Boolean(role?.editable) && can(isNew ? 'roles.create' : 'roles.edit')

  /** Can the current user tick this particular box? */
  const isGrantable = useCallback(
    (permission) =>
      editable && permission.status !== 'planned' && Boolean(grantable?.has(permission.slug)),
    [editable, grantable]
  )

  const visible = useMemo(() => {
    if (!catalog) return []
    const needle = query.trim().toLowerCase()

    return catalog
      .map((module) => ({
        ...module,
        permissions: module.permissions.filter((permission) => {
          if (!matches(permission, module.label, needle)) return false
          if (filter === 'granted') return granted.has(permission.slug)
          if (filter === 'planned') return permission.status === 'planned'
          return true
        }),
      }))
      .filter((module) => module.permissions.length > 0)
  }, [catalog, query, filter, granted])

  const totals = useMemo(() => {
    if (!catalog) return { granted: 0, total: 0 }
    const all = catalog.flatMap((m) => m.permissions)
    return { granted: granted.size, total: all.length }
  }, [catalog, granted])

  const mutate = (next) => {
    setGranted(next)
    setDirty(true)
  }

  const toggle = (slug, on) => {
    const next = new Set(granted)
    if (on) next.add(slug)
    else next.delete(slug)
    mutate(next)
  }

  /** Select-all / clear-all, scoped to whatever the search and filter currently show. */
  const setAllVisible = (on) => {
    const next = new Set(granted)
    visible.forEach((module) => {
      module.permissions.forEach((permission) => {
        if (!isGrantable(permission)) return
        if (on) next.add(permission.slug)
        else next.delete(permission.slug)
      })
    })
    mutate(next)
  }

  const setModule = (module, on) => {
    const next = new Set(granted)
    module.permissions.forEach((permission) => {
      if (!isGrantable(permission)) return
      if (on) next.add(permission.slug)
      else next.delete(permission.slug)
    })
    mutate(next)
  }

  const onSave = async () => {
    if (!name.trim()) {
      toast.error('Give the role a name.')
      return
    }

    setSaving(true)
    try {
      const payload = {
        name: name.trim(),
        description: description.trim(),
        permissions: Array.from(granted),
      }

      if (isNew) {
        const created = await rolesApi.create(payload)
        toast.success(`Created “${created.name}”.`)
        setDirty(false)
        navigate(`/roles/${created.id}`, { replace: true })
      } else {
        const saved = await rolesApi.update(id, { ...payload, updated_at: loadedAt.current })
        loadedAt.current = saved.updated_at
        setRole(saved)
        setDirty(false)
        toast.success('Role saved.')
      }

      // Editing a role can change the CURRENT user's own access — re-read it so the sidebar and
      // every <Can> reflect reality immediately rather than at next sign-in.
      await refresh()
    } catch (err) {
      if (err.code === 'yazan_stale') {
        toast.error('This role changed while you were editing. Reload to see the current version.')
      } else if (err.code === 'yazan_escalation') {
        toast.error('You cannot grant a permission you do not hold yourself.')
      } else {
        toast.error(err.message)
      }
    } finally {
      setSaving(false)
    }
  }

  if (error) {
    return (
      <Alert tone="danger" title="Could not load this role" onRetry={load}>
        {error.message}
      </Alert>
    )
  }

  if (!catalog || !role) {
    return (
      <>
        <PageHeader title="Role" />
        <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
          <Skeleton h={220} rounded="md" />
          <Skeleton h={420} rounded="md" />
        </div>
      </>
    )
  }

  return (
    <>
      <PageHeader
        title={isNew ? 'New role' : role.name}
        subtitle={
          role.is_super
            ? 'This role bypasses every permission check. Its permission list is informational.'
            : 'Tick what this role may do. People holding several roles get the union of them.'
        }
        breadcrumbs={[{ label: 'Access' }, { label: 'Roles', to: '/roles' }, { label: isNew ? 'New' : role.name }]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="ghost" onClick={() => navigate('/roles')}>
              Cancel
            </Button>
            <Can perm={isNew ? 'roles.create' : 'roles.edit'} mode="disable">
              <Button
                variant="primary"
                icon={Save}
                loading={saving}
                disabled={!editable || (!dirty && !isNew)}
                onClick={onSave}
              >
                {isNew ? 'Create role' : 'Save changes'}
              </Button>
            </Can>
          </div>
        }
      />

      {!editable && !isNew && (
        <Alert tone="info" title="Read only" className="mb-4">
          This role holds permissions you do not, so it can only be viewed. Ask a super admin to
          change it.
        </Alert>
      )}

      {role.is_super && (
        <Alert tone="gold" title="Super admin role" className="mb-4">
          Holders of this role bypass every permission check, so ticking or unticking boxes below
          does not restrict them. Remove the super-admin flag first if you want it limited.
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[320px_1fr] lg:items-start">
        {/* -------------------------------------------------------- Meta */}
        <Card title="Role details" className="lg:sticky lg:top-4">
          <div className="space-y-4">
            <Field label="Name" required>
              <Input
                value={name}
                onChange={(event) => {
                  setName(event.target.value)
                  setDirty(true)
                }}
                disabled={!editable}
                placeholder="e.g. Warehouse"
              />
            </Field>

            <Field label="Description" help="Shown in the roles list and when assigning people.">
              <Textarea
                rows={3}
                value={description}
                onChange={(event) => {
                  setDescription(event.target.value)
                  setDirty(true)
                }}
                disabled={!editable}
                placeholder="What is this role for?"
              />
            </Field>

            <div className="flex flex-wrap items-center gap-2 border-t border-edge pt-3">
              {role.is_super && (
                <Badge tone="gold" icon={Crown}>
                  Super admin
                </Badge>
              )}
              {role.is_system && (
                <Badge tone="muted" icon={Lock}>
                  Built in
                </Badge>
              )}
              {!isNew && (
                <Badge tone="muted" icon={ShieldCheck}>
                  {role.members ?? 0} {role.members === 1 ? 'person' : 'people'}
                </Badge>
              )}
            </div>

            <p className="text-2xs text-muted tabular-nums">
              {totals.granted} of {totals.total} permissions granted
            </p>
          </div>
        </Card>

        {/* ------------------------------------------------- Permissions */}
        <div className="min-w-0 space-y-3">
          <Card bodyClass="p-3" className="lg:sticky lg:top-4 z-10">
            <div className="flex flex-wrap items-center gap-2">
              <SearchInput
                value={query}
                onChange={setQuery}
                placeholder="Search permissions…"
                className="min-w-[200px] flex-1"
              />
              <SegmentedControl
                items={FILTERS}
                value={filter}
                onChange={setFilter}
                label="Filter permissions"
              />
              <div className="flex items-center gap-1.5">
                <Button small variant="ghost" disabled={!editable} onClick={() => setAllVisible(true)}>
                  Select all
                </Button>
                <Button small variant="ghost" disabled={!editable} onClick={() => setAllVisible(false)}>
                  Clear all
                </Button>
              </div>
            </div>
            {query && (
              <p className="mt-2 text-2xs text-muted">
                Select all applies to the {visible.reduce((n, m) => n + m.permissions.length, 0)}{' '}
                permissions shown.
              </p>
            )}
          </Card>

          {visible.length === 0 && (
            <Card>
              <p className="py-6 text-center text-sm text-muted">
                No permissions match “{query}”.
              </p>
            </Card>
          )}

          {visible.map((module) => (
            <ModuleCard
              key={module.module}
              module={module}
              granted={granted}
              isGrantable={isGrantable}
              onToggle={toggle}
              onModule={setModule}
              editable={editable}
            />
          ))}
        </div>
      </div>
    </>
  )
}

/**
 * One module's permissions.
 *
 * The header checkbox is genuinely tri-state: `indeterminate` is a DOM property with no React
 * attribute, so it has to be written through a ref. Without it, "some ticked" looks identical to
 * "none ticked" and the control lies.
 */
function ModuleCard({ module, granted, isGrantable, onToggle, onModule, editable }) {
  const boxRef = useRef(null)

  const selectable = module.permissions.filter(isGrantable)
  const grantedHere = module.permissions.filter((p) => granted.has(p.slug))
  const all = selectable.length > 0 && selectable.every((p) => granted.has(p.slug))
  const some = grantedHere.length > 0 && !all

  useEffect(() => {
    if (boxRef.current) boxRef.current.indeterminate = some
  }, [some])

  const planned = module.status === 'planned'

  return (
    <Card bodyClass="p-0">
      <header className="flex flex-wrap items-center gap-2 border-b border-edge px-4 py-2.5">
        <label className={`flex items-center gap-2.5 ${editable && selectable.length ? 'cursor-pointer' : 'opacity-60'}`}>
          <input
            ref={boxRef}
            type="checkbox"
            checked={all}
            disabled={!editable || selectable.length === 0}
            onChange={(event) => onModule(module, event.target.checked)}
            className="size-4 shrink-0 rounded-xs accent-[var(--yz-agate)]"
            aria-label={`Select all ${module.label} permissions`}
          />
          <span className="text-sm font-medium text-fg">{module.label}</span>
        </label>

        {planned && (
          <Tooltip label="This module has not shipped yet. Grants are stored now and start working the day it does.">
            <Badge tone="muted">Planned</Badge>
          </Tooltip>
        )}

        <span className="ms-auto text-2xs tabular-nums text-muted">
          {grantedHere.length}/{module.permissions.length}
        </span>
      </header>

      <div className="grid gap-x-4 gap-y-2.5 p-4 sm:grid-cols-2 xl:grid-cols-3">
        {module.permissions.map((permission) => {
          const allowed = isGrantable(permission)
          const blockedByOwnAccess = editable && !allowed && permission.status !== 'planned'

          const box = (
            <label
              key={permission.slug}
              className={`flex items-start gap-2.5 ${allowed ? 'cursor-pointer' : 'cursor-default opacity-60'}`}
            >
              <input
                type="checkbox"
                checked={granted.has(permission.slug)}
                disabled={!allowed}
                onChange={(event) => onToggle(permission.slug, event.target.checked)}
                className="mt-0.5 size-4 shrink-0 rounded-xs accent-[var(--yz-agate)]"
              />
              <span className="min-w-0">
                <span className="block text-sm text-fg">{permission.label}</span>
                {permission.description && (
                  <span className="mt-0.5 block text-2xs leading-relaxed text-muted">
                    {permission.description}
                  </span>
                )}
                <code className="mt-1 block font-mono text-2xs text-faint">{permission.slug}</code>
              </span>
            </label>
          )

          if (blockedByOwnAccess) {
            return (
              <Tooltip key={permission.slug} label="You cannot grant a permission you do not hold">
                {box}
              </Tooltip>
            )
          }
          return box
        })}
      </div>
    </Card>
  )
}
