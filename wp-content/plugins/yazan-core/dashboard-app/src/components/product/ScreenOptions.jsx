import { useEffect, useState } from 'react'
import { Dropdown, Icon } from '../ui/index.js'
import { Columns3 } from '../ui/icons.js'
import { PRODUCT_COLUMNS, DEFAULT_VISIBLE_COLUMNS } from './ProductsTable.jsx'

const COLUMNS_KEY = 'yazan-dash-products-columns'
const PER_PAGE_KEY = 'yazan-dash-products-per-page'
const PER_PAGE_CHOICES = [20, 50, 100]

/**
 * wp-admin's "Screen Options" panel: which columns are shown, and how many rows per page.
 *
 * Stored in localStorage rather than user meta. WordPress keeps these per user on the
 * server; here that would mean a new REST route and a new RBAC surface for a preference
 * that changes nothing anyone else can see. The trade-off is that the choice is per
 * browser rather than per account — the same trade the theme toggle already makes.
 */
export function useScreenOptions() {
  const [columns, setColumns] = useState(() => read(COLUMNS_KEY, DEFAULT_VISIBLE_COLUMNS))
  const [perPage, setPerPage] = useState(() => {
    const stored = Number(read(PER_PAGE_KEY, 20))
    return PER_PAGE_CHOICES.includes(stored) ? stored : 20
  })

  useEffect(() => write(COLUMNS_KEY, columns), [columns])
  useEffect(() => write(PER_PAGE_KEY, perPage), [perPage])

  const toggleColumn = (key) =>
    setColumns((current) =>
      current.includes(key) ? current.filter((entry) => entry !== key) : [...current, key],
    )

  return { columns, toggleColumn, perPage, setPerPage }
}

function read(key, fallback) {
  try {
    const raw = window.localStorage.getItem(key)
    return raw === null ? fallback : JSON.parse(raw)
  } catch {
    // Private mode, disabled storage, or a value from an older shape — fall back rather
    // than taking the whole page down over a display preference.
    return fallback
  }
}

function write(key, value) {
  try {
    window.localStorage.setItem(key, JSON.stringify(value))
  } catch {
    /* Not being able to remember the preference is not worth an error. */
  }
}

export default function ScreenOptions({ columns, onToggleColumn, perPage, onPerPage }) {
  return (
    <Dropdown
      label="Screen options"
      align="end"
      trigger={({ toggle, open }) => (
        <button
          type="button"
          onClick={toggle}
          aria-haspopup="menu"
          aria-expanded={open}
          aria-label="Screen options"
          className="yz-btn yz-btn-ghost yz-btn-sm"
        >
          <Icon as={Columns3} size={15} />
          <span className="hidden sm:inline">Screen options</span>
        </button>
      )}
    >
      <div className="min-w-56">
          {/* Columns are a wide-screen concept: the phone list has one fixed row
              shape, so offering to toggle columns there controls nothing. */}
          <div className="hidden sm:block">
            <p className="px-2 pt-1 pb-1.5 text-2xs font-medium tracking-wide text-faint uppercase">
              Columns
            </p>
            {PRODUCT_COLUMNS.filter((column) => !column.locked).map((column) => (
              <label
                key={column.key}
                className="flex cursor-pointer items-center gap-2 rounded-sm px-2.5 py-1.5 text-sm transition-colors hover:bg-hover"
              >
                <input
                  type="checkbox"
                  checked={columns.includes(column.key)}
                  onChange={() => onToggleColumn(column.key)}
                  className="size-3.5 rounded-xs accent-[var(--yz-agate)]"
                />
                {column.label}
              </label>
            ))}

            <div className="my-1 h-px bg-divider" />
          </div>

          <p className="px-2 pt-1 pb-1.5 text-2xs font-medium tracking-wide text-faint uppercase">
            Rows per page
          </p>
          <div className="flex gap-1 px-2 pb-1.5">
            {PER_PAGE_CHOICES.map((choice) => (
              <button
                key={choice}
                type="button"
                onClick={() => onPerPage(choice)}
                aria-pressed={perPage === choice}
                className={`yz-num rounded-sm border px-2 py-1 text-xs transition-colors ${
                  perPage === choice
                    ? 'border-agate bg-agate-bg text-fg'
                    : 'border-edge text-muted hover:text-fg'
                }`}
              >
                {choice}
              </button>
            ))}
        </div>
      </div>
    </Dropdown>
  )
}
