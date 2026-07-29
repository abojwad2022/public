import { useEffect, useMemo, useRef } from 'react'
import { useParams } from 'react-router-dom'
import { boot } from '../api/client.js'
import { PageHeader } from '../components/Layout.jsx'

/**
 * Yazan Rewards screens live in the yazan-social-rewards plugin as self-contained
 * WordPress admin widgets. Rather than re-implement them here, each is shown inside
 * an iframe of the plugin's chrome-less render at `/?yzrw_embed={screen}` — the real,
 * WordPress-authenticated screen, always in sync. Access is enforced server-side
 * (manage_yazan_rewards) on both the embed page and every REST call it makes.
 *
 * The embed inherits the dashboard's own design tokens via a Yazan skin themed by
 * the `theme` query param we pass, and its background is the dashboard canvas — so
 * with no iframe border and the frame auto-sized to its content (same-origin), the
 * screen reads as a native dashboard page rather than a boxed-in iframe.
 */
const SCREENS = {
  rules: 'Rules',
  points: 'Points',
  rewards: 'Rewards',
  services: 'Service Queue',
  campaigns: 'Campaigns',
  referrals: 'Referrals',
  social: 'Social',
  fraud: 'Fraud',
  analytics: 'Analytics',
  notifications: 'Notifications',
  integrity: 'Data Integrity',
}

const readTheme = () =>
  document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark'

export default function RewardsEmbed() {
  const { screen } = useParams()
  const key = SCREENS[screen] ? screen : 'analytics'
  const base = (boot.homeUrl || '/').replace(/\/$/, '')
  const iframeRef = useRef(null)

  // Theme is baked into the iframe URL for a correct first paint. Captured per
  // screen (useMemo on key) so a live theme toggle does NOT change src / reload —
  // the observer below re-tints the already-loaded embed in place instead.
  const initialTheme = useMemo(() => readTheme(), [key])
  const src = `${base}/?yzrw_embed=${encodeURIComponent(key)}&theme=${initialTheme}`

  useEffect(() => {
    const iframe = iframeRef.current
    if (!iframe) return undefined

    let contentObserver

    // Match the iframe height to its content so the whole dashboard scrolls as one
    // page — no inner scrollbar, no fixed box height. Collapse to 0 first so the
    // measurement is not floored by the frame's current height (otherwise the
    // height only ever grows — a shrink, e.g. a queue row removed or a view
    // switched, would leave a dead gap that never reclaims).
    const fitHeight = () => {
      try {
        const doc = iframe.contentDocument
        if (doc && doc.documentElement) {
          iframe.style.height = '0px'
          const h = Math.max(
            doc.documentElement.scrollHeight,
            doc.body ? doc.body.scrollHeight : 0
          )
          iframe.style.height = `${h}px`
        }
      } catch {
        /* cross-origin — should not happen for a same-origin embed */
      }
    }

    // Keep the embed's theme in sync with the dashboard toggle, in place.
    const syncTheme = () => {
      try {
        const doc = iframe.contentDocument
        if (doc && doc.documentElement) {
          doc.documentElement.setAttribute('data-theme', readTheme())
        }
      } catch {
        /* cross-origin — the URL theme still covers first paint */
      }
    }

    const onLoad = () => {
      syncTheme()
      fitHeight()
      try {
        const doc = iframe.contentDocument
        if (doc && doc.body && 'ResizeObserver' in window) {
          contentObserver = new ResizeObserver(fitHeight)
          contentObserver.observe(doc.body)
        }
      } catch {
        /* ignore */
      }
    }

    iframe.addEventListener('load', onLoad)
    // Late async content (widgets fetching data) may resize after the observer
    // window; a low-frequency poll is a cheap safety net.
    const poll = window.setInterval(fitHeight, 1200)

    // React to the dashboard theme toggle without reloading the frame.
    const root = document.documentElement
    const themeObserver = new MutationObserver(syncTheme)
    themeObserver.observe(root, { attributes: true, attributeFilter: ['data-theme'] })

    return () => {
      iframe.removeEventListener('load', onLoad)
      if (contentObserver) contentObserver.disconnect()
      themeObserver.disconnect()
      window.clearInterval(poll)
    }
  }, [key])

  return (
    <div>
      <PageHeader title={`Yazan Rewards — ${SCREENS[key]}`} subtitle="Managed in the Yazan Rewards plugin" />
      <iframe
        ref={iframeRef}
        key={key}
        src={src}
        title={`Yazan Rewards — ${SCREENS[key]}`}
        scrolling="no"
        className="w-full block bg-canvas"
        style={{ height: '60vh', border: 0 }}
      />
    </div>
  )
}
