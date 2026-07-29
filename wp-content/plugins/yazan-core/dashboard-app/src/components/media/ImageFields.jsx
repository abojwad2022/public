import { useState } from 'react'
import MediaPicker from './MediaPicker.jsx'
import { Button, Card } from '../ui.jsx'

/**
 * Featured image: pick / replace / remove. Stores the attachment id on the product.
 */
export function FeaturedImageField({ value, url, onChange }) {
  const [picking, setPicking] = useState(false)

  return (
    <Card title="Product image">
      {value ? (
        <div className="space-y-3">
          <img src={url} alt="" className="w-full aspect-square object-cover border border-edge" />
          <div className="flex gap-2">
            <Button type="button" small onClick={() => setPicking(true)}>
              Replace
            </Button>
            <Button type="button" small variant="danger" onClick={() => onChange(0, '')}>
              Remove
            </Button>
          </div>
        </div>
      ) : (
        <button
          type="button"
          onClick={() => setPicking(true)}
          className="w-full aspect-square border border-dashed border-edge text-muted
                     hover:border-gold hover:text-agate-fg transition-colors text-sm"
        >
          + Set product image
        </button>
      )}

      <MediaPicker
        open={picking}
        onClose={() => setPicking(false)}
        onSelect={([item]) => onChange(item.id, item.medium || item.url)}
      />
    </Card>
  )
}

/**
 * Gallery manager: add, reorder (move left/right), and remove images.
 */
export function GalleryField({ items, onChange }) {
  const [picking, setPicking] = useState(false)

  const add = (selected) => {
    const existing = new Set(items.map((item) => item.id))
    const additions = selected
      .filter((item) => !existing.has(item.id))
      .map((item) => ({ id: item.id, url: item.medium || item.url }))
    onChange([...items, ...additions])
  }

  const remove = (id) => onChange(items.filter((item) => item.id !== id))

  const move = (index, direction) => {
    const target = index + direction
    if (target < 0 || target >= items.length) return
    const next = [...items]
    ;[next[index], next[target]] = [next[target], next[index]]
    onChange(next)
  }

  return (
    <Card
      title="Product gallery"
      actions={
        <button
          type="button"
          onClick={() => setPicking(true)}
          className="text-xs text-muted hover:text-agate-fg transition-colors"
        >
          + Add
        </button>
      }
    >
      {items.length === 0 ? (
        <button
          type="button"
          onClick={() => setPicking(true)}
          className="w-full py-8 border border-dashed border-edge text-muted
                     hover:border-gold hover:text-agate-fg transition-colors text-sm"
        >
          + Add gallery images
        </button>
      ) : (
        <div className="grid grid-cols-3 gap-2">
          {items.map((item, index) => (
            <div key={item.id} className="relative group border border-edge">
              <img src={item.url} alt="" className="w-full aspect-square object-cover" />
              <div className="absolute inset-0 bg-black/70 opacity-0 group-hover:opacity-100
                              transition-opacity flex items-center justify-center gap-1">
                <button
                  type="button"
                  onClick={() => move(index, -1)}
                  disabled={index === 0}
                  className="text-fg disabled:opacity-30 px-1"
                  aria-label="Move earlier"
                >
                  ←
                </button>
                <button
                  type="button"
                  onClick={() => remove(item.id)}
                  className="text-danger px-1"
                  aria-label="Remove"
                >
                  ✕
                </button>
                <button
                  type="button"
                  onClick={() => move(index, 1)}
                  disabled={index === items.length - 1}
                  className="text-fg disabled:opacity-30 px-1"
                  aria-label="Move later"
                >
                  →
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      <MediaPicker open={picking} onClose={() => setPicking(false)} onSelect={add} multiple />
    </Card>
  )
}

/**
 * Generic single-attachment field used for the digital certificate (image or PDF).
 */
export function AttachmentField({ label, value, url, onChange }) {
  const [picking, setPicking] = useState(false)

  return (
    <div>
      <span className="yz-label">{label}</span>
      {value ? (
        <div className="flex items-center gap-2">
          <a
            href={url}
            target="_blank"
            rel="noreferrer"
            className="flex-1 truncate text-sm text-gold hover:underline"
          >
            {url?.split('/').pop()}
          </a>
          <Button type="button" small onClick={() => setPicking(true)}>
            Replace
          </Button>
          <Button type="button" small variant="danger" onClick={() => onChange(0, '')}>
            Remove
          </Button>
        </div>
      ) : (
        <Button type="button" small onClick={() => setPicking(true)}>
          Choose file
        </Button>
      )}

      <MediaPicker
        open={picking}
        onClose={() => setPicking(false)}
        onSelect={([item]) => onChange(item.id, item.url)}
      />
    </div>
  )
}
