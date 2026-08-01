import { useEffect, useMemo, useRef } from 'react'
import { useParams } from 'react-router'
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
  // eslint-disable-next-line react-hooks/exhaustive-deps -- `key` is the re-derivation trigger, not a value the factory reads: this component is not remounted when the screen changes, so dropping it would freeze the theme at first mount.
  const initialTheme = useMemo(() => readTheme(), [key])
  const src = `${base}/?yzrw_embed=${encodeURIComponent(key)}&theme=${initialTheme}`

  useEffect(() => {
    const iframe = iframeRef.current
    if (!iframe) return undefined

    let contentObserver
    let lastH = 0

    // Match the iframe height to its content so the whole dashboard scrolls as one
    // page — no inner scrollbar, no fixed box height. The embed skin overrides
    // wp-admin's `html,body{height:100%}` to `height:auto`, so the body is content-
    // height and `body.scrollHeight` reflects the true content and shrinks as well
    // as grows. We must NOT collapse the frame to 0 to measure (an old trick): the
    // forced reflow while the frame is 0px clamps the parent scroll to the top,
    // which — fired by a timer — read as "scrolling down jumps back up". Writing
    // only on a real change means a plain scroll never touches the height at all.
    const fitHeight = () => {
      try {
        const doc = iframe.contentDocument
        if (!doc || !doc.body) return
        const h = doc.body.scrollHeight
        if (h > 0 && Math.abs(h - lastH) > 1) {
          lastH = h
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
    // The ResizeObserver on the embed body (set up in onLoad) is the sole refit
    // trigger — it fires on genuine content-size changes (late async data, a view
    // switch, the user typeahead opening) but never on scroll, so it can't fight
    // the user. No polling timer: that was what periodically yanked the page up.

    // React to the dashboard theme toggle without reloading the frame.
    const root = document.documentElement
    const themeObserver = new MutationObserver(syncTheme)
    themeObserver.observe(root, { attributes: true, attributeFilter: ['data-theme'] })

    return () => {
      iframe.removeEventListener('load', onLoad)
      if (contentObserver) contentObserver.disconnect()
      themeObserver.disconnect()
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
