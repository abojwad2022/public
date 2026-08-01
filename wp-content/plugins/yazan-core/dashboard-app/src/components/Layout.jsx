import { useCallback, useEffect, useMemo, useState } from 'react'
import { NavLink, useLocation, useNavigate } from 'react-router'
import { useAuth } from '../context/AuthContext.jsx'
import { useOrderAlerts } from '../context/OrderAlertsContext.jsx'
import { boot } from '../api/client.js'
import { isMuted, setMuted } from '../lib/chime.js'
import { getTheme, toggleTheme } from '../lib/theme.js'
import { Breadcrumbs, Dropdown, Icon, Tooltip } from './ui/index.js'
import {
  Award,
  BadgeCheck,
  Bell,
  Boxes,
  ChartNoAxesColumn,
  ChartPie,
  ChevronRight,
  Coins,
  DatabaseZap,
  ExternalLink,
  FolderTree,
  Gavel,
  Gift,
  KeyRound,
  LayoutDashboard,
  Lightbulb,
  LogOut,
  Megaphone,
  Menu,
  Moon,
  Package,
  PanelLeftClose,
  PanelLeftOpen,
  ScrollText,
  Search,
  Settings as SettingsIcon,
  Share2,
  ShieldAlert,
  ShieldCheck,
  ShoppingCart,
  SlidersHorizontal,
  Sparkles,
  Store,
  Sun,
  Tags,
  Ticket,
  UserCog,
  UserPlus,
  Users,
  Wrench,
  X,
} from './ui/icons.js'

/**
 * New-order bell.
 *
 * The toast is transient by nature — closed, or missed while the owner was on another tab. The
 * bell is the durable record: it keeps the count until the orders are actually looked at, so an
 * order can never be lost to a stray click. Right-click (or the caret) toggles the chime.
 */
function OrderBell() {
  const { unread, clear } = useOrderAlerts()
  const navigate = useNavigate()
  const [muted, setMutedState] = useState(() => isMuted())

  const label = unread > 0 ? `${unread} new ${unread === 1 ? 'order' : 'orders'}` : 'No new orders'

  return (
    <Dropdown
      label="New orders"
      trigger={({ toggle, open }) => (
        <Tooltip label={label}>
          <button
            type="button"
            onClick={() => {
              if (unread > 0) {
                clear()
                navigate('/orders')
              } else {
                toggle()
              }
            }}
            onContextMenu={(e) => {
              e.preventDefault()
              toggle()
            }}
            aria-haspopup="menu"
            aria-expanded={open}
            aria-label={label}
            className="yz-btn yz-btn-quiet yz-btn-icon yz-btn-sm relative"
          >
            <Icon as={Bell} size={16} />
            {unread > 0 ? (
              <span
                className="absolute -end-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-agate px-1 text-2xs font-medium text-white"
                aria-hidden="true"
              >
                {unread > 99 ? '99+' : unread}
              </span>
            ) : null}
          </button>
        </Tooltip>
      )}
      items={[
        {
          key: 'count',
          label,
          icon: ShoppingCart,
          disabled: true,
        },
        {
          key: 'view',
          label: 'View orders',
          icon: Package,
          onSelect: () => {
            clear()
            navigate('/orders')
          },
        },
        {
          key: 'mute',
          label: muted ? 'Unmute chime' : 'Mute chime',
          icon: Megaphone,
          separatorBefore: true,
          onSelect: () => setMutedState(setMuted(!muted)),
        },
      ]}
    />
  )
}

/**
 * Navigation is grouped by what the user is trying to DO, not by which feature
 * shipped first. The previous 13-item flat list forced a linear scan every time:
 * Products, Categories, Attributes and Inventory are one job; Orders, Customers
 * and Coupons are another; the AI screens are a third.
 */
/**
 * Icon tints, one hue per group.
 *
 * These are the six --yz-viz-* hues, already run through the colour-vision
 * validator and measured at >= 3:1 against the sidebar surface (--yz-sunken) in
 * both themes. Colour is assigned per SECTION, never per item: fourteen items
 * would need fourteen hues, which stops being distinguishable long before it
 * stops being colour-vision-safe, and a per-group hue reinforces the grouping
 * the sidebar is built around.
 *
 * A static map on purpose — Tailwind scans source text, so an interpolated
 * `text-viz-${n}` would never be generated.
 */
const TINT = {
  1: 'text-viz-1',
  2: 'text-viz-2',
  3: 'text-viz-3',
  4: 'text-viz-4',
  5: 'text-viz-5',
  6: 'text-viz-6',
}

const NAV = [
  {
    tint: 1, // agate red — the brand hue on the home anchor
    items: [{ to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true, perm: 'dashboard.view' }],
  },
  {
    section: 'Catalog',
    tint: 3, // amber — goods, closest to the gold/agate brand warmth
    items: [
      { to: '/products', label: 'Products', icon: Package, perm: 'products.view' },
      { to: '/categories', label: 'Categories', icon: FolderTree, perm: 'categories.view' },
      { to: '/attributes', label: 'Attributes', icon: Tags, perm: 'attributes.view' },
      { to: '/inventory', label: 'Inventory', icon: Boxes, perm: 'inventory.view' },
    ],
  },
  {
    section: 'Sales',
    tint: 5, // green — money moving
    items: [
      { to: '/orders', label: 'Orders', icon: ShoppingCart, perm: 'orders.view' },
      { to: '/customers', label: 'Customers', icon: Users, perm: 'customers.view' },
      { to: '/coupons', label: 'Coupons', icon: Ticket, perm: 'coupons.view' },
    ],
  },
  {
    section: 'Intelligence',
    tint: 2, // cyan — the analytical group
    items: [
      { to: '/reports', label: 'Reports', icon: ChartNoAxesColumn, perm: 'reports.view' },
      { to: '/ai', label: 'AI Studio', icon: Sparkles, end: true, perm: 'ai.use' },
      { to: '/ai/insights', label: 'AI Insights', icon: Lightbulb, perm: 'ai.insights_view' },
    ],
  },
  {
    section: 'Rewards',
    tint: 6, // magenta — loyalty, distinct from anything commercial
    perm: 'rewards.view',
    collapsible: true,
    icon: Gift,
    // Where the collapsed rail's single Rewards icon points. /rewards redirects
    // to the analytics screen, and matching on the prefix keeps the rail icon
    // highlighted across all eleven Rewards screens.
    root: '/rewards',
    items: [
      { to: '/rewards/rules', label: 'Rules', icon: Gavel },
      { to: '/rewards/points', label: 'Points', icon: Coins },
      { to: '/rewards/rewards', label: 'Rewards', icon: Award },
      { to: '/rewards/services', label: 'Service Queue', icon: Wrench },
      { to: '/rewards/campaigns', label: 'Campaigns', icon: Megaphone },
      { to: '/rewards/referrals', label: 'Referrals', icon: UserPlus },
      { to: '/rewards/social', label: 'Social', icon: Share2 },
      { to: '/rewards/fraud', label: 'Fraud', icon: ShieldAlert },
      { to: '/rewards/analytics', label: 'Analytics', icon: ChartPie },
      { to: '/rewards/notifications', label: 'Notifications', icon: Bell },
      { to: '/rewards/integrity', label: 'Data Integrity', icon: DatabaseZap },
    ],
  },
  {
    section: 'Access',
    // Reuses the System blue: TINT only defines hues 1-6 (see the map above) and all six are
    // taken. A seventh would need a new --yz-viz-7 token, and Access is utility work like System.
    tint: 4,
    // No group-level `perm` — NavSection already returns null once every item filters out, so a
    // role with none of these permissions never sees the heading.
    items: [
      { to: '/users', label: 'Users', icon: UserCog, perm: 'users.view' },
      { to: '/roles', label: 'Roles', icon: ShieldCheck, perm: 'roles.view' },
      { to: '/permissions', label: 'Permissions', icon: KeyRound, perm: 'permissions.view' },
    ],
  },
  {
    section: 'System',
    tint: 4, // blue — utility, cool and recessive
    items: [
      { to: '/settings', label: 'Settings', icon: SettingsIcon, perm: 'settings.view' },
      { to: '/ai/settings', label: 'AI Settings', icon: SlidersHorizontal, perm: 'ai.configure' },
      { to: '/activity', label: 'Activity log', icon: ScrollText, perm: 'audit.view' },
    ],
  },
]

const SIDEBAR_KEY = 'yazan-dash-sidebar'

/* ---------------------------------------------------------------- Nav item */

function NavItem({ item, collapsed, onNavigate, indented, tint }) {
  // NavLink sets aria-current="page" on the active link for free.
  const link = (
    <NavLink
      to={item.to}
      end={item.end}
      onClick={onNavigate}
      className={({ isActive }) =>
        // Active reads as a RAISED PILL (surface sits a step above the sunken
        // sidebar) rather than a colour tint — a 10% agate wash on ivory was
        // near-invisible, and this is the primary wayfinding cue in the app.
        `group relative flex w-full items-center gap-2.5 rounded-md py-2 text-sm transition-colors ${
          collapsed ? 'justify-center px-0' : indented ? 'ps-9 pe-3' : 'px-3'
        } ${
          isActive
            ? 'bg-surface font-medium text-fg shadow-1'
            : 'text-muted hover:bg-hover hover:text-fg'
        }`
      }
    >
      {({ isActive }) => (
        <>
          {/* `start-0` is logical, so the marker moves to the right edge in RTL. */}
          {isActive && (
            <span
              aria-hidden="true"
              className="absolute inset-y-1.5 start-0 w-0.5 rounded-full bg-agate"
            />
          )}
          {item.icon && <Icon as={item.icon} size={16} className={TINT[tint] || ''} />}
          {!collapsed && <span className="truncate">{item.label}</span>}
        </>
      )}
    </NavLink>
  )

  return collapsed && item.icon ? (
    <Tooltip label={item.label} placement="end" className="w-full">
      {link}
    </Tooltip>
  ) : (
    link
  )
}

/* ------------------------------------------------------------- Nav section */

function NavSection({ group, can, collapsed, onNavigate }) {
  const { pathname } = useLocation()
  const items = group.items.filter((i) => !i.perm || can(i.perm))
  const hasActive = items.some((i) => pathname === i.to || pathname.startsWith(`${i.to}/`))
  const [open, setOpen] = useState(hasActive)

  useEffect(() => {
    if (hasActive) setOpen(true)
  }, [hasActive])

  if (!items.length) return null

  // Collapsed rail has no room for section labels, so groups become icon runs
  // separated by a rule. A collapsible group (Rewards) collapses further to a
  // SINGLE icon pointing at its first screen — rendering its 11 children here
  // produced 11 identical, indistinguishable gift icons.
  if (collapsed) {
    // `end` is preserved per item — dropping it would make the Dashboard link
    // ("/" ) match every route and appear permanently active.
    const railItems = group.collapsible
      ? [{ to: group.root || items[0].to, label: group.section, icon: group.icon, end: false }]
      : items

    return (
      <div className="mt-1 space-y-0.5 border-t border-edge pt-1 first:border-0 first:pt-0">
        {railItems.map((item) => (
          <NavItem
            key={item.to}
            item={{ ...item, icon: item.icon || group.icon || ChevronRight }}
            collapsed
            tint={group.tint}
            onNavigate={onNavigate}
          />
        ))}
      </div>
    )
  }

  if (group.collapsible) {
    return (
      <div className="mt-3">
        <button
          type="button"
          onClick={() => setOpen((o) => !o)}
          aria-expanded={open}
          className="flex w-full items-center gap-2 rounded-md px-3 py-1.5 text-2xs font-medium text-faint transition-colors hover:text-muted"
        >
          {group.icon && <Icon as={group.icon} size={13} className={TINT[group.tint] || ''} />}
          <span className="flex-1 text-start">{group.section}</span>
          <Icon
            as={ChevronRight}
            size={12}
            className="transition-transform"
            style={{ transform: open ? 'rotate(90deg)' : 'rotate(var(--yz-caret-rest, 0deg))' }}
          />
        </button>
        {open && (
          <div className="mt-0.5 space-y-0.5">
            {items.map((item) => (
              <NavItem key={item.to} item={item} tint={group.tint} onNavigate={onNavigate} indented />
            ))}
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="mt-3 first:mt-0">
      {group.section && (
        <p className="px-3 pb-1 text-2xs font-medium text-faint">{group.section}</p>
      )}
      <div className="space-y-0.5">
        {items.map((item) => (
          <NavItem key={item.to} item={item} tint={group.tint} onNavigate={onNavigate} />
        ))}
      </div>
    </div>
  )
}

/* -------------------------------------------------------- Command palette */

/**
 * ⌘K / Ctrl-K jump-to-screen. With ~30 destinations spread across six groups,
 * pointing at the sidebar is the slow path for anyone who knows where they are
 * going — this is the single biggest speed win in the shell.
 */
function CommandPalette({ open, onClose, destinations }) {
  const [query, setQuery] = useState('')
  const [index, setIndex] = useState(0)
  const navigate = useNavigate()

  const results = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return destinations.slice(0, 8)
    return destinations
      .filter((d) => `${d.section || ''} ${d.label}`.toLowerCase().includes(q))
      .slice(0, 10)
  }, [query, destinations])

  useEffect(() => setIndex(0), [query])

  useEffect(() => {
    if (!open) setQuery('')
  }, [open])

  if (!open) return null

  const go = (dest) => {
    if (!dest) return
    navigate(dest.to)
    onClose()
  }

  const onKeyDown = (event) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setIndex((i) => Math.min(i + 1, results.length - 1))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setIndex((i) => Math.max(i - 1, 0))
    } else if (event.key === 'Enter') {
      event.preventDefault()
      go(results[index])
    } else if (event.key === 'Escape') {
      onClose()
    }
  }

  return (
    <div
      className="fixed inset-0 flex items-start justify-center p-4 pt-[12vh]"
      style={{ zIndex: 'var(--yz-z-modal)' }}
    >
      <div className="fixed inset-0 bg-scrim" onClick={onClose} role="presentation" aria-hidden="true" />
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Search screens"
        className="relative w-full max-w-lg overflow-hidden rounded-xl border border-edge bg-raised shadow-3"
      >
        <div className="flex items-center gap-2.5 border-b border-edge px-4">
          <Icon as={Search} size={16} className="text-faint" />
          <input
            autoFocus
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            onKeyDown={onKeyDown}
            placeholder="Jump to a screen…"
            aria-label="Jump to a screen"
            className="w-full bg-transparent py-3.5 text-sm text-fg outline-none placeholder:text-faint"
          />
          <kbd className="rounded-xs border border-edge px-1.5 py-0.5 text-2xs text-faint">Esc</kbd>
        </div>

        <ul className="max-h-80 overflow-y-auto p-1.5" role="listbox">
          {results.length === 0 && (
            <li className="px-3 py-6 text-center text-sm text-faint">No matching screen.</li>
          )}
          {results.map((dest, i) => (
            <li key={dest.to}>
              <button
                type="button"
                role="option"
                aria-selected={i === index}
                onMouseEnter={() => setIndex(i)}
                onClick={() => go(dest)}
                className={`flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-start text-sm transition-colors ${
                  i === index ? 'bg-selected text-fg' : 'text-muted'
                }`}
              >
                {dest.icon && <Icon as={dest.icon} size={15} />}
                <span className="flex-1 truncate">{dest.label}</span>
                {dest.section && <span className="text-2xs text-faint">{dest.section}</span>}
              </button>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ Layout */

export default function Layout({ children }) {
  const { user, logout, can } = useAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const [navOpen, setNavOpen] = useState(false)
  const [paletteOpen, setPaletteOpen] = useState(false)
  const [theme, setTheme] = useState(getTheme)
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem(SIDEBAR_KEY) === '1'
    } catch {
      return false
    }
  })

  const toggleCollapsed = useCallback(() => {
    setCollapsed((c) => {
      const next = !c
      try {
        localStorage.setItem(SIDEBAR_KEY, next ? '1' : '0')
      } catch {
        /* storage disabled — collapse still applies for this session */
      }
      return next
    })
  }, [])

  // Close the mobile drawer whenever the route changes.
  useEffect(() => setNavOpen(false), [pathname])

  useEffect(() => {
    const onKey = (event) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setPaletteOpen((o) => !o)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const destinations = useMemo(
    () =>
      NAV.filter((g) => !g.perm || can(g.perm)).flatMap((g) =>
        g.items
          .filter((i) => !i.perm || can(i.perm))
          .map((i) => ({ ...i, section: g.section, icon: i.icon || g.icon }))
      ),
    [can]
  )

  const handleLogout = async () => {
    await logout()
    navigate('/')
  }

  const sidebarWidth = collapsed ? 'w-16' : 'w-60'

  return (
    <div className="flex min-h-screen bg-canvas">
      <aside
        id="yz-sidebar"
        className={`fixed inset-y-0 start-0 flex ${sidebarWidth} flex-col border-e border-edge bg-sunken transition-transform lg:static lg:translate-x-0 ${
          navOpen ? 'translate-x-0' : 'translate-x-[var(--yz-slide-out)]'
        }`}
        style={{ zIndex: 'var(--yz-z-drawer)' }}
      >
        {/* Brand */}
        <div className={`flex h-14 items-center border-b border-edge ${collapsed ? 'justify-center px-2' : 'px-4'}`}>
          {collapsed ? (
            <span className="font-display text-lg text-fg">Y</span>
          ) : (
            <div className="min-w-0">
              <span className="font-display text-lg tracking-wide text-fg">YAZAN</span>
              <span className="ms-2 text-2xs text-faint">Store Manager</span>
            </div>
          )}
        </div>

        <nav aria-label="Main" className="yz-scroll-y flex-1 overflow-y-auto p-2">
          {NAV.filter((g) => !g.perm || can(g.perm)).map((group, i) => (
            <NavSection
              key={group.section || `group-${i}`}
              group={group}
              can={can}
              collapsed={collapsed}
              onNavigate={() => setNavOpen(false)}
            />
          ))}
        </nav>

        <div className={`border-t border-edge p-2 ${collapsed ? 'space-y-1' : ''}`}>
          <a
            href={boot.storeUrl}
            target="_blank"
            rel="noreferrer"
            className={`flex items-center gap-2.5 rounded-md py-2 text-sm text-muted transition-colors hover:bg-hover hover:text-fg ${
              collapsed ? 'justify-center px-0' : 'px-3'
            }`}
          >
            <Icon as={Store} size={16} />
            {!collapsed && (
              <>
                <span className="flex-1">View store</span>
                <Icon as={ExternalLink} size={13} className="text-faint rtl:-scale-x-100" />
              </>
            )}
          </a>
          <button
            type="button"
            onClick={toggleCollapsed}
            className={`hidden w-full items-center gap-2.5 rounded-md py-2 text-sm text-muted transition-colors hover:bg-hover hover:text-fg lg:flex ${
              collapsed ? 'justify-center px-0' : 'px-3'
            }`}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <Icon as={collapsed ? PanelLeftOpen : PanelLeftClose} size={16} className="rtl:-scale-x-100" />
            {!collapsed && <span>Collapse</span>}
          </button>
        </div>
      </aside>

      {navOpen && (
        <div
          className="fixed inset-0 bg-scrim lg:hidden"
          style={{ zIndex: 'var(--yz-z-overlay)' }}
          onClick={() => setNavOpen(false)}
          role="presentation"
        />
      )}

      {/* Main column */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header
          className="sticky top-0 flex h-14 items-center gap-2 border-b border-edge bg-canvas/95 px-4 backdrop-blur sm:px-6"
          style={{ zIndex: 'var(--yz-z-sticky)' }}
        >
          <button
            type="button"
            className="yz-btn yz-btn-quiet yz-btn-icon yz-btn-sm lg:hidden"
            onClick={() => setNavOpen((o) => !o)}
            aria-label={navOpen ? 'Close navigation' : 'Open navigation'}
            aria-expanded={navOpen}
            aria-controls="yz-sidebar"
          >
            <Icon as={navOpen ? X : Menu} size={18} />
          </button>

          {/* Search trigger — the palette itself is keyboard-first. */}
          <button
            type="button"
            onClick={() => setPaletteOpen(true)}
            className="flex h-8 max-w-72 flex-1 items-center gap-2 rounded-md border border-edge bg-sunken px-2.5 text-sm text-faint transition-colors hover:border-edge-strong hover:text-muted"
          >
            <Icon as={Search} size={15} />
            <span className="flex-1 text-start">Search…</span>
            <kbd className="hidden rounded-xs border border-edge px-1 text-2xs sm:block">⌘K</kbd>
          </button>

          <div className="flex-1" />

          <OrderBell />

          <Tooltip label={theme === 'light' ? 'Switch to dark' : 'Switch to light'}>
            <button
              type="button"
              onClick={() => setTheme(toggleTheme())}
              aria-label={theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme'}
              className="yz-btn yz-btn-quiet yz-btn-icon yz-btn-sm"
            >
              <Icon as={theme === 'light' ? Moon : Sun} size={16} />
            </button>
          </Tooltip>

          <Dropdown
            label="Account"
            trigger={({ toggle, open }) => (
              <button
                type="button"
                onClick={toggle}
                aria-haspopup="menu"
                aria-expanded={open}
                className="flex items-center gap-2 rounded-md px-1.5 py-1 transition-colors hover:bg-hover"
              >
                {user?.avatar ? (
                  <img src={user.avatar} alt="" className="size-7 rounded-full border border-edge" />
                ) : (
                  <span className="grid size-7 place-items-center rounded-full bg-agate text-xs font-medium text-white">
                    {(user?.name || '?').charAt(0).toUpperCase()}
                  </span>
                )}
                <span className="hidden text-sm text-fg sm:block">{user?.name}</span>
              </button>
            )}
            items={[
              {
                key: 'role',
                label: user?.roles?.length ? user.roles[0].replace(/_/g, ' ') : 'Signed in',
                icon: BadgeCheck,
                disabled: true,
              },
              {
                key: 'logout',
                label: 'Sign out',
                icon: LogOut,
                tone: 'danger',
                separatorBefore: true,
                onSelect: handleLogout,
              },
            ]}
          />
        </header>

        {/* tabIndex makes the skip link's target actually focusable. */}
        {/* min-w-0 lets a wide table scroll inside its own card rather than
            stretching this column. Clipping here was tried and reverted: it hid
            the overflow instead of making it reachable, which is strictly worse. */}
        <main id="yz-main" tabIndex={-1} className="min-w-0 flex-1 outline-none">
          <div className="mx-auto w-full min-w-0 max-w-page p-4 sm:p-6">{children}</div>
        </main>
      </div>

      <CommandPalette
        open={paletteOpen}
        onClose={() => setPaletteOpen(false)}
        destinations={destinations}
      />
    </div>
  )
}

/* -------------------------------------------------------------- PageHeader */

/**
 * `title`, `subtitle` and `actions` are the original API, unchanged. `breadcrumbs`
 * is the addition — the app had no positional cue anywhere, so a deep screen like
 * an order detail gave no sense of where it sat.
 */
export function PageHeader({ title, subtitle, actions, breadcrumbs }) {
  return (
    <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
      <div className="min-w-0">
        {breadcrumbs?.length ? <Breadcrumbs items={breadcrumbs} LinkComponent={NavLink} /> : null}
        <h1 className="font-display text-2xl text-fg">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
      </div>
      {/* Full width below sm so the primary action wraps under the title rather
          than being pushed off the right edge of a phone screen. */}
      {actions && (
        <div className="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto">{actions}</div>
      )}
    </div>
  )
}
