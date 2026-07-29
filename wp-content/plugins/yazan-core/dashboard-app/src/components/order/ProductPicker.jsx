import { useEffect, useState } from 'react'
import { productsApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Button, Modal, Spinner } from '../ui.jsx'

/**
 * Search the catalogue and pick a product to put on an order.
 * Returns the chosen product via onSelect({ id, name, price, sku, thumb }).
 */
export default function ProductPicker({ open, onClose, onSelect }) {
  const toast = useToast()
  const [term, setTerm] = useState('')
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!open) return undefined
    let cancelled = false
    setLoading(true)
    const timer = setTimeout(() => {
      productsApi
        .list({ search: term, per_page: 20, status: 'publish' })
        .then((data) => !cancelled && setItems(data.items))
        .catch((err) => !cancelled && toast.error(err.message))
        .finally(() => !cancelled && setLoading(false))
    }, 300)
    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [term, open, toast])

  return (
    <Modal open={open} onClose={onClose} title="Add a product" wide>
      <input
        type="search"
        value={term}
        autoFocus
        placeholder="Search products…"
        onChange={(event) => setTerm(event.target.value)}
        className="yz-input mb-3"
      />

      {loading ? (
        <Spinner />
      ) : items.length === 0 ? (
        <p className="text-sm text-muted py-8 text-center">No products found.</p>
      ) : (
        <ul className="max-h-[50vh] overflow-y-auto divide-y divide-divider">
          {items.map((product) => (
            <li key={product.id}>
              <button
                type="button"
                onClick={() => {
                  onSelect(product)
                  onClose()
                }}
                className="w-full flex items-center gap-3 p-2 text-start hover:bg-surface2 transition-colors"
              >
                {product.thumb ? (
                  <img src={product.thumb} alt="" className="size-10 object-cover border border-edge" />
                ) : (
                  <div className="size-10 border border-edge bg-sunken" />
                )}
                <span className="flex-1 min-w-0">
                  <span className="block text-fg truncate">{product.name}</span>
                  <span className="block text-xs text-faint">
                    {product.sku ? `SKU ${product.sku} · ` : ''}
                    {product.stock_status === 'outofstock' ? 'Out of stock' : 'In stock'}
                  </span>
                </span>
                <span className="text-sm text-fg whitespace-nowrap">{product.price_html}</span>
              </button>
            </li>
          ))}
        </ul>
      )}

      <div className="flex justify-end mt-4 pt-3 border-t border-edge">
        <Button type="button" onClick={onClose}>
          Cancel
        </Button>
      </div>
    </Modal>
  )
}
