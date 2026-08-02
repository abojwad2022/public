import { useEffect, useRef, useState } from 'react'
import { Icon, DirIcon } from './primitives.jsx'
import { ChevronRight, ChevronDown } from './icons.js'

/* ------------------------------------------------------------------- Tabs */

/**
 * Real tablist semantics with roving tabindex: arrow keys move between tabs,
 * Home/End jump to the ends, and only the active tab is in the tab order.
 *
 * Settings, ProductDataTabs and the Coupon editor each hand-rolled their own tab
 * strip out of <Button > rows — none of which were reachable by arrow key or
 * announced as tabs.
 *
 * @param {Array}  items    [{ key, label, icon?, count?, disabled? }]
 * @param {string} value    Active key.
 */
export function Tabs({ items, value, onChange, className = '' }) {
  const refs = useRef({})

  const move = (event) => {
    const enabled = items.filter((i) => !i.disabled)
    const index = enabled.findIndex((i) => i.key === value)
    let next = null

    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % enabled.length
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp')
      next = (index - 1 + enabled.length) % enabled.length
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = enabled.length - 1
    else return

    event.preventDefault()
    const key = enabled[next].key
    onChange(key)
    refs.current[key]?.focus()
  }

  return (
    <div
      role="tablist"
      onKeyDown={move}
      className={`yz-scroll-x flex gap-0.5 overflow-x-auto border-b border-edge ${className}`}
    >
      {items.map((item) => {
        const active = item.key === value
        return (
          <button
            key={item.key}
            ref={(el) => (refs.current[item.key] = el)}
            role="tab"
            type="button"
            aria-selected={active}
            tabIndex={active ? 0 : -1}
            disabled={item.disabled}
            onClick={() => onChange(item.key)}
            className={`-mb-px inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition-colors disabled:opacity-40 ${
              active
                ? 'border-agate font-medium text-fg'
                : 'border-transparent text-muted hover:text-fg'
            }`}
          >
            {item.icon && <Icon as={item.icon} size={15} />}
            {item.label}
            {typeof item.count === 'number' && (
              <span className="yz-num rounded-full bg-surface2 px-1.5 text-2xs text-muted">
                {item.count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}

/* ------------------------------------------------------- SegmentedControl */

/** Compact mutually-exclusive choice — date ranges, view modes, density. */
export function SegmentedControl({ items, value, onChange, size = 'sm', label }) {
  return (
    <div
      role="group"
      aria-label={label}
      className="inline-flex rounded-md border border-edge bg-sunken p-0.5"
    >
      {items.map((item) => {
        const active = item.value === value
        return (
          <button
            key={item.value}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(item.value)}
            className={`rounded-sm px-2.5 transition-colors ${
              size === 'sm' ? 'py-1 text-xs' : 'py-1.5 text-sm'
            } ${active ? 'bg-surface font-medium text-fg shadow-1' : 'text-muted hover:text-fg'}`}
          >
            {item.label}
          </button>
        )
      })}
    </div>
  )
}

/* ------------------------------------------------------------ Breadcrumbs */

/** @param {Array} items [{ label, to? }] — the last entry is the current page. */
export function Breadcrumbs({ items, LinkComponent }) {
  if (!items?.length) return null
  const Link = LinkComponent || 'a'

  return (
    <nav aria-label="Breadcrumb" className="mb-1.5">
      <ol className="flex flex-wrap items-center gap-1 text-xs text-faint">
        {items.map((item, i) => {
          const last = i === items.length - 1
          return (
            <li key={`${item.label}-${i}`} className="flex items-center gap-1">
              {item.to && !last ? (
                <Link to={item.to} href={item.to} className="rounded-xs transition-colors hover:text-fg">
                  {item.label}
                </Link>
              ) : (
                <span aria-current={last ? 'page' : undefined} className={last ? 'text-muted' : ''}>
                  {item.label}
                </span>
              )}
              {!last && <DirIcon as={ChevronRight} size={12} className="text-faint" />}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

/* --------------------------------------------------------------- Dropdown */

/**
 * Menu anchored to a trigger. Closes on Escape, on outside click, and on select;
 * returns focus to the trigger so keyboard flow is not lost.
 *
 * @param {Array} items [{ key, label, icon?, onSelect, tone?, separatorBefore? }]
 * @param {React.ReactNode} children Free-form panel content INSTEAD of `items` — for
 *   panels whose controls are not one-shot commands (checkbox lists, filter forms).
 *   Such a panel is not a menu, so it gets a plain container and does not self-close
 *   on click; the dismissal behaviour (Escape, outside click) is shared either way.
 */
export function Dropdown({ trigger, items, children, align = 'end', label = 'Actions' }) {
  const [open, setOpen] = useState(false)
  const wrapRef = useRef(null)
  const triggerRef = useRef(null)

  useEffect(() => {
    if (!open) return undefined
    const onDocDown = (event) => {
      if (!wrapRef.current?.contains(event.target)) setOpen(false)
    }
    const onKey = (event) => {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }
    document.addEventListener('mousedown', onDocDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDocDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  return (
    <div className="relative inline-flex" ref={wrapRef}>
      <span ref={triggerRef} className="contents">
        {typeof trigger === 'function'
          ? trigger({ open, toggle: () => setOpen((o) => !o) })
          : (
            <button
              type="button"
              aria-haspopup="menu"
              aria-expanded={open}
              aria-label={label}
              onClick={() => setOpen((o) => !o)}
              className="yz-btn yz-btn-ghost yz-btn-sm"
            >
              {trigger}
              <Icon as={ChevronDown} size={13} />
            </button>
          )}
      </span>

      {open && (
        <div
          role={children ? undefined : 'menu'}
          style={{ zIndex: 'var(--yz-z-popover)' }}
          className={`absolute top-full mt-1.5 min-w-44 rounded-lg border border-edge bg-raised p-1 shadow-2 ${
            align === 'end' ? 'end-0' : 'start-0'
          }`}
        >
          {children}
          {(items || []).map((item) => (
            <div key={item.key}>
              {item.separatorBefore && <div className="my-1 h-px bg-divider" />}
              <button
                type="button"
                role="menuitem"
                disabled={item.disabled}
                onClick={() => {
                  setOpen(false)
                  item.onSelect?.()
                }}
                className={`flex w-full items-center gap-2 rounded-sm px-2.5 py-1.5 text-start text-sm transition-colors disabled:opacity-40 ${
                  item.tone === 'danger'
                    ? 'text-danger hover:bg-danger-bg'
                    : 'text-fg hover:bg-surface2'
                }`}
              >
                {item.icon && <Icon as={item.icon} size={15} />}
                {item.label}
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

/* ---------------------------------------------------------------- Tooltip */

/**
 * Shows on hover AND on keyboard focus — a hover-only tooltip is invisible to
 * keyboard users, which matters because icon-only buttons rely on it for their
 * visible label.
 */
export function Tooltip({ label, children, placement = 'top', className = '' }) {
  const [open, setOpen] = useState(false)

  const position =
    placement === 'bottom'
      ? 'top-full mt-1.5'
      : placement === 'start'
        ? 'end-full me-1.5 top-1/2 -translate-y-1/2'
        : placement === 'end'
          ? 'start-full ms-1.5 top-1/2 -translate-y-1/2'
          : 'bottom-full mb-1.5'

  return (
    <span
      className={`relative inline-flex ${className}`}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
      onFocusCapture={() => setOpen(true)}
      onBlurCapture={() => setOpen(false)}
    >
      {children}
      {open && (
        <span
          role="tooltip"
          style={{ zIndex: 'var(--yz-z-popover)' }}
          className={`pointer-events-none absolute ${position} left-1/2 w-max max-w-56 -translate-x-1/2 rounded-md border border-edge bg-raised px-2 py-1 text-xs text-fg shadow-2 ${
            placement === 'start' || placement === 'end' ? 'left-auto translate-x-0' : ''
          }`}
        >
          {label}
        </span>
      )}
    </span>
  )
}
