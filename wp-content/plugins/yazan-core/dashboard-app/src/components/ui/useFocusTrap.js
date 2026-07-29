import { useEffect, useRef } from 'react'

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

/**
 * Traps Tab focus inside an overlay while it is open, and returns focus to
 * whatever was focused before it opened.
 *
 * Without this, Tab walks straight out of a dialog into the page behind it: the
 * keyboard user is then typing into a form they cannot see. A visible focus ring
 * on an unreachable element is worse than no ring at all, so this ships in the
 * same wave as the focus ring itself.
 *
 * @param {boolean}  open      Whether the overlay is mounted and visible.
 * @param {Function} onClose   Called on Escape.
 * @returns {object}           Ref to attach to the overlay container.
 */
export function useFocusTrap(open, onClose) {
  const ref = useRef(null)
  const restoreTo = useRef(null)

  useEffect(() => {
    if (!open) return undefined

    restoreTo.current = document.activeElement
    const node = ref.current

    // Focus the first meaningful control, falling back to the container itself
    // so screen readers announce the dialog rather than staying on the page.
    const focusFirst = () => {
      if (!node) return
      const target = node.querySelector('[data-autofocus]') || node.querySelector(FOCUSABLE)
      ;(target || node).focus()
    }
    // Wait a frame so the element exists and any entry transition has started.
    const raf = requestAnimationFrame(focusFirst)

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose?.()
        return
      }
      if (event.key !== 'Tab' || !node) return

      const items = Array.from(node.querySelectorAll(FOCUSABLE)).filter(
        (el) => el.offsetParent !== null || el === document.activeElement
      )
      if (!items.length) {
        event.preventDefault()
        return
      }

      const first = items[0]
      const last = items[items.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      cancelAnimationFrame(raf)
      document.removeEventListener('keydown', onKeyDown, true)
      document.body.style.overflow = previousOverflow
      // Only restore if focus is still inside the overlay, so we never yank it
      // away from somewhere the user has deliberately moved to.
      const active = document.activeElement
      if (restoreTo.current && (!active || active === document.body || node?.contains(active))) {
        restoreTo.current.focus?.()
      }
    }
  }, [open, onClose])

  return ref
}
