/**
 * Roles — the list.
 *
 * Deliberately plain: the interesting work happens in the editor. What this screen has to make
 * obvious is which roles are load-bearing (super admin, built-in) and how many people each one
 * affects, because those are the two facts that decide whether an edit is safe.
 */
import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { rolesApi } from '../../api/endpoints.js'
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
  Icon,
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
  Copy,
  Crown,
  Lock,
  Pencil,
  Plus,
  ShieldCheck,
  Trash2,
  Users,
} from '../../components/ui/icons.js'

export default function Roles() {
  const [roles, setRoles] = useState(null)
  const [error, setError] = useState(null)
  const navigate = useNavigate()
  const toast = useToast()
  const [confirm, confirmDialog] = useConfirm()
  const { refresh } = useAuth()

  const load = useCallback(async () => {
    setError(null)
    try {
      const data = await rolesApi.list({ with_counts: 1 })
      setRoles(data.items)
    } catch (err) {
      setError(err)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const onDuplicate = async (role) => {
    try {
      const copy = await rolesApi.duplicate(role.id)
      toast.success(`Created “${copy.name}”.`)
      navigate(`/roles/${copy.id}`)
    } catch (err) {
      toast.error(err.message)
    }
  }

  const onDelete = async (role) => {
    const ok = await confirm({
      title: `Delete “${role.name}”?`,
      body: role.members
        ? `${role.members} ${role.members === 1 ? 'person holds' : 'people hold'} this role and will lose the access it grants. This cannot be undone.`
        : 'This role is not assigned to anyone. This cannot be undone.',
      confirmLabel: 'Delete role',
      tone: 'danger',
    })
    if (!ok) return

    try {
      await rolesApi.remove(role.id)
      toast.success('Role deleted.')
      // Deleting a role can change what the CURRENT user may do, so re-read our own permissions
      // before the sidebar keeps offering a screen the server has started refusing.
      await refresh()
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  return (
    <>
      <PageHeader
        title="Roles"
        subtitle="A role is a named bundle of permissions. People can hold more than one; their access is the union."
        breadcrumbs={[{ label: 'Access' }, { label: 'Roles' }]}
        actions={
          <Can perm="roles.create">
            <Button variant="primary" icon={Plus} onClick={() => navigate('/roles/new')}>
              New role
            </Button>
          </Can>
        }
      />

      {error && (
        <Alert tone="danger" title="Could not load roles" onRetry={load} className="mb-4">
          {error.message}
        </Alert>
      )}

      <Card bodyClass="">
        {!roles && <SkeletonTable rows={6} cols={4} />}

        {roles && roles.length === 0 && (
          <EmptyState title="No roles yet" icon={ShieldCheck}>
            Create a role to start granting people access.
          </EmptyState>
        )}

        {roles && roles.length > 0 && (
          <Table>
            <THead>
              <TR>
                <TH>Role</TH>
                <TH align="end">Permissions</TH>
                <TH align="end">People</TH>
                <TH className="w-10" />
              </TR>
            </THead>
            <TBody>
              {roles.map((role) => (
                <TR key={role.id}>
                  <TD label="Role" primary>
                    <div className="flex flex-wrap items-center gap-2">
                      <Link
                        to={`/roles/${role.id}`}
                        className="font-medium text-fg transition-colors hover:text-agate"
                      >
                        {role.name}
                      </Link>
                      {role.is_super && (
                        <Tooltip label="Bypasses every permission check">
                          <Badge tone="gold" icon={Crown}>
                            Super admin
                          </Badge>
                        </Tooltip>
                      )}
                      {role.is_system && (
                        <Tooltip label="Built in — permissions can be edited, but it cannot be deleted">
                          <span className="text-faint">
                            <Icon as={Lock} size={13} />
                          </span>
                        </Tooltip>
                      )}
                    </div>
                    {role.description && (
                      <p className="mt-0.5 text-2xs text-muted">{role.description}</p>
                    )}
                  </TD>
                  <TD label="Permissions" align="end" className="tabular-nums text-muted">
                    {role.permissions.length}
                  </TD>
                  <TD label="People" align="end">
                    <span className="inline-flex items-center gap-1.5 tabular-nums text-muted">
                      <Icon as={Users} size={13} />
                      {role.members ?? 0}
                    </span>
                  </TD>
                  <TD align="end">
                    <Dropdown
                      label={`Actions for ${role.name}`}
                      items={[
                        {
                          key: 'edit',
                          label: 'Edit',
                          icon: Pencil,
                          onSelect: () => navigate(`/roles/${role.id}`),
                        },
                        {
                          key: 'duplicate',
                          label: 'Duplicate',
                          icon: Copy,
                          onSelect: () => onDuplicate(role),
                        },
                        // A built-in role gets no delete entry: the server refuses it outright, and
                        // a button that always fails is worse than no button.
                        ...(role.is_system
                          ? []
                          : [
                              {
                                key: 'delete',
                                label: 'Delete',
                                icon: Trash2,
                                tone: 'danger',
                                separatorBefore: true,
                                onSelect: () => onDelete(role),
                              },
                            ]),
                      ]}
                    />
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      {confirmDialog}
    </>
  )
}
