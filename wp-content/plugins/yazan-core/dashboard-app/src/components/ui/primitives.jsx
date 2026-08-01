import { cloneElement, forwardRef, isValidElement, useId, useState } from 'react'
import { Eye, EyeOff, LoaderCircle, Search, X } from './icons.js'

/* ------------------------------------------------------------------ Icons */

/**
 * Every icon renders through here so size and stroke stay consistent.
 * 16px / 1.75 stroke optically matches the 13-14px UI text; lucide's 24px / 2
 * default towers over it.
 */
export function Icon({ as: Component, size = 16, stroke = 1.75, className = '', ...rest }) {
  if (!Component) return null
  return (
    <Component
      size={size}
      strokeWidth={stroke}
      className={`shrink-0 ${className}`}
      aria-hidden="true"
      {...rest}
    />
  )
}

/**
 * A directional icon — chevrons, arrows, external-link markers. These are the
 * ONLY icons that may mirror in RTL. Everything else (search, pencil, trash)
 * must not: a mirrored magnifier is the classic RTL bug. Mirroring is therefore
 * opt-in via this component, never automatic.
 */
export function DirIcon(props) {
  return <Icon {...props} className={`rtl:-scale-x-100 ${props.className || ''}`} />
}

/* ---------------------------------------------------------------- Buttons */

const VARIANTS = {
  primary: 'yz-btn-primary',
  secondary: 'yz-btn-secondary',
  ghost: 'yz-btn-ghost',
  quiet: 'yz-btn-quiet',
  danger: 'yz-btn-danger',
}

/**
 * `variant` and `small` are the original API and still behave identically, so
 * the ~30 call sites that predate the design system keep working untouched.
 * `size`, `loading` and `icon` are the additions.
 */
export const Button = forwardRef(function Button(
  { variant = 'ghost', small, size, loading, icon, iconOnly, children, className = '', ...props },
  ref
) {
  const resolvedSize = size || (small ? 'sm' : 'md')
  const sizeClass = resolvedSize === 'sm' ? 'yz-btn-sm' : resolvedSize === 'lg' ? 'yz-btn-lg' : ''

  return (
    <button
      ref={ref}
      type={props.type || 'button'}
      {...props}
      disabled={props.disabled || loading}
      aria-busy={loading || undefined}
      className={`yz-btn ${VARIANTS[variant] || VARIANTS.ghost} ${sizeClass} ${
        iconOnly ? 'yz-btn-icon' : ''
      } ${className}`}
    >
      {loading ? (
        <LoaderCircle size={15} strokeWidth={2} className="animate-spin shrink-0" aria-hidden="true" />
      ) : (
        icon && <Icon as={icon} size={resolvedSize === 'sm' ? 14 : 16} />
      )}
      {children}
    </button>
  )
})

/** Icon-only button. `label` is required — it becomes the accessible name. */
export const IconButton = forwardRef(function IconButton(
  { icon, label, variant = 'quiet', size = 'md', ...props },
  ref
) {
  return (
    <Button ref={ref} variant={variant} size={size} iconOnly aria-label={label} title={label} {...props}>
      <Icon as={icon} size={size === 'sm' ? 14 : 16} />
    </Button>
  )
})

/* ------------------------------------------------------------------ Fields */

/**
 * Wraps a control with its label, help text and error.
 *
 * The important part is invisible: `error` sets aria-invalid on the control and
 * wires aria-describedby to the message, so a screen reader announces WHY a
 * field was rejected instead of just "invalid". Previously errors were rendered
 * as loose text with no programmatic relationship to the input at all.
 */
export function Field({ label, help, error, htmlFor, required, children, className = '' }) {
  const generatedId = useId()
  const id = htmlFor || generatedId
  const helpId = help ? `${id}-help` : undefined
  const errorId = error ? `${id}-error` : undefined
  const describedBy = [errorId, helpId].filter(Boolean).join(' ') || undefined

  // Only clone a single element child, so fragments and multi-element children
  // still pass straight through.
  const control = isValidElement(children)
    ? cloneElement(children, {
        id: children.props.id || id,
        'aria-describedby': children.props['aria-describedby'] || describedBy,
        'aria-invalid': error ? 'true' : children.props['aria-invalid'],
      })
    : children

  return (
    <div className={className}>
      {label && (
        <label className="yz-label" htmlFor={id}>
          {label}
          {required && (
            <span className="text-danger ms-0.5" aria-hidden="true">
              *
            </span>
          )}
        </label>
      )}
      {control}
      {error && (
        <p className="yz-error" id={errorId}>
          {error}
        </p>
      )}
      {help && (
        <p className="yz-help" id={helpId}>
          {help}
        </p>
      )}
    </div>
  )
}

export const Input = forwardRef(function Input(props, ref) {
  return <input ref={ref} {...props} className={`yz-input ${props.className || ''}`} />
})

/**
 * Password field with a reveal toggle.
 *
 * Typing a credential you cannot read is how typos become "the password doesn't work" support
 * tickets — worse when an administrator is setting a password *for someone else* and has no way to
 * confirm what they typed. Hidden by default; revealing is a deliberate act.
 *
 * The toggle is a real <button> rather than a click handler on an icon, so it is keyboard
 * reachable, and it is padded with logical properties (`end-*`) so it flips side in RTL.
 */
export const PasswordInput = forwardRef(function PasswordInput(
  { className = '', ...props },
  ref
) {
  const [visible, setVisible] = useState(false)
  const label = visible ? 'Hide password' : 'Show password'

  return (
    <div className="relative flex items-center">
      <input
        ref={ref}
        {...props}
        type={visible ? 'text' : 'password'}
        // Room for the button, so a long value never slides underneath it.
        className={`yz-input pe-9 ${className}`}
      />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        // aria-pressed carries the state; without it the button reads identically in both
        // positions to a screen reader.
        aria-pressed={visible}
        aria-label={label}
        title={label}
        tabIndex={props.disabled ? -1 : 0}
        disabled={props.disabled}
        className="absolute end-1 grid size-7 place-items-center rounded-md text-faint transition-colors hover:text-fg disabled:opacity-50"
      >
        <Icon as={visible ? EyeOff : Eye} size={15} />
      </button>
    </div>
  )
})

export const Textarea = forwardRef(function Textarea(props, ref) {
  return <textarea ref={ref} {...props} className={`yz-input ${props.className || ''}`} />
})

export const Select = forwardRef(function Select({ children, ...props }, ref) {
  return (
    <select ref={ref} {...props} className={`yz-input ${props.className || ''}`}>
      {children}
    </select>
  )
})

export function Checkbox({ label, checked, onChange, disabled, help, className = '' }) {
  return (
    <label
      className={`flex items-start gap-2.5 ${disabled ? 'opacity-50' : 'cursor-pointer'} ${className}`}
    >
      <input
        type="checkbox"
        checked={Boolean(checked)}
        disabled={disabled}
        onChange={(event) => onChange(event.target.checked)}
        className="mt-0.5 size-4 shrink-0 rounded-xs accent-[var(--yz-agate)]"
      />
      <span>
        <span className="text-sm text-fg">{label}</span>
        {help && <span className="block text-xs text-faint">{help}</span>}
      </span>
    </label>
  )
}

export function Radio({ label, name, value, checked, onChange, disabled, help }) {
  return (
    <label className={`flex items-start gap-2.5 ${disabled ? 'opacity-50' : 'cursor-pointer'}`}>
      <input
        type="radio"
        name={name}
        value={value}
        checked={Boolean(checked)}
        disabled={disabled}
        onChange={() => onChange(value)}
        className="mt-0.5 size-4 shrink-0 accent-[var(--yz-agate)]"
      />
      <span>
        <span className="text-sm text-fg">{label}</span>
        {help && <span className="block text-xs text-faint">{help}</span>}
      </span>
    </label>
  )
}

/** Switch — for settings that apply immediately, where a checkbox reads wrong. */
export function Switch({ checked, onChange, label, disabled, id }) {
  const generatedId = useId()
  const switchId = id || generatedId
  return (
    <label
      htmlFor={switchId}
      className={`inline-flex items-center gap-2.5 ${disabled ? 'opacity-50' : 'cursor-pointer'}`}
    >
      <button
        id={switchId}
        type="button"
        role="switch"
        aria-checked={Boolean(checked)}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={`relative h-5 w-9 shrink-0 rounded-full transition-colors ${
          checked ? 'bg-agate' : 'bg-edge-strong'
        }`}
      >
        <span
          className={`absolute top-0.5 size-4 rounded-full bg-white transition-[inset-inline-start] ${
            checked ? 'start-[1.125rem]' : 'start-0.5'
          }`}
        />
      </button>
      {label && <span className="text-sm text-fg">{label}</span>}
    </label>
  )
}

/** Search input with a leading icon and a clear button once it has a value. */
export function SearchInput({ value, onChange, placeholder = 'Search…', className = '', ...rest }) {
  return (
    <div className={`relative flex items-center ${className}`}>
      <Search
        size={15}
        strokeWidth={1.75}
        aria-hidden="true"
        className="pointer-events-none absolute start-2.5 text-faint"
      />
      <input
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        className="yz-input ps-8 pe-8"
        {...rest}
      />
      {value ? (
        <button
          type="button"
          onClick={() => onChange('')}
          aria-label="Clear search"
          className="absolute end-1.5 grid size-6 place-items-center rounded-sm text-faint transition-colors hover:text-fg"
        >
          <X size={14} strokeWidth={2} aria-hidden="true" />
        </button>
      ) : null}
    </div>
  )
}
