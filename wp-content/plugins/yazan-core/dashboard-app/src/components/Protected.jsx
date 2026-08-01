/**
 * Permission-aware rendering.
 *
 * Two shapes, deliberately different:
 *
 *   <Protected perm="users.view">  wraps a ROUTE. A denied user gets an explanation, not a blank
 *                                  screen or a redirect that hides why they landed elsewhere.
 *   <Can perm="users.delete">      wraps an ACTION. Destructive actions are hidden outright;
 *                                  non-destructive ones can be disabled with a tooltip instead,
 *                                  which teaches the operator what to ask for.
 *
 * Neither is security. The server refuses every forbidden request in Yazan_REST_Guard regardless
 * of what the client chooses to render — this only stops the UI offering doors that will not open.
 */
import { cloneElement, isValidElement } from 'react'
import { useAuth } from '../context/AuthContext.jsx'
import { PageHeader } from './Layout.jsx'
import { Card, EmptyState, Tooltip } from './ui/index.js'
import { Lock } from './ui/icons.js'

/**
 * Route guard. `perm` requires one permission; `anyOf` requires at least one of several.
 */
export function Protected({ perm, anyOf, title, children }) {
  const { can, canAny } = useAuth()
  const allowed = anyOf ? canAny(...anyOf) : can(perm)

  if (allowed) return children

  return <NoAccess title={title} perm={perm || (anyOf && anyOf[0])} />
}

/**
 * The denied-route screen. Names the missing permission on purpose: "ask an administrator" is
 * useless advice if neither party knows which switch to flip.
 */
export function NoAccess({ title = 'Not available', perm }) {
  return (
    <>
      <PageHeader title={title} />
      <Card>
        <EmptyState title="You do not have access to this screen" icon={Lock}>
          Ask an administrator to grant your role
          {perm ? (
            <>
              {' '}
              the <code className="rounded bg-hover px-1 py-0.5 font-mono text-2xs">{perm}</code>{' '}
              permission.
            </>
          ) : (
            ' access to this area.'
          )}
        </EmptyState>
      </Card>
    </>
  )
}

/**
 * Inline action guard.
 *
 * mode="hide"    — render nothing (the default; correct for destructive or irrelevant actions).
 * mode="disable" — render the child disabled, wrapped in a tooltip explaining why.
 */
export function Can({ perm, anyOf, mode = 'hide', fallback = null, children }) {
  const { can, canAny } = useAuth()
  const allowed = anyOf ? canAny(...anyOf) : can(perm)

  if (allowed) return children
  if (mode !== 'disable' || !isValidElement(children)) return fallback

  const label = `Requires the ${perm || (anyOf && anyOf.join(' or '))} permission`

  return (
    <Tooltip label={label}>
      {cloneElement(children, { disabled: true, 'aria-disabled': true })}
    </Tooltip>
  )
}
