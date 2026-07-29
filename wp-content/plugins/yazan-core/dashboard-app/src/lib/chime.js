/**
 * Short WebAudio chime for the new-order alert.
 *
 * Ported from the wp-admin alert (assets/js/admin-order-alert.js) so both surfaces sound
 * identical. Synthesised rather than loaded from a file — no asset, no network request, and it
 * cannot be blocked by a missing media type.
 */

const MUTE_KEY = 'yz-order-chime-muted'

/** @returns {boolean} Whether the owner has muted the chime. */
export function isMuted() {
  try {
    return window.localStorage.getItem(MUTE_KEY) === '1'
  } catch {
    return false // Storage disabled — audible is the safer default for an order alert.
  }
}

/**
 * Persist the mute preference.
 *
 * @param {boolean} muted
 * @returns {boolean} The value actually applied.
 */
export function setMuted(muted) {
  try {
    window.localStorage.setItem(MUTE_KEY, muted ? '1' : '0')
  } catch {
    /* storage disabled — the preference simply does not persist */
  }
  return muted
}

/**
 * Play the chime, unless muted.
 *
 * Browsers create an AudioContext in a suspended state when there has been no user gesture yet,
 * so `resume()` is attempted first — on a dashboard the owner has almost always clicked
 * something, and when they have not the promise simply rejects and the alert stays silent
 * rather than throwing.
 */
export function chime() {
  if (isMuted()) return

  try {
    const Ctx = window.AudioContext || window.webkitAudioContext
    if (!Ctx) return

    const ctx = new Ctx()
    const play = () => {
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      osc.connect(gain)
      gain.connect(ctx.destination)
      osc.type = 'sine'
      osc.frequency.value = 880
      gain.gain.setValueAtTime(0.0001, ctx.currentTime)
      gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.5)
      osc.start()
      osc.stop(ctx.currentTime + 0.5)
      osc.onended = () => {
        try {
          ctx.close()
        } catch {
          /* already closed */
        }
      }
    }

    if (ctx.state === 'suspended') {
      ctx.resume().then(play, () => {
        try {
          ctx.close()
        } catch {
          /* nothing to close */
        }
      })
    } else {
      play()
    }
  } catch {
    /* audio unavailable — the toast and the bell still carry the alert */
  }
}
