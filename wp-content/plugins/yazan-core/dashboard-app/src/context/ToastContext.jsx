import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { CircleAlert, CircleCheck, Info, X } from '../components/ui/icons.js'
import { Icon } from '../components/ui/primitives.jsx'

const ToastContext = createContext(null)
let nextId = 1

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const dismiss = useCallback((id) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  /**
   * `options` is additive — the success/error/info(message) signature used across the app is
   * unchanged. It carries:
   *   persist — skip auto-dismiss (an order alert must survive until it is acknowledged)
   *   key     — identity. A repeat push with the same key REPLACES the live toast instead of
   *             stacking a second one, which is how one alert accumulates a count rather than
   *             becoming a pile of banners.
   *   action  — { label, to } renders a router link.
   *   icon    — override the tone icon.
   */
  const push = useCallback((message, tone = 'info', options = {}) => {
    const { persist = false, key = null, action = null, icon = null } = options
    // Allocate the id outside the updater: React may invoke an updater more than once
    // (StrictMode, concurrent re-render) and that must not burn ids or return a stale one.
    const id = nextId++

    setToasts((current) => {
      if (key && current.some((toast) => toast.key === key)) {
        return current.map((toast) =>
          toast.key === key ? { ...toast, message, tone, persist, action, icon } : toast
        )
      }
      return [...current, { id, message, tone, persist, key, action, icon }]
    })

    return id
  }, [])

  const dismissKey = useCallback((key) => {
    setToasts((current) => current.filter((toast) => toast.key !== key))
  }, [])

  const value = useMemo(
    () => ({
      success: (message, options) => push(message, 'success', options),
      error: (message, options) => push(message, 'error', options),
      info: (message, options) => push(message, 'info', options),
      dismissKey,
    }),
    [push, dismissKey]
  )

  return (
    <ToastContext.Provider value={value}>
      {children}
      {/*
        Two live regions, not one. Errors must interrupt (assertive); successes
        and info must not. A single polite region — which is what this used to be
        — means a failed save is announced only after whatever the user is
        currently reading, or not at all.
        Positioned with logical properties so the stack moves to the left in RTL.
      */}
      <div
        className="fixed bottom-5 end-5 flex w-80 max-w-[calc(100vw-2.5rem)] flex-col gap-2"
        style={{ zIndex: 'var(--yz-z-toast)' }}
      >
        <div aria-live="assertive" aria-atomic="false" className="contents">
          {toasts
            .filter((t) => t.tone === 'error')
            .map((toast) => (
              <Toast key={toast.id} toast={toast} onDismiss={() => dismiss(toast.id)} />
            ))}
        </div>
        <div aria-live="polite" aria-atomic="false" className="contents">
          {toasts
            .filter((t) => t.tone !== 'error')
            .map((toast) => (
              <Toast key={toast.id} toast={toast} onDismiss={() => dismiss(toast.id)} />
            ))}
        </div>
      </div>
    </ToastContext.Provider>
  )
}

const TONES = {
  success: { icon: CircleCheck, cls: 'text-ok', label: 'Success' },
  error: { icon: CircleAlert, cls: 'text-danger', label: 'Error' },
  info: { icon: Info, cls: 'text-info', label: 'Notice' },
}

function Toast({ toast, onDismiss }) {
  const [paused, setPaused] = useState(false)
  const tone = TONES[toast.tone] || TONES.info
  const icon = toast.icon || tone.icon
  const timer = useRef(null)

  // Auto-dismiss pauses on hover and on keyboard focus, so a message never
  // disappears while it is being read or while its close button is focused.
  // A `persist` toast has no timer at all — it waits to be acknowledged.
  useEffect(() => {
    if (paused || toast.persist) return undefined
    timer.current = setTimeout(onDismiss, toast.tone === 'error' ? 7000 : 4000)
    return () => clearTimeout(timer.current)
  }, [paused, onDismiss, toast.tone, toast.persist])

  return (
    <div
      role={toast.tone === 'error' ? 'alert' : 'status'}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocusCapture={() => setPaused(true)}
      onBlurCapture={() => setPaused(false)}
      className="flex items-start gap-2.5 rounded-lg border border-edge bg-raised p-3 shadow-4"
    >
      {/* The icon is not decoration: tone was previously carried by colour alone,
          which fails WCAG 1.4.1 for anyone who cannot distinguish the hues. */}
      <Icon as={icon} size={16} className={`mt-px ${tone.cls}`} />
      <span className="sr-only">{tone.label}: </span>
      <div className="flex-1">
        <span className="text-sm text-fg">{toast.message}</span>
        {/* A callback, not a <Link>: ToastProvider is mounted ABOVE <BrowserRouter> in App.jsx,
            so any router hook used in here would throw. The caller lives inside the router and
            supplies its own navigate(). */}
        {toast.action ? (
          <button
            type="button"
            onClick={() => {
              toast.action.onClick?.()
              onDismiss()
            }}
            className="mt-1 block text-sm font-medium text-info underline-offset-2 hover:underline"
          >
            {toast.action.label}
          </button>
        ) : null}
      </div>
      <button
        type="button"
        onClick={onDismiss}
        aria-label="Dismiss notification"
        className="-me-1 -mt-0.5 grid size-6 shrink-0 place-items-center rounded-sm text-faint transition-colors hover:bg-hover hover:text-fg"
      >
        <X size={14} strokeWidth={2} aria-hidden="true" />
      </button>
    </div>
  )
}

export function useToast() {
  const context = useContext(ToastContext)
  if (!context) throw new Error('useToast must be used inside <ToastProvider>')
  return context
}
