/**
 * Switch which store the dashboard is administering.
 *
 * ⚠️ THIS DOES NOT CHANGE WHAT THE STOREFRONT SHOWS, and that is the whole safety argument.
 *
 * The tenancy kernel resolves the store from the Host header before any plugin loads, and freezes
 * it. That is what renders the shop. This switcher answers a different question — "which store am I
 * administering" — and it answers it as a REQUEST PARAMETER: every admin call carries `?store=`,
 * and the server checks the permission IN THAT STORE before doing anything.
 *
 * So switching cannot grant rights the user does not already hold there. The alternative — teaching
 * the kernel to read a cookie — would have to trust that cookie without a permission check, because
 * the kernel runs before WordPress knows who is asking and is forbidden from calling the capability
 * API at all. That would be a way to become any store's administrator by writing one value.
 *
 * The list comes from /switchable, which is already filtered by a real per-store permission check —
 * a switcher offering a store that then refuses every write is worse than one that never offered it.
 */

import { useEffect, useState } from 'react'

import { storesApi } from '../api/endpoints.js'
import { Dropdown } from './ui/index.js'
import { Check, Store } from './ui/icons.js'

const STORAGE_KEY = 'yazan-admin-store'

/** Read the remembered choice. Local, per browser — it is a preference, not an authorisation. */
export function currentAdminStore() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    return raw ? Number(raw) : null
  } catch {
    return null
  }
}

export default function StoreSwitcher() {
  const [stores, setStores] = useState(null)
  const [active, setActive] = useState(currentAdminStore())

  useEffect(() => {
    let cancelled = false
    storesApi
      .switchable()
      .then((data) => {
        if (cancelled) return
        const items = data.items || []
        setStores(items)
        // A remembered store that no longer exists (archived, deleted, access revoked) must not
        // stick around as a phantom selection.
        setActive((prev) => (items.some((s) => s.id === prev) ? prev : items[0]?.id ?? null))
      })
      .catch(() => { if (!cancelled) setStores([]) })
    return () => { cancelled = true }
  }, [])

  // One store is the normal case and needs no chrome — showing a switcher that cannot switch is
  // noise that makes the platform look more complicated than it is.
  if (!stores || stores.length < 2) return null

  const choose = (id) => {
    setActive(id)
    try { window.localStorage.setItem(STORAGE_KEY, String(id)) } catch { /* private mode */ }
    // A full reload is deliberate: every loaded screen was fetched for the previous store, and
    // re-fetching them piecemeal is how one panel ends up showing another store's data.
    window.location.reload()
  }

  const label = stores.find((s) => s.id === active)?.name || 'Store'

  return (
    <Dropdown
      label="Switch store"
      align="end"
      trigger={({ toggle, open }) => (
        <button
          type="button"
          onClick={toggle}
          aria-haspopup="menu"
          aria-expanded={open}
          className="flex items-center gap-2 rounded-md px-2 py-1 text-sm transition-colors hover:bg-hover"
        >
          <Store />
          <span className="max-w-32 truncate">{label}</span>
        </button>
      )}
      items={stores.map((store) => ({
        key: String(store.id),
        label: store.name,
        icon: store.id === active ? Check : undefined,
        disabled: store.id === active,
        onSelect: () => choose(store.id),
      }))}
    />
  )
}
