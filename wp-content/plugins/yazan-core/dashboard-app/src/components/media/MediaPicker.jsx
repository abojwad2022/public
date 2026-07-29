import { useCallback, useEffect, useRef, useState } from 'react'
import { mediaApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Button, Modal, Spinner } from '../ui.jsx'

/**
 * Media Library browser + uploader. Everything it uploads becomes a real WordPress
 * attachment, so images are shared with the storefront and wp-admin.
 *
 * @param {boolean}  multiple  Allow selecting several items (gallery mode).
 * @param {Function} onSelect  Receives an array of attachment objects.
 */
export default function MediaPicker({ open, onClose, onSelect, multiple = false }) {
  const toast = useToast()
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState([])
  const fileInput = useRef(null)

  const load = useCallback(
    async (term) => {
      setLoading(true)
      try {
        const data = await mediaApi.list({ per_page: 40, search: term })
        setItems(data.items)
      } catch (err) {
        toast.error(err.message)
      } finally {
        setLoading(false)
      }
    },
    [toast],
  )

  useEffect(() => {
    if (open) {
      setSelected([])
      load(search)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const upload = async (files) => {
    if (!files?.length) return
    setUploading(true)
    try {
      const uploaded = []
      for (const file of Array.from(files)) {
        uploaded.push(await mediaApi.upload(file))
      }
      setItems((current) => [...uploaded, ...current])
      toast.success(`${uploaded.length} file${uploaded.length === 1 ? '' : 's'} uploaded.`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setUploading(false)
      if (fileInput.current) fileInput.current.value = ''
    }
  }

  const toggle = (item) => {
    setSelected((current) => {
      const exists = current.some((entry) => entry.id === item.id)
      if (exists) return current.filter((entry) => entry.id !== item.id)
      return multiple ? [...current, item] : [item]
    })
  }

  const confirm = () => {
    if (selected.length) onSelect(selected)
    onClose()
  }

  return (
    <Modal open={open} onClose={onClose} title="Media Library" wide>
      <div className="flex flex-wrap items-center gap-2 mb-4">
        <input
          type="search"
          value={search}
          placeholder="Search media…"
          onChange={(event) => setSearch(event.target.value)}
          onKeyDown={(event) => event.key === 'Enter' && load(search)}
          className="yz-input flex-1 min-w-48"
        />
        <Button type="button" onClick={() => load(search)}>
          Search
        </Button>
        <Button type="button" variant="primary" onClick={() => fileInput.current?.click()} disabled={uploading}>
          {uploading ? 'Uploading…' : 'Upload'}
        </Button>
        <input
          ref={fileInput}
          type="file"
          accept="image/*"
          multiple={multiple}
          hidden
          onChange={(event) => upload(event.target.files)}
        />
      </div>

      {loading ? (
        <Spinner />
      ) : items.length === 0 ? (
        <p className="text-sm text-muted py-10 text-center">No media found.</p>
      ) : (
        <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2 max-h-[52vh] overflow-y-auto pe-1">
          {items.map((item) => {
            const active = selected.some((entry) => entry.id === item.id)
            return (
              <button
                type="button"
                key={item.id}
                onClick={() => toggle(item)}
                title={item.title}
                className={`relative aspect-square border overflow-hidden transition-colors ${
                  active ? 'border-gold' : 'border-edge hover:border-faint'
                }`}
              >
                <img
                  src={item.thumb || item.url}
                  alt={item.alt || item.title}
                  className="size-full object-cover"
                  loading="lazy"
                />
                {active && (
                  <span className="absolute top-1 right-1 bg-gold text-oncontrast text-[10px] px-1 leading-4">
                    ✓
                  </span>
                )}
              </button>
            )
          })}
        </div>
      )}

      <div className="flex justify-end gap-2 mt-4 pt-4 border-t border-edge">
        <Button type="button" onClick={onClose}>
          Cancel
        </Button>
        <Button type="button" variant="primary" onClick={confirm} disabled={!selected.length}>
          Use {selected.length > 1 ? `${selected.length} images` : 'image'}
        </Button>
      </div>
    </Modal>
  )
}
