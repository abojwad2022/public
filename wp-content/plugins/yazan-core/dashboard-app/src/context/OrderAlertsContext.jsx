import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { api } from '../api/client.js'
import { ShoppingCart } from '../components/ui/icons.js'
import { chime } from '../lib/chime.js'
import { useToast } from './ToastContext.jsx'

const OrderAlertsContext = createContext(null)

/** How often to ask the server. Matches the 30s Heartbeat interval used in wp-admin. */
const POLL_MS = 30000

/** Toast identity — a second alert updates this one instead of stacking another banner. */
const TOAST_KEY = 'new-orders'

/**
 * Polls `yazan/v1/orders/alerts` and raises the owner's new-order alert.
 *
 * This is the dashboard counterpart of the wp-admin Heartbeat alert. Heartbeat is not an option
 * here: it needs jQuery and `wp_enqueue_script`, and the dashboard is a standalone document that
 * never calls wp_head()/wp_footer(), so nothing enqueued can reach it. A plain fetch poll on the
 * SPA's own REST namespace is the native fit.
 *
 * Must be mounted INSIDE the router — it uses useNavigate() for the toast's "View orders" action.
 */
export function OrderAlertsProvider({ children }) {
  const [unread, setUnread] = useState(0)
  const [orders, setOrders] = useState([])
  const toast = useToast()
  const navigate = useNavigate()

  // Highest order id already accounted for. A ref, not state: the poll loop reads it on every
  // tick and must never restart the interval just because it advanced.
  const lastSeen = useRef(null)

  // Keep the latest toast/navigate in refs so the polling effect can stay mounted once.
  const toastRef = useRef(toast)
  const navigateRef = useRef(navigate)
  useEffect(() => {
    toastRef.current = toast
    navigateRef.current = navigate
  }, [toast, navigate])

  const clear = useCallback(() => {
    setUnread(0)
    setOrders([])
    toastRef.current.dismissKey(TOAST_KEY)
  }, [])

  useEffect(() => {
    let cancelled = false
    let timer = null

    const announce = (count) => {
      const message =
        count > 1 ? `${count} new orders have arrived.` : 'A new order has arrived.'

      toastRef.current.info(message, {
        persist: true, // Never auto-dismiss: an order must be acknowledged, not missed.
        key: TOAST_KEY,
        icon: ShoppingCart,
        action: {
          label: 'View orders',
          onClick: () => {
            navigateRef.current('/orders')
            setUnread(0)
            setOrders([])
          },
        },
      })
      chime()
    }

    const poll = async () => {
      try {
        // First call omits `since` entirely — seed mode, so opening the dashboard does not
        // announce the whole existing order history.
        const seeding = lastSeen.current === null
        const data = await api.get('/orders/alerts', seeding ? undefined : { since: lastSeen.current })
        if (cancelled || !data) return

        if (typeof data.latest === 'number' && data.latest > 0) {
          lastSeen.current = data.latest
        } else if (seeding) {
          lastSeen.current = 0 // No orders yet; anything from here on is new.
        }

        const count = Number(data.count) || 0
        if (!seeding && count > 0) {
          setOrders(Array.isArray(data.orders) ? data.orders : [])
          setUnread((current) => {
            const next = current + count
            announce(next)
            return next
          })
        }
      } catch {
        // A failed poll is not worth interrupting the owner for — the next tick retries.
      }
    }

    poll()
    timer = setInterval(() => {
      // Background tabs are throttled and the alert would be stale anyway; skip and catch up
      // on the visibilitychange below.
      if (!document.hidden) poll()
    }, POLL_MS)

    const onVisible = () => {
      if (!document.hidden) poll()
    }
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      cancelled = true
      clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [])

  const value = useMemo(() => ({ unread, orders, clear }), [unread, orders, clear])

  return <OrderAlertsContext.Provider value={value}>{children}</OrderAlertsContext.Provider>
}

/**
 * Read the alert state. Returns a null-object outside the provider so the header bell can render
 * on screens (e.g. Login) that sit above it rather than throwing.
 */
export function useOrderAlerts() {
  return useContext(OrderAlertsContext) || { unread: 0, orders: [], clear: () => {} }
}
