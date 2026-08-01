/**
 * Users — the staff list.
 *
 * "Staff" means people who hold a Yazan role and can sign into this dashboard. Shoppers live on
 * the Customers screen; the server refuses to return them here at all, so this table can never
 * accidentally become a customer-data leak.
 *
 * Real WordPress administrators appear even without a Yazan role, badged as such and read-only for
 * anyone who is not a super admin — they can do everything regardless, and a staff screen that
 * hides them would be lying about who has access.
 */
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { usersApi } from '../../api/endpoints.js'
import { useAuth } from '../../context/AuthContext.jsx'
import { useToast } from '../../context/ToastContext.jsx'
import { PageHeader } from '../../components/Layout.jsx'
import { Can } from '../../components/Protected.jsx'
import {
  Alert,
  Badge,
  Button,
  Card,
  Dropdown,
  EmptyState,
  Pagination,
  SearchInput,
  Select,
  SkeletonTable,
  Table,
  TBody,
  TD,
  TH,
  THead,
  Tooltip,
  TR,
  useConfirm,
} from '../../components/ui/index.js'
import {
  Ban,
  Crown,
  KeyRound,
  LogOut,
  Pencil,
  Plus,
  ScrollText,
  Trash2,
  UserCheck,
  UserCog,
} from '../../components/ui/icons.js'

const PER_PAGE = 20

/** "Never" beats an empty cell — it is a fact, not a missing value. */
function formatWhen(value) {
  if (!value) return null
  const date = new Date(value.replace(' ', 'T'))
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

export default function Users() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [role, setRole] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const navigate = useNavigate()
  const toast = useToast()
  const [confirm, confirmDialog] = useConfirm()
  const { user: me, refresh } = useAuth()

  // 350ms, matching the Customers screen — long enough not to fire per keystroke, short enough
  // that the table still feels live.
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(search)
      setPage(1)
    }, 350)
    return () => clearTimeout(timer)
  }, [search])

  const load = useCallback(async () => {
    setError(null)
    try {
      const result = await usersApi.list({
        search: debounced,
        role,
        status,
        page,
        per_page: PER_PAGE,
      })
      setData(result)
    } catch (err) {
      setError(err)
    }
  }, [debounced, role, status, page])

  useEffect(() => {
    load()
  }, [load])

  const roles = useMemo(() => data?.roles || [], [data])

  const act = async (label, fn, { reloadSelf = false } = {}) => {
    try {
      await fn()
      toast.success(label)
      if (reloadSelf) await refresh()
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const onSuspend = async (staff) => {
    const ok = await confirm({
      title: `Suspend ${staff.name}?`,
      body: 'They will be signed out on every device immediately and cannot sign in again until reactivated. Nothing is deleted.',
      confirmLabel: 'Suspend',
      tone: 'danger',
    })
    if (ok) act('Account suspended.', () => usersApi.suspend(staff.id))
  }

  const onDelete = async (staff) => {
    const ok = await confirm({
      title: `Delete ${staff.name}?`,
      body: 'The account is removed permanently. Their entries in the activity log are kept but anonymised. This cannot be undone.',
      confirmLabel: 'Delete account',
      tone: 'danger',
    })
    if (ok) act('Account deleted.', () => usersApi.remove(staff.id))
  }

  const onResetPassword = async (staff) => {
    const ok = await confirm({
      title: `Send a reset link to ${staff.name}?`,
      body: `WordPress will email ${staff.email} a link to set a new password. You will never see the password.`,
      confirmLabel: 'Send link',
      tone: 'agate',
    })
    if (ok) act('Reset link sent.', () => usersApi.resetPassword(staff.id, 'link'))
  }

  const onForceLogout = async (staff) => {
    const ok = await confirm({
      title: `Sign ${staff.name} out everywhere?`,
      body: 'Every active session on every device ends immediately. They can sign back in straight away.',
      confirmLabel: 'Force logout',
      tone: 'danger',
    })
    if (ok) act('Sessions ended.', () => usersApi.forceLogout(staff.id))
  }

  return (
    <>
      <PageHeader
        title="Users"
        subtitle="People who can sign into this dashboard. Shoppers are on the Customers screen."
        breadcrumbs={[{ label: 'Access' }, { label: 'Users' }]}
        actions={
          <Can perm="users.create">
            <Button variant="primary" icon={Plus} onClick={() => navigate('/users/new')}>
              New user
            </Button>
          </Can>
        }
      />

      {error && (
        <Alert tone="danger" title="Could not load users" onRetry={load} className="mb-4">
          {error.message}
        </Alert>
      )}

      <Card bodyClass="p-3">
        <div className="flex flex-wrap items-center gap-2">
          <SearchInput
            value={search}
            onChange={setSearch}
            placeholder="Search name or email…"
            className="min-w-[200px] flex-1"
          />
          <Select
            value={role}
            onChange={(event) => {
              setRole(event.target.value)
              setPage(1)
            }}
            aria-label="Filter by role"
          >
            <option value="">All roles</option>
            {roles.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </Select>
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
            aria-label="Filter by status"
          >
            <option value="">Any status</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
          </Select>
        </div>
      </Card>

      <Card bodyClass="" className="mt-3">
        {!data && <SkeletonTable rows={8} cols={5} />}

        {data && data.items.length === 0 && (
          <EmptyState title="No users match" icon={UserCog}>
            Try a different search, or create a staff account.
          </EmptyState>
        )}

        {data && data.items.length > 0 && (
          <>
            <Table>
              <THead>
                <TR>
                  <TH>Name</TH>
                  <TH>Roles</TH>
                  <TH>Status</TH>
                  <TH>Last login</TH>
                  <TH>Created</TH>
                  <TH className="w-10" />
                </TR>
              </THead>
              <TBody>
                {data.items.map((staff) => (
                  <TR key={staff.id}>
                    <TD label="Name" primary>
                      <div className="flex items-center gap-2.5">
                        <img
                          src={staff.avatar}
                          alt=""
                          width={32}
                          height={32}
                          loading="lazy"
                          className="size-8 shrink-0 rounded-full bg-surface2 object-cover"
                        />
                        <div className="min-w-0">
                          <Link
                            to={`/users/${staff.id}`}
                            className="block truncate font-medium text-fg transition-colors hover:text-agate"
                          >
                            {staff.name}
                            {staff.id === me?.id && (
                              <span className="ms-1.5 text-2xs font-normal text-faint">(you)</span>
                            )}
                          </Link>
                          <span className="block truncate text-2xs text-muted">{staff.email}</span>
                        </div>
                      </div>
                    </TD>

                    <TD label="Roles">
                      <div className="flex flex-wrap gap-1">
                        {staff.is_wp_admin && (
                          <Tooltip label="A WordPress administrator. Can do everything regardless of Yazan roles.">
                            <Badge tone="gold" icon={Crown}>
                              WP admin
                            </Badge>
                          </Tooltip>
                        )}
                        {staff.roles.map((r) => (
                          <Badge key={r.id} tone={r.is_super ? 'gold' : 'muted'}>
                            {r.name}
                          </Badge>
                        ))}
                        {!staff.roles.length && !staff.is_wp_admin && (
                          <span className="text-2xs text-faint">None</span>
                        )}
                      </div>
                    </TD>

                    <TD label="Status">
                      {staff.status === 'suspended' ? (
                        <Badge tone="warn" icon={Ban}>
                          Suspended
                        </Badge>
                      ) : (
                        <Badge tone="ok" dot>
                          Active
                        </Badge>
                      )}
                    </TD>

                    <TD label="Last login" className="text-muted">
                      {formatWhen(staff.last_login) || <span className="text-faint">Never</span>}
                    </TD>

                    <TD label="Created" className="text-muted">
                      {formatWhen(staff.created_at)}
                    </TD>

                    <TD align="end">
                      <Dropdown
                        label={`Actions for ${staff.name}`}
                        items={rowActions({
                          staff,
                          me,
                          navigate,
                          onSuspend,
                          onDelete,
                          onResetPassword,
                          onForceLogout,
                          act,
                        })}
                      />
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>

            <Pagination
              page={data.page}
              pages={data.total_pages}
              total={data.total}
              perPage={PER_PAGE}
              onPage={setPage}
              label="users"
            />
          </>
        )}
      </Card>

      {confirmDialog}
    </>
  )
}

/**
 * Row menu.
 *
 * Entries are omitted rather than disabled when the server would refuse them outright — an
 * un-editable WP administrator, or an action on your own account. The exception is Force logout on
 * yourself, which is genuinely useful (it clears a session left open on another device) and the
 * server keeps the current one alive.
 */
function rowActions({ staff, me, navigate, onSuspend, onDelete, onResetPassword, onForceLogout, act }) {
  const isSelf = staff.id === me?.id
  const items = [
    { key: 'edit', label: 'Edit', icon: Pencil, onSelect: () => navigate(`/users/${staff.id}`) },
    {
      key: 'activity',
      label: 'View activity',
      icon: ScrollText,
      onSelect: () => navigate(`/users/${staff.id}?tab=activity`),
    },
  ]

  if (!staff.editable) return items

  items.push({
    key: 'password',
    label: 'Send password reset',
    icon: KeyRound,
    separatorBefore: true,
    onSelect: () => onResetPassword(staff),
  })

  items.push({
    key: 'logout',
    label: isSelf ? 'Sign out other devices' : 'Force logout',
    icon: LogOut,
    onSelect: () => onForceLogout(staff),
  })

  if (!isSelf) {
    items.push(
      staff.status === 'suspended'
        ? {
            key: 'activate',
            label: 'Reactivate',
            icon: UserCheck,
            separatorBefore: true,
            onSelect: () => act('Account reactivated.', () => usersApi.activate(staff.id)),
          }
        : {
            key: 'suspend',
            label: 'Suspend',
            icon: Ban,
            tone: 'danger',
            separatorBefore: true,
            onSelect: () => onSuspend(staff),
          }
    )

    items.push({
      key: 'delete',
      label: 'Delete',
      icon: Trash2,
      tone: 'danger',
      onSelect: () => onDelete(staff),
    })
  }

  return items
}
