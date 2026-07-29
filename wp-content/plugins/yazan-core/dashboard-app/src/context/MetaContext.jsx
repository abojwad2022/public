import { createContext, useContext, useEffect, useState } from 'react'
import { metaApi } from '../api/endpoints.js'

/**
 * Reference data (categories, attributes + terms, collections, shipping/tax classes, currency).
 * Fetched once after login and shared by every filter and select in the app.
 */
const MetaContext = createContext(null)

export function MetaProvider({ children }) {
  const [meta, setMeta] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    metaApi
      .taxonomies()
      .then((data) => !cancelled && setMeta(data))
      .catch((err) => !cancelled && setError(err))
    return () => {
      cancelled = true
    }
  }, [])

  return <MetaContext.Provider value={{ meta, error }}>{children}</MetaContext.Provider>
}

export function useMeta() {
  const context = useContext(MetaContext)
  if (!context) throw new Error('useMeta must be used inside <MetaProvider>')
  return context
}

/** Find a global attribute definition (with its terms) by taxonomy name, e.g. `pa_stone`. */
export function findAttribute(meta, taxonomy) {
  return meta?.attributes?.find((attribute) => attribute.taxonomy === taxonomy) || null
}

/**
 * Format a price using the store's currency settings.
 *
 * Thousands are grouped: an unseparated "12480.55" forces the reader to count
 * digits to work out the magnitude, which is exactly the wrong tax to charge on a
 * headline revenue figure. `position` is honoured so right-symbol currencies are
 * not silently rendered left.
 */
export function formatPrice(meta, value) {
  if (value === '' || value === null || value === undefined) return '—'
  const number = Number(value)
  if (Number.isNaN(number)) return '—'

  const symbol = meta?.currency?.symbol ?? '$'
  const decimals = meta?.currency?.decimals ?? 2
  const position = meta?.currency?.position ?? 'left'

  const amount = number.toLocaleString(undefined, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })

  switch (position) {
    case 'right':
      return `${amount}${symbol}`
    case 'left_space':
      return `${symbol} ${amount}`
    case 'right_space':
      return `${amount} ${symbol}`
    default:
      return `${symbol}${amount}`
  }
}
