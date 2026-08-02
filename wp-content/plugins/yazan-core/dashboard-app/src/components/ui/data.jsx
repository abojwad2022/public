import { Icon } from './primitives.jsx'
import {
  ArrowDown,
  ArrowUp,
  ChevronsUpDown,
  Inbox,
  LoaderCircle,
  Minus,
  TrendingDown,
  TrendingUp,
  UserRound,
} from './icons.js'
import { DirIcon } from './primitives.jsx'
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from './icons.js'

/* ----------------------------------------------------------------- Avatar */

/**
 * A person's picture, or a neutral stand-in when there isn't one.
 *
 * ⚠️ Pass `src` ONLY when the account genuinely has an uploaded photo. The REST payload's `avatar`
 * field is never empty — WordPress's get_avatar_url() always returns *something* (a Gravatar URL,
 * falling back to their generated default), so testing `avatar` for emptiness silently never fires.
 * The real signal is `avatar_id`:
 *
 *     <Avatar src={user.avatar_id ? user.avatar : ''} name={user.name} />
 *
 * The fallback is drawn locally rather than deferring to Gravatar's default image, which also keeps
 * these screens free of third-party requests.
 *
 * @param {string} src   Image URL, or '' for the icon fallback.
 * @param {string} name  Used for the alt text only — decorative here, since every call site shows
 *                       the name next to the avatar, so it stays empty to avoid a screen reader
 *                       announcing it twice.
 * @param {number} size  Pixel box; the icon scales with it.
 */
export function Avatar({ src, name = '', size = 40, className = '' }) {
  const box = { width: size, height: size }

  if (src) {
    return (
      <img
        src={src}
        alt=""
        width={size}
        height={size}
        loading="lazy"
        style={box}
        className={`shrink-0 rounded-full bg-surface2 object-cover ${className}`}
      />
    )
  }

  return (
    <span
      style={box}
      role="img"
      aria-label={name ? `${name} — no photo` : 'No photo'}
      className={`grid shrink-0 place-items-center rounded-full bg-surface2 text-faint ${className}`}
    >
      <Icon as={UserRound} size={Math.round(size * 0.55)} />
    </span>
  )
}

/* ------------------------------------------------------------------ Badge */

/**
 * Soft-filled by default. Status is the single most-scanned element in an order
 * or product list, and the previous outline-only treatment read as weak — a thin
 * coloured border on a dark surface is close to invisible at 11px.
 */
const BADGE_TONES = {
  ok: 'bg-ok-bg border-ok-border text-ok',
  warn: 'bg-warn-bg border-warn-border text-warn',
  danger: 'bg-danger-bg border-danger-border text-danger',
  info: 'bg-info-bg border-info-border text-info',
  gold: 'bg-gold-bg border-gold text-gold',
  agate: 'bg-agate-bg border-agate text-agate-fg',
  muted: 'bg-surface2 border-edge text-muted',
}

const BADGE_SOLID = {
  ok: 'bg-ok-solid border-transparent text-white',
  warn: 'bg-warn-solid border-transparent text-white',
  danger: 'bg-danger-solid border-transparent text-white',
  info: 'bg-info-solid border-transparent text-white',
  gold: 'bg-gold border-transparent text-oncontrast',
  agate: 'bg-agate border-transparent text-white',
  muted: 'bg-edge-strong border-transparent text-fg',
}

export function Badge({ tone = 'muted', variant = 'soft', icon, dot, children }) {
  const map = variant === 'solid' ? BADGE_SOLID : BADGE_TONES
  return (
    <span className={`yz-badge ${map[tone] || map.muted}`}>
      {dot && <span className="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" />}
      {icon && <Icon as={icon} size={12} />}
      {children}
    </span>
  )
}

/* ------------------------------------------------------------------ Table */

/**
 * Table shell. The wrapper carries `yz-scroll-x`, which the global focus rule
 * keys off to flip focus rings inward — an outward ring is clipped by a
 * horizontally scrolling container.
 */
/**
 * @param {boolean} cards  Re-flow each row into a stacked card below `sm`
 *                         (default). Pass false for narrow tables that already
 *                         fit — a 3-column table reads worse as cards.
 */
export function Table({ children, className = '', cards = true }) {
  return (
    <div className={`yz-scroll-x overflow-x-auto ${cards ? 'yz-table-cards' : ''} ${className}`}>
      <table className="w-full border-collapse text-sm">{children}</table>
    </div>
  )
}

export function THead({ children }) {
  return <thead>{children}</thead>
}

export function TBody({ children }) {
  return <tbody>{children}</tbody>
}

export function TR({ children, onClick, selected, className = '' }) {
  return (
    <tr
      onClick={onClick}
      className={`${onClick ? 'cursor-pointer' : ''} ${
        selected ? 'bg-selected' : 'yz-tr-hover'
      } transition-colors ${className}`}
    >
      {children}
    </tr>
  )
}

/**
 * Column header. When `sortKey` is given it becomes a real sort button and
 * exposes `aria-sort`, so assistive tech can report the current sort — the old
 * hand-rolled headers were plain text with a caret glyph and announced nothing.
 */
export function TH({ children, sortKey, activeSort, direction, onSort, align, className = '', ...rest }) {
  const isActive = sortKey && activeSort === sortKey
  const alignClass = align === 'end' ? 'text-end' : align === 'center' ? 'text-center' : ''

  if (!sortKey) {
    return (
      <th scope="col" className={`yz-th ${alignClass} ${className}`} {...rest}>
        {children}
      </th>
    )
  }

  return (
    <th
      scope="col"
      aria-sort={isActive ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}
      className={`yz-th ${alignClass} ${className}`}
      {...rest}
    >
      <button
        type="button"
        onClick={() => onSort(sortKey)}
        className={`inline-flex items-center gap-1 rounded-xs transition-colors hover:text-fg ${
          isActive ? 'text-fg' : ''
        } ${align === 'end' ? 'flex-row-reverse' : ''}`}
      >
        {children}
        <Icon
          as={isActive ? (direction === 'asc' ? ArrowUp : ArrowDown) : ChevronsUpDown}
          size={13}
          className={isActive ? 'text-gold' : 'text-faint'}
        />
      </button>
    </th>
  )
}

export function TD({ children, align, label, primary, className = '', ...rest }) {
  const alignClass = align === 'end' ? 'text-end' : align === 'center' ? 'text-center' : ''
  return (
    <td
      // Printed by a ::before in card mode, where the header row is hidden.
      data-label={label || undefined}
      className={`yz-td ${primary ? 'yz-td-primary' : ''} ${alignClass} ${className}`}
      {...rest}
    >
      {children}
    </td>
  )
}

/* ------------------------------------------------------------- Pagination */

export function Pagination({ page, pages, total, perPage, onPage, label = 'items' }) {
  if (!pages || pages <= 1) return null

  const from = (page - 1) * perPage + 1
  const to = Math.min(page * perPage, total)

  return (
    <nav
      className="flex flex-wrap items-center justify-between gap-3 border-t border-edge px-4 py-3"
      aria-label="Pagination"
    >
      <p className="text-xs text-faint">
        <span className="yz-num">
          {from}–{to}
        </span>{' '}
        of <span className="yz-num">{total}</span> {label}
      </p>
      <div className="flex items-center gap-1.5">
        {/* First/last jumps appear only once there is somewhere to jump to. Without them,
            reaching the end of a 40-page list means 39 clicks — the reason wp-admin has
            had them since forever. */}
        {pages > 2 && (
          <button
            type="button"
            onClick={() => onPage(1)}
            disabled={page <= 1}
            aria-label="First page"
            title="First page"
            className="yz-btn yz-btn-ghost yz-btn-sm yz-btn-icon"
          >
            <DirIcon as={ChevronsLeft} size={14} />
          </button>
        )}
        <button
          type="button"
          onClick={() => onPage(page - 1)}
          disabled={page <= 1}
          className="yz-btn yz-btn-ghost yz-btn-sm"
        >
          <DirIcon as={ChevronLeft} size={14} />
          Previous
        </button>
        <span className="yz-num px-2 text-xs text-muted">
          {page} / {pages}
        </span>
        <button
          type="button"
          onClick={() => onPage(page + 1)}
          disabled={page >= pages}
          className="yz-btn yz-btn-ghost yz-btn-sm"
        >
          Next
          <DirIcon as={ChevronRight} size={14} />
        </button>
        {pages > 2 && (
          <button
            type="button"
            onClick={() => onPage(pages)}
            disabled={page >= pages}
            aria-label="Last page"
            title="Last page"
            className="yz-btn yz-btn-ghost yz-btn-sm yz-btn-icon"
          >
            <DirIcon as={ChevronsRight} size={14} />
          </button>
        )}
      </div>
    </nav>
  )
}

/* ----------------------------------------------------------------- Stats */

/**
 * The single KPI tile for the whole app. `Tile` was previously defined three
 * separate times — in Reports, AI Insights and AI Settings — each with slightly
 * different padding and type, which is exactly how a design system erodes.
 *
 * A bare number answers "how much"; a delta answers "is that good", which is the
 * question a store owner actually opens the dashboard to ask.
 */
/**
 * Optional icon accents, drawn from the same six validated --yz-viz-* hues the
 * sidebar uses. Static class strings on purpose: Tailwind scans source text, so
 * an interpolated `text-viz-${n}` is never generated.
 */
const ACCENT = {
  1: 'text-viz-1 bg-viz-1/12',
  2: 'text-viz-2 bg-viz-2/12',
  3: 'text-viz-3 bg-viz-3/12',
  4: 'text-viz-4 bg-viz-4/12',
  5: 'text-viz-5 bg-viz-5/12',
  6: 'text-viz-6 bg-viz-6/12',
}

export function StatTile({ label, value, delta, deltaLabel, icon, tone, hint, children, href, accent }) {
  // Rounded to the same precision that is displayed, so a change too small to
  // show as a number never renders as an arrow either.
  const shown = typeof delta === 'number' ? Math.round(delta * 10) / 10 : null
  const up = shown !== null && shown > 0
  const down = shown !== null && shown < 0
  const flat = shown !== null && shown === 0
  const Wrapper = href ? 'a' : 'div'

  return (
    <Wrapper
      href={href}
      className={`yz-card block p-4 ${href ? 'transition-colors hover:border-edge-strong' : ''}`}
    >
      <div className="flex items-start justify-between gap-2">
        <span className="yz-label mb-0">{label}</span>
        {/* With an accent the icon sits in a soft tinted square; a bare coloured
            glyph on a card reads as an alert rather than as identity. */}
        {icon &&
          (accent ? (
            <span className={`grid size-7 shrink-0 place-items-center rounded-md ${ACCENT[accent]}`}>
              <Icon as={icon} size={15} />
            </span>
          ) : (
            <Icon as={icon} size={15} className="text-faint" />
          ))}
      </div>

      <div className="mt-1.5 flex items-baseline gap-2">
        <span
          className={`yz-num text-2xl font-semibold tracking-tight ${
            tone === 'danger' ? 'text-danger' : tone === 'ok' ? 'text-ok' : 'text-fg'
          }`}
        >
          {value}
        </span>
        {shown !== null && (
          <span
            className={`inline-flex items-center gap-0.5 text-xs font-medium ${
              up ? 'text-ok' : down ? 'text-danger' : 'text-faint'
            }`}
          >
            {/* No change means no direction — a down-arrow beside "0.0%" reads as
                a decline that did not happen. */}
            <Icon as={flat ? Minus : up ? TrendingUp : TrendingDown} size={13} />
            <span className="yz-num">{Math.abs(shown).toFixed(1)}%</span>
          </span>
        )}
      </div>

      {(deltaLabel || hint) && <p className="mt-1 text-xs text-faint">{deltaLabel || hint}</p>}
      {children && <div className="mt-2.5">{children}</div>}
    </Wrapper>
  )
}

/* ---------------------------------------------------------------- States */

export function Spinner({ label = 'Loading…', className = '' }) {
  return (
    <div
      role="status"
      className={`flex items-center justify-center gap-2.5 py-12 text-sm text-muted ${className}`}
    >
      <LoaderCircle size={16} strokeWidth={2} className="animate-spin text-gold" aria-hidden="true" />
      {label}
    </div>
  )
}

/**
 * Skeletons replace the previous pattern of swapping the entire page for a
 * centred spinner, which threw away the header and collapsed the layout on every
 * load — the page appeared to break and then rebuild itself.
 */
export function Skeleton({ w = '100%', h = 14, rounded = 'sm', className = '', style }) {
  return (
    <div
      className={`yz-skeleton ${className}`}
      style={{ width: w, height: h, borderRadius: `var(--yz-radius-${rounded})`, ...style }}
      aria-hidden="true"
    />
  )
}

export function SkeletonText({ lines = 3, className = '' }) {
  const widths = ['92%', '78%', '85%', '64%', '70%']
  return (
    <div className={`space-y-2 ${className}`} aria-hidden="true">
      {Array.from({ length: lines }, (_, i) => (
        <Skeleton key={i} w={widths[i % widths.length]} />
      ))}
    </div>
  )
}

/** Placeholder that matches a table's shape, so nothing jumps when data lands. */
export function SkeletonTable({ rows = 6, cols = 4 }) {
  return (
    <div className="p-4" aria-busy="true" aria-label="Loading">
      {Array.from({ length: rows }, (_, r) => (
        <div key={r} className="flex items-center gap-4 border-b border-divider py-3 last:border-0">
          {Array.from({ length: cols }, (_, c) => (
            <Skeleton key={c} w={c === 0 ? '18%' : c === cols - 1 ? '12%' : '24%'} />
          ))}
        </div>
      ))}
    </div>
  )
}

export function EmptyState({ title, children, action, icon = Inbox }) {
  return (
    <div className="px-6 py-14 text-center">
      <div className="mx-auto mb-3.5 grid size-11 place-items-center rounded-full border border-edge bg-surface2 text-faint">
        <Icon as={icon} size={19} />
      </div>
      <h3 className="font-display text-lg text-fg">{title}</h3>
      {children && <p className="mx-auto mt-1 max-w-md text-sm text-muted">{children}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}

export function ProgressBar({ value, max = 100, label, tone = 'agate' }) {
  const pct = Math.max(0, Math.min(100, (value / max) * 100))
  const bg = tone === 'danger' ? 'bg-danger' : tone === 'warn' ? 'bg-warn' : tone === 'ok' ? 'bg-ok' : 'bg-agate'
  return (
    <div>
      {label && (
        <div className="mb-1 flex items-center justify-between text-xs text-muted">
          <span>{label}</span>
          <span className="yz-num">{Math.round(pct)}%</span>
        </div>
      )}
      <div
        role="progressbar"
        aria-valuenow={Math.round(pct)}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label}
        className="h-1.5 overflow-hidden rounded-full bg-surface2"
      >
        <div className={`h-full rounded-full ${bg} transition-[width]`} style={{ width: `${pct}%` }} />
      </div>
    </div>
  )
}
