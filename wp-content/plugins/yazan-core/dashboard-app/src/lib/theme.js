/**
 * Light/dark theme switching.
 *
 * The initial value is stamped on <html data-theme> before first paint by the inline boot
 * script in class-yazan-dashboard.php, so this module only ever *changes* it — it never
 * has to guess on load, which is what keeps the page from flashing the wrong theme.
 */
const STORAGE_KEY = 'yazan-dash-theme'

export function getTheme() {
  return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark'
}

export function applyTheme(theme) {
  const next = theme === 'light' ? 'light' : 'dark'
  const root = document.documentElement

  // Only animate deliberate switches, never the initial paint.
  root.classList.add('yz-theme-animating')
  root.setAttribute('data-theme', next)
  window.setTimeout(() => root.classList.remove('yz-theme-animating'), 300)

  try {
    localStorage.setItem(STORAGE_KEY, next)
  } catch {
    // Private mode / storage disabled — the theme still applies for this session.
  }
  return next
}

export function toggleTheme() {
  return applyTheme(getTheme() === 'light' ? 'dark' : 'light')
}
