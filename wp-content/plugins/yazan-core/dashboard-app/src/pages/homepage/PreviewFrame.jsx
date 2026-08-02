/**
 * The live preview.
 *
 * The iframe loads the REAL front page with `?yz_preview=1`, which makes the theme bridge render
 * the DRAFT instead of the published document for a user holding `homepage.preview`. That means the
 * preview is produced by the production renderer and the production templates: it cannot drift
 * from what ships, which is the failure mode that makes people stop trusting a builder.
 *
 * It used to reload the whole page after every autosave — three hundred queries and a white flash
 * to change one heading, with the scroll position thrown away each time. Now only the sections
 * that changed are fetched, and `assets/homepage/preview.js` swaps them in over postMessage.
 *
 * Patching is the optimisation, never the fallback. It is used ONLY when the set of sections and
 * their order are unchanged AND the agent inside the frame has said it is listening. Anything else
 * — a section added, removed, reordered, a frame that never answered, a request that failed —
 * reloads. A patch that is sometimes wrong is worse than a reload that is always right.
 */
import { useCallback, useEffect, useRef, useState } from 'react'
import { homepageApi } from '../../api/endpoints.js'
import { Icons, IconButton } from '../../components/ui/index.js'

const WIDTHS = { desktop: '100%', tablet: '820px', mobile: '400px' }

export default function PreviewFrame({ device, onDevice, signal, docKey, homeUrl }) {
  const frame = useRef(null)
  const structure = useRef(null)
  const [ready, setReady] = useState(false)

  const reload = useCallback(
    (version) => {
      if (!frame.current) return
      setReady(false)
      // The version is in the URL so the browser cannot serve its own cached copy.
      frame.current.src = `${homeUrl}?yz_preview=1&v=${version ?? Date.now()}`
    },
    [homeUrl],
  )

  useEffect(() => {
    const onMessage = (event) => {
      if (event.origin !== window.location.origin) return
      if (event.source !== frame.current?.contentWindow) return

      const data = event.data || {}

      // The agent announces itself once it is listening. Until it does, every update reloads:
      // a page served from cache, or rendered before this feature shipped, has no patcher in it.
      if (data.type === 'yzhp:ready') setReady(true)

      // It reports back the ids it could not find. A missing marker means the page is older than
      // the draft, and only a reload can reconcile that.
      if (data.type === 'yzhp:patched' && data.missed?.length) reload()
    }

    window.addEventListener('message', onMessage)
    return () => window.removeEventListener('message', onMessage)
  }, [reload])

  useEffect(() => {
    if (!signal || !frame.current) return undefined

    const first = structure.current === null
    const moved = structure.current !== signal.structure
    structure.current = signal.structure

    if (first || moved || !ready || !signal.ids?.length) {
      reload(signal.version)
      return undefined
    }

    let cancelled = false

    homepageApi
      .previewSections({ doc: docKey, ids: signal.ids.join(',') })
      .then((result) => {
        if (cancelled) return

        const sections = result?.sections || {}

        // A section the server declined to render — its component deactivated, say — must not be
        // left showing its old content as though nothing had happened.
        if (signal.ids.some((id) => typeof sections[id] !== 'string')) {
          reload(signal.version)
          return
        }

        frame.current?.contentWindow?.postMessage(
          { type: 'yzhp:patch', sections, token: String(signal.version) },
          window.location.origin,
        )
      })
      .catch(() => {
        if (!cancelled) reload(signal.version)
      })

    return () => {
      cancelled = true
    }
    // `ready` is deliberately absent from the dependencies: it flips false on every reload and
    // true again when the frame answers, and re-running this on that flip would patch a page that
    // has just rendered the change anyway.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signal, docKey, reload])

  return (
    <div className="flex h-full flex-col bg-sunken">
      <div className="flex items-center justify-between border-b border-edge px-3 py-1.5">
        <span className="text-2xs text-faint">Preview of the current draft</span>
        <div className="flex items-center gap-1">
          {Object.keys(WIDTHS).map((key) => (
            <button
              key={key}
              type="button"
              onClick={() => onDevice(key)}
              className={[
                'rounded px-2 py-1 text-2xs capitalize',
                device === key ? 'bg-surface2 text-fg' : 'text-faint hover:text-fg',
              ].join(' ')}
            >
              {key}
            </button>
          ))}
          <IconButton label="Reload the preview" onClick={() => reload()}>
            <Icons.RefreshCw />
          </IconButton>
        </div>
      </div>

      <div className="flex flex-1 justify-center overflow-hidden p-3">
        <iframe
          ref={frame}
          title="Homepage preview"
          className="h-full rounded border border-divider bg-white transition-[width]"
          style={{ width: WIDTHS[device] }}
        />
      </div>
    </div>
  )
}
