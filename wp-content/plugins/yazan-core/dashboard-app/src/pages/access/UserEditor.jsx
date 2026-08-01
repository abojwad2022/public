/**
 * User editor — create and edit a staff account.
 *
 * Two things shape this screen. First, a password should almost never be typed by an administrator:
 * the default flow emails a WordPress reset link, so the credential exists only between the person
 * and their own browser. Second, several actions are refused by the server on your own account —
 * changing your roles, suspending, deleting — because they are the fastest ways to lock the store's
 * only administrator out. Those controls are simply not offered rather than failing on submit.
 */
import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { rolesApi, usersApi } from '../../api/endpoints.js'
import { useAuth } from '../../context/AuthContext.jsx'
import { useToast } from '../../context/ToastContext.jsx'
import { PageHeader } from '../../components/Layout.jsx'
import { Can } from '../../components/Protected.jsx'
import {
  Alert,
  Badge,
  Button,
  Card,
  Checkbox,
  Field,
  Icon,
  Input,
  Select,
  Skeleton,
  SkeletonTable,
  Switch,
  Table,
  TBody,
  TD,
  TH,
  THead,
  Tabs,
  Tooltip,
  TR,
} from '../../components/ui/index.js'
import {
  Ban,
  Crown,
  ImagePlus,
  KeyRound,
  Lock,
  LogOut,
  Save,
  UserCheck,
} from '../../components/ui/icons.js'

const TABS = [
  { value: 'profile', label: 'Profile' },
  { value: 'activity', label: 'Activity' },
]

export default function UserEditor() {
  const { id } = useParams()
  const isNew = !id
  const navigate = useNavigate()
  const toast = useToast()
  const { refresh } = useAuth()
  const [params, setParams] = useSearchParams()

  const [roles, setRoles] = useState(null)
  const [staff, setStaff] = useState(null)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [uploading, setUploading] = useState(false)

  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    roles: [],
    wpRole: '',
  })
  const [sendInvite, setSendInvite] = useState(true)
  const fileRef = useRef(null)

  const tab = params.get('tab') === 'activity' && !isNew ? 'activity' : 'profile'

  const load = useCallback(async () => {
    setError(null)
    try {
      const [roleList, staffData] = await Promise.all([
        rolesApi.list({ with_counts: 0 }),
        isNew ? Promise.resolve(null) : usersApi.get(id),
      ])

      setRoles(roleList.items)

      if (staffData) {
        setStaff(staffData)
        setForm({
          name: staffData.name,
          email: staffData.email,
          phone: staffData.phone,
          password: '',
          roles: staffData.role_ids,
          wpRole: staffData.wp_role || '',
        })
      } else {
        setStaff({ id: 0, editable: true, is_self: false, status: 'active', roles: [] })
      }
    } catch (err) {
      setError(err)
    }
  }, [id, isNew])

  useEffect(() => {
    load()
  }, [load])

  const set = (key, value) => setForm((current) => ({ ...current, [key]: value }))

  const toggleRole = (roleId, on) =>
    setForm((current) => ({
      ...current,
      roles: on ? [...current.roles, roleId] : current.roles.filter((r) => r !== roleId),
    }))

  const editable = Boolean(staff?.editable)
  const isSelf = Boolean(staff?.is_self)

  const onSave = async () => {
    if (!form.name.trim() || !form.email.trim()) {
      toast.error('Name and email are both required.')
      return
    }
    if (isNew && !form.roles.length) {
      toast.error('Choose at least one role.')
      return
    }

    setSaving(true)
    try {
      const payload = {
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim(),
        // Roles are omitted for your own account: the server refuses the change, and sending it
        // would turn an unrelated name edit into a 409.
        ...(isSelf ? {} : { roles: form.roles }),
        // Only sent when it actually changed, so an unrelated edit never trips the
        // last-administrator guard.
        ...(!isNew && staff.can_set_wp_role && form.wpRole !== (staff.wp_role || '')
          ? { wp_role: form.wpRole }
          : {}),
      }

      if (isNew) {
        const created = await usersApi.create({
          ...payload,
          password: form.password || undefined,
          send_invite: sendInvite,
        })
        toast.success(
          sendInvite ? `${created.name} created — reset link sent.` : `${created.name} created.`
        )
        navigate(`/users/${created.id}`, { replace: true })
      } else {
        const saved = await usersApi.update(id, payload)
        setStaff(saved)
        toast.success('Saved.')
        if (isSelf) await refresh()
      }
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  const onPhoto = async (event) => {
    const file = event.target.files?.[0]
    if (!file || isNew) return

    setUploading(true)
    try {
      const result = await usersApi.uploadPhoto(id, file)
      setStaff((current) => ({ ...current, avatar: result.avatar, avatar_id: result.avatar_id }))
      toast.success('Photo updated.')
      if (isSelf) await refresh()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setUploading(false)
      if (fileRef.current) fileRef.current.value = ''
    }
  }

  const action = async (label, fn) => {
    try {
      await fn()
      toast.success(label)
      load()
    } catch (err) {
      toast.error(err.message)
    }
  }

  if (error) {
    return (
      <Alert tone="danger" title="Could not load this account" onRetry={load}>
        {error.message}
      </Alert>
    )
  }

  if (!staff || !roles) {
    return (
      <>
        <PageHeader title="User" />
        <Skeleton h={420} rounded="md" />
      </>
    )
  }

  return (
    <>
      <PageHeader
        title={isNew ? 'New user' : staff.name}
        subtitle={isNew ? 'Create an account that can sign into this dashboard.' : staff.email}
        breadcrumbs={[
          { label: 'Access' },
          { label: 'Users', to: '/users' },
          { label: isNew ? 'New' : staff.name },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="ghost" onClick={() => navigate('/users')}>
              Cancel
            </Button>
            <Can perm={isNew ? 'users.create' : 'users.edit'} mode="disable">
              <Button
                variant="primary"
                icon={Save}
                loading={saving}
                disabled={!editable}
                onClick={onSave}
              >
                {isNew ? 'Create user' : 'Save changes'}
              </Button>
            </Can>
          </div>
        }
      />

      {!editable && (
        <Alert tone="info" title="Read only" className="mb-4">
          This account has more access than yours, so only a super admin can change it.
        </Alert>
      )}

      {staff.status === 'suspended' && (
        <Alert tone="warn" title="Suspended" className="mb-4">
          This account cannot sign in. Its permissions are inert until it is reactivated.
        </Alert>
      )}

      {!isNew && (
        <div className="mb-4">
          <Tabs
            items={TABS}
            value={tab}
            onChange={(value) => setParams(value === 'activity' ? { tab: 'activity' } : {})}
          />
        </div>
      )}

      {tab === 'activity' ? (
        <ActivityTab userId={id} />
      ) : (
        <div className="grid gap-4 lg:grid-cols-[1fr_320px] lg:items-start">
          <div className="space-y-4">
            <Card title="Identity">
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Name" required>
                  <Input
                    value={form.name}
                    onChange={(event) => set('name', event.target.value)}
                    disabled={!editable}
                  />
                </Field>
                <Field label="Email" required help="Also their sign-in name.">
                  <Input
                    type="email"
                    value={form.email}
                    onChange={(event) => set('email', event.target.value)}
                    disabled={!editable}
                  />
                </Field>
                <Field label="Phone" help="Stored separately from any WooCommerce billing phone.">
                  <Input
                    type="tel"
                    value={form.phone}
                    onChange={(event) => set('phone', event.target.value)}
                    disabled={!editable}
                  />
                </Field>
              </div>
            </Card>

            {/*
              * WordPress role sits ABOVE the Yazan roles on purpose. An `administrator` bypasses
              * every Yazan permission, so ticking boxes below it is theatre until this is changed —
              * showing it first is the only honest ordering.
              */}
            {!isNew && staff.can_set_wp_role && (
              <Card
                title="WordPress role"
                subtitle="Site-level access, separate from the Yazan roles below."
              >
                <div className="space-y-3">
                  <Field
                    label="Role"
                    help="Yazan staff normally sit on Subscriber — everything they may do then comes from their Yazan roles."
                  >
                    <Select
                      value={form.wpRole}
                      disabled={!editable}
                      onChange={(event) => set('wpRole', event.target.value)}
                    >
                      <option value="">— No role —</option>
                      {(staff.wp_roles_available || []).map((role) => (
                        <option key={role.value} value={role.value}>
                          {role.label}
                        </option>
                      ))}
                    </Select>
                  </Field>

                  {form.wpRole === 'administrator' && (
                    <Alert tone="warn" title="This account bypasses every Yazan permission">
                      A WordPress administrator has full control of the site and is always treated as
                      a super admin here, whatever the roles below say. Move them to Subscriber to
                      make those roles take effect.
                    </Alert>
                  )}

                  {staff.is_wp_admin && form.wpRole !== 'administrator' && (
                    <Alert tone="info" title="Demoting a WordPress administrator">
                      They lose access to wp-admin and to everything the Yazan roles below do not
                      grant. Saving is refused if this is the last administrator who can still sign
                      in.
                    </Alert>
                  )}
                </div>
              </Card>
            )}

            <Card
              title="Access"
              subtitle="Someone holding several roles gets the union of their permissions."
            >
              {isSelf ? (
                <Alert tone="info" title="You cannot change your own roles">
                  Ask another administrator. This is what stops the store's last administrator
                  locking themselves out.
                </Alert>
              ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                  {roles.map((role) => (
                    <Checkbox
                      key={role.id}
                      label={
                        <span className="flex items-center gap-1.5">
                          {role.name}
                          {role.is_super && (
                            <Badge tone="gold" icon={Crown}>
                              Super
                            </Badge>
                          )}
                          {role.is_system && <Icon as={Lock} size={12} className="text-faint" />}
                        </span>
                      }
                      help={role.description}
                      checked={form.roles.includes(role.id)}
                      disabled={!editable}
                      onChange={(on) => toggleRole(role.id, on)}
                    />
                  ))}
                </div>
              )}
            </Card>

            <Card title="Security">
              {isNew ? (
                <div className="space-y-4">
                  <Field
                    label="Password"
                    help="Leave blank to generate a strong one — they will set their own from the emailed link."
                  >
                    <Input
                      type="password"
                      autoComplete="new-password"
                      value={form.password}
                      onChange={(event) => set('password', event.target.value)}
                      placeholder="At least 12 characters"
                    />
                  </Field>
                  <Switch
                    checked={sendInvite}
                    onChange={setSendInvite}
                    label="Email them a link to set their own password"
                  />
                </div>
              ) : (
                <div className="flex flex-wrap gap-2">
                  <Can perm="users.reset_password" mode="disable">
                    <Button
                      icon={KeyRound}
                      disabled={!editable}
                      onClick={() =>
                        action('Reset link sent.', () => usersApi.resetPassword(id, 'link'))
                      }
                    >
                      Send password reset link
                    </Button>
                  </Can>
                  <Can perm="users.force_logout" mode="disable">
                    <Button
                      icon={LogOut}
                      disabled={!editable}
                      onClick={() => action('Sessions ended.', () => usersApi.forceLogout(id))}
                    >
                      {isSelf ? 'Sign out other devices' : 'Force logout'}
                    </Button>
                  </Can>
                </div>
              )}
            </Card>
          </div>

          {/* --------------------------------------------------- Sidebar */}
          <div className="space-y-4">
            <Card title="Photo">
              {isNew ? (
                <p className="text-sm text-muted">
                  Save the account first, then add a photo.
                </p>
              ) : (
                <div className="flex items-center gap-4">
                  <img
                    src={staff.avatar}
                    alt=""
                    width={72}
                    height={72}
                    className="size-[72px] shrink-0 rounded-full bg-surface2 object-cover"
                  />
                  <div className="min-w-0">
                    <input
                      ref={fileRef}
                      type="file"
                      accept="image/*"
                      className="hidden"
                      onChange={onPhoto}
                    />
                    <Button
                      small
                      icon={ImagePlus}
                      loading={uploading}
                      disabled={!editable}
                      onClick={() => fileRef.current?.click()}
                    >
                      Upload photo
                    </Button>
                    <p className="mt-1.5 text-2xs leading-relaxed text-muted">
                      Stored in the media library, like any WordPress avatar.
                    </p>
                  </div>
                </div>
              )}
            </Card>

            {!isNew && (
              <Card title="Status">
                <div className="space-y-3">
                  <div className="flex items-center gap-2">
                    {staff.status === 'suspended' ? (
                      <Badge tone="warn" icon={Ban}>
                        Suspended
                      </Badge>
                    ) : (
                      <Badge tone="ok" dot>
                        Active
                      </Badge>
                    )}
                    {staff.is_wp_admin && (
                      <Tooltip label="A WordPress administrator — full access regardless of Yazan roles.">
                        <Badge tone="gold" icon={Crown}>
                          WP admin
                        </Badge>
                      </Tooltip>
                    )}
                  </div>

                  {isSelf ? (
                    <p className="text-2xs text-muted">
                      You cannot suspend your own account.
                    </p>
                  ) : (
                    <Can perm="users.suspend" mode="disable">
                      {staff.status === 'suspended' ? (
                        <Button
                          small
                          icon={UserCheck}
                          disabled={!editable}
                          onClick={() =>
                            action('Account reactivated.', () => usersApi.activate(id))
                          }
                        >
                          Reactivate account
                        </Button>
                      ) : (
                        <Button
                          small
                          variant="danger"
                          icon={Ban}
                          disabled={!editable}
                          onClick={() => action('Account suspended.', () => usersApi.suspend(id))}
                        >
                          Suspend account
                        </Button>
                      )}
                    </Can>
                  )}

                  <dl className="space-y-1.5 border-t border-edge pt-3 text-2xs">
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted">Last login</dt>
                      <dd className="text-fg">{staff.last_login || 'Never'}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted">Created</dt>
                      <dd className="text-fg">{staff.created_at}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted">Username</dt>
                      <dd className="font-mono text-fg">{staff.login}</dd>
                    </div>
                  </dl>
                </div>
              </Card>
            )}
          </div>
        </div>
      )}
    </>
  )
}

/** This account's slice of the audit log. */
function ActivityTab({ userId }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    usersApi
      .activity(userId, { per_page: 50 })
      .then((result) => !cancelled && setData(result))
      .catch((err) => !cancelled && setError(err))
    return () => {
      cancelled = true
    }
  }, [userId])

  if (error) {
    return (
      <Alert tone="danger" title="Could not load activity">
        {error.message}
      </Alert>
    )
  }

  return (
    <Card bodyClass="">
      {!data && <SkeletonTable rows={6} cols={4} />}

      {data && data.items.length === 0 && (
        <p className="p-6 text-center text-sm text-muted">
          Nothing recorded for this account yet.
        </p>
      )}

      {data && data.items.length > 0 && (
        <Table>
          <THead>
            <TR>
              <TH>When</TH>
              <TH>Action</TH>
              <TH>Record</TH>
              <TH>IP</TH>
              <TH>Browser</TH>
            </TR>
          </THead>
          <TBody>
            {data.items.map((row) => (
              <TR key={row.id}>
                <TD label="When" className="whitespace-nowrap text-muted">
                  {row.created_at}
                </TD>
                <TD label="Action" primary>
                  <code className="font-mono text-2xs">{row.action}</code>
                </TD>
                <TD label="Record" className="text-muted">
                  {row.object_type}
                  {row.object_id ? ` #${row.object_id}` : ''}
                </TD>
                <TD label="IP" className="font-mono text-2xs text-muted">
                  {row.ip}
                </TD>
                <TD label="Browser" className="max-w-[220px] text-2xs text-muted">
                  <span className="block truncate" title={row.user_agent}>
                    {row.user_agent || '—'}
                  </span>
                </TD>
              </TR>
            ))}
          </TBody>
        </Table>
      )}
    </Card>
  )
}
