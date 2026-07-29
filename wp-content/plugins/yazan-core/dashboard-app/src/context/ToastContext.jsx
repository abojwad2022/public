import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import { CircleAlert, CircleCheck, Info, X } from '../components/ui/icons.js'
import { Icon } from '../components/ui/primitives.jsx'

const ToastContext = createContext(null)
let nextId = 1

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const dismiss = useCallback((id) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const push = useCallback((message, tone = 'info') => {
    const id = nextId++
    setToasts((current) => [...current, { id, message, tone }])
    return id
  }, [])

  const value = {
    success: (message) => push(message, 'success'),
    error: (message) => push(message, 'error'),
    info: (message) => push(message, 'info'),
  }

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
  const { icon, cls, label } = TONES[toast.tone] || TONES.info
  const timer = useRef(null)

  // Auto-dismiss pauses on hover and on keyboard focus, so a message never
  // disappears while it is being read or while its close button is focused.
  useEffect(() => {
    if (paused) return undefined
    timer.current = setTimeout(onDismiss, toast.tone === 'error' ? 7000 : 4000)
    return () => clearTimeout(timer.current)
  }, [paused, onDismiss, toast.tone])

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
      <Icon as={icon} size={16} className={`mt-px ${cls}`} />
      <span className="sr-only">{label}: </span>
      <span className="flex-1 text-sm text-fg">{toast.message}</span>
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
