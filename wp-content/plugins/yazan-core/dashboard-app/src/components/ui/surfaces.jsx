import { useCallback, useId, useRef, useState } from 'react'
import { useFocusTrap } from './useFocusTrap.js'
import { Button, Icon, IconButton } from './primitives.jsx'
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from './icons.js'

/* ------------------------------------------------------------------- Card */

export function Card({ title, subtitle, actions, children, className = '', bodyClass = 'p-4' }) {
  return (
    <section className={`yz-card ${className}`}>
      {(title || actions) && (
        <header className="yz-card-head">
          <div className="min-w-0">
            {title && <h2 className="yz-card-title truncate">{title}</h2>}
            {subtitle && <p className="mt-0.5 truncate text-xs text-faint">{subtitle}</p>}
          </div>
          {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        </header>
      )}
      <div className={bodyClass}>{children}</div>
    </section>
  )
}

/* ------------------------------------------------------------------ Modal */

const MODAL_SIZES = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-3xl', xl: 'max-w-5xl' }

/**
 * `wide` is the original API (equivalent to size="xl") and still works; `size`
 * is the addition.
 *
 * What changed underneath: the dialog now traps Tab, restores focus to whatever
 * opened it, and labels itself via aria-labelledby pointing at the real heading
 * rather than a duplicated aria-label string.
 */
export function Modal({ open, onClose, title, description, children, footer, wide, size = 'md' }) {
  const titleId = useId()
  const trapRef = useFocusTrap(open, onClose)

  if (!open) return null
  const width = wide ? MODAL_SIZES.xl : MODAL_SIZES[size] || MODAL_SIZES.md

  return (
    <div
      className="fixed inset-0 flex items-start justify-center overflow-y-auto p-4 sm:p-8"
      style={{ zIndex: 'var(--yz-z-modal)' }}
    >
      <div
        className="fixed inset-0 bg-scrim"
        onClick={onClose}
        role="presentation"
        aria-hidden="true"
      />
      <div
        ref={trapRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className={`relative my-auto w-full ${width} rounded-xl border border-edge bg-surface shadow-3 outline-none`}
      >
        <header className="sticky top-0 z-10 flex items-start justify-between gap-3 rounded-t-xl border-b border-edge bg-surface px-5 py-3.5">
          <div className="min-w-0">
            <h2 id={titleId} className="yz-card-title">
              {title}
            </h2>
            {description && <p className="mt-0.5 text-xs text-faint">{description}</p>}
          </div>
          <IconButton icon={X} label="Close" size="sm" onClick={onClose} />
        </header>
        <div className="p-5">{children}</div>
        {footer && (
          <footer className="flex items-center justify-end gap-2 border-t border-edge px-5 py-3.5">
            {footer}
          </footer>
        )}
      </div>
    </div>
  )
}

/* ----------------------------------------------------------------- Drawer */

/** Side panel for secondary detail that shouldn't take over the screen. */
export function Drawer({ open, onClose, title, children, footer, width = 'max-w-md' }) {
  const titleId = useId()
  const trapRef = useFocusTrap(open, onClose)

  if (!open) return null

  return (
    <div className="fixed inset-0" style={{ zIndex: 'var(--yz-z-drawer)' }}>
      <div className="absolute inset-0 bg-scrim" onClick={onClose} role="presentation" aria-hidden="true" />
      <div
        ref={trapRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className={`absolute inset-y-0 end-0 flex w-full ${width} flex-col border-s border-edge bg-surface shadow-3 outline-none`}
      >
        <header className="flex items-center justify-between gap-3 border-b border-edge px-5 py-3.5">
          <h2 id={titleId} className="yz-card-title">
            {title}
          </h2>
          <IconButton icon={X} label="Close" size="sm" onClick={onClose} />
        </header>
        <div className="flex-1 overflow-y-auto p-5">{children}</div>
        {footer && (
          <footer className="flex items-center justify-end gap-2 border-t border-edge px-5 py-3.5">
            {footer}
          </footer>
        )}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ Alert */

const ALERT_TONES = {
  info: { icon: Info, cls: 'bg-info-bg border-info-border text-info' },
  ok: { icon: CircleCheck, cls: 'bg-ok-bg border-ok-border text-ok' },
  warn: { icon: TriangleAlert, cls: 'bg-warn-bg border-warn-border text-warn' },
  danger: { icon: CircleAlert, cls: 'bg-danger-bg border-danger-border text-danger' },
}

/**
 * Inline message with an icon, a title and an optional retry action.
 *
 * The icon matters for accessibility, not decoration: tone was previously
 * communicated by colour alone, which fails WCAG 1.4.1 for anyone who cannot
 * distinguish the hues.
 */
export function Alert({ tone = 'info', title, children, action, onRetry, className = '' }) {
  const { icon, cls } = ALERT_TONES[tone] || ALERT_TONES.info
  return (
    <div
      role={tone === 'danger' ? 'alert' : 'status'}
      className={`flex items-start gap-3 rounded-lg border p-3.5 ${cls} ${className}`}
    >
      <Icon as={icon} size={17} className="mt-px" />
      <div className="min-w-0 flex-1">
        {title && <p className="text-sm font-medium">{title}</p>}
        {children && <div className={`text-sm text-fg ${title ? 'mt-0.5' : ''}`}>{children}</div>}
      </div>
      {onRetry && (
        <Button size="sm" variant="secondary" onClick={onRetry}>
          Retry
        </Button>
      )}
      {action}
    </div>
  )
}

/* ---------------------------------------------------------- ConfirmDialog */

/**
 * Replaces window.confirm(), which cannot be styled, cannot be themed, is not
 * translatable, and in a luxury product looks exactly like what it is: a browser
 * dialog bolted onto a designed interface.
 */
export function ConfirmDialog({
  open,
  onCancel,
  onConfirm,
  title,
  children,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  tone = 'danger',
  busy,
}) {
  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="ghost" onClick={onCancel} disabled={busy}>
            {cancelLabel}
          </Button>
          <Button
            variant={tone === 'danger' ? 'danger' : 'primary'}
            onClick={onConfirm}
            loading={busy}
            data-autofocus
          >
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="text-sm text-muted">{children}</div>
    </Modal>
  )
}

/**
 * Promise-based confirmation.
 *
 * `window.confirm` survived longest in the money-handling paths precisely because
 * it is a one-line blocking boolean. This keeps that call shape —
 *   if (!(await confirm({ title, body }))) return
 * — while rendering a real dialog: themed, translatable, Escape-dismissable,
 * focus-trapped, and able to carry a destructive tone. Usage:
 *
 *   const [confirm, confirmDialog] = useConfirm()
 *   …
 *   return (<>…{confirmDialog}</>)
 */
export function useConfirm() {
  const [state, setState] = useState(null)
  const resolver = useRef(null)

  const confirm = useCallback((options) => {
    setState(options || {})
    return new Promise((resolve) => {
      resolver.current = resolve
    })
  }, [])

  const settle = useCallback((value) => {
    setState(null)
    if (resolver.current) {
      resolver.current(value)
      resolver.current = null
    }
  }, [])

  const dialog = (
    <ConfirmDialog
      open={Boolean(state)}
      title={state?.title}
      confirmLabel={state?.confirmLabel}
      cancelLabel={state?.cancelLabel}
      tone={state?.tone}
      onCancel={() => settle(false)}
      onConfirm={() => settle(true)}
    >
      {state?.body}
    </ConfirmDialog>
  )

  return [confirm, dialog]
}
