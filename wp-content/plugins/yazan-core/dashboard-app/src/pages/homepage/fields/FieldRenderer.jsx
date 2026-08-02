/**
 * One control per schema field type — generated, never hand-written per component.
 *
 * This is the payoff of the schema-driven design: adding a section in PHP produces its whole
 * editor here with no new JSX. Every control lives in this one file on purpose — each is five to
 * twenty lines, and twenty files that each render a single input is more indirection than value.
 *
 * The server is still the authority. Everything here is convenience: it hides controls the user
 * may not use and clamps what it can, but Yazan_REST_Guard and the field permission filter decide
 * what is actually stored.
 */
import { useState } from 'react'
import MediaPicker from '../../../components/media/MediaPicker.jsx'
import { Button, Checkbox, Field, IconButton, Input, Select, Switch, Textarea, Icons } from '../../../components/ui/index.js'
import { conditionMet, getPath, responsiveValue } from '../lib/paths.js'

/**
 * Render every field of a schema against a content object.
 *
 * @param {Array}    fields    Schema fields from GET /homepage/components.
 * @param {object}   content   Current content.
 * @param {string}   prefix    Dotted path prefix (repeaters and groups pass their own).
 * @param {Function} onChange  (path, value) => void
 * @param {object}   can       Permission map from the server.
 * @param {string}   device    Active breakpoint for responsive fields.
 * @param {string}   group     Only render fields in this inspector tab.
 */
export function FieldList({ fields, content, prefix = '', onChange, can, device, group }) {
  return (
    <div className="space-y-4">
      {fields
        .filter((field) => !group || (field.group || 'content') === group)
        .filter((field) => conditionMet(field.condition, content))
        // A field the user cannot write is HIDDEN, not disabled — the requirement was explicit,
        // and a greyed control still advertises something they cannot have. `permission` may be a
        // list, in which case holding ANY of them is enough (design fields do this).
        .filter((field) => {
          if (!field.permission) return true
          const wanted = Array.isArray(field.permission) ? field.permission : [field.permission]
          return wanted.some((slug) => can?.[slug])
        })
        .map((field) => (
          <FieldRenderer
            key={field.key}
            field={field}
            path={prefix ? `${prefix}.${field.key}` : field.key}
            content={content}
            onChange={onChange}
            can={can}
            device={device}
          />
        ))}
    </div>
  )
}

function FieldRenderer({ field, path, content, onChange, can, device }) {
  const raw = getPath(content, path, field.default)

  if (field.responsive) {
    const current = responsiveValue(raw, device)
    const inherited = device !== 'desktop' && (raw?.[device] === null || raw?.[device] === undefined)

    return (
      <Field
        label={field.label}
        help={inherited ? `Inherited from the wider screen — edit to override on ${device}.` : field.help}
      >
        <Control
          field={field}
          value={current}
          onChange={(value) => onChange(`${path}.${device}`, value)}
          can={can}
        />
        {inherited ? null : (
          <button
            type="button"
            className="mt-1 text-2xs text-faint underline"
            onClick={() => onChange(`${path}.${device}`, null)}
          >
            Reset to inherited
          </button>
        )}
      </Field>
    )
  }

  if (field.type === 'group') {
    return (
      <div className="rounded border border-divider p-3">
        <p className="mb-3 text-2xs font-medium uppercase tracking-wide text-faint">{field.label}</p>
        <FieldList
          fields={field.fields || []}
          content={content}
          prefix={path}
          onChange={onChange}
          can={can}
          device={device}
        />
      </div>
    )
  }

  if (field.type === 'repeater') {
    return (
      <Repeater field={field} path={path} content={content} onChange={onChange} can={can} device={device} />
    )
  }

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      <Control field={field} value={raw} onChange={(value) => onChange(path, value)} can={can} />
    </Field>
  )
}

function Control({ field, value, onChange, can }) {
  const c = field.constraints || {}

  switch (field.type) {
    case 'textarea':
      return <Textarea rows={4} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />

    case 'richtext':
      return (
        <Textarea
          rows={4}
          value={value ?? ''}
          onChange={(e) => onChange(e.target.value)}
          placeholder="Basic formatting only: links, bold, italic."
        />
      )

    case 'number':
    case 'range':
      return (
        <Input
          type="number"
          min={c.min}
          max={c.max}
          value={value ?? 0}
          onChange={(e) => onChange(Number(e.target.value))}
        />
      )

    case 'toggle':
      return <Switch checked={Boolean(value)} onChange={(next) => onChange(next)} />

    case 'datetime':
      return (
        <Input
          type="datetime-local"
          value={toLocalInput(value)}
          onChange={(e) => onChange(fromLocalInput(e.target.value))}
        />
      )

    case 'select':
    case 'icon':
      return (
        <Select value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          {(c.choices || []).map((choice) => (
            <option key={choice} value={choice}>
              {choice}
            </option>
          ))}
        </Select>
      )

    case 'color':
      return (
        <Input
          value={value ?? ''}
          placeholder="--yz-ink or #5C1626"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'url':
      return (
        <Input
          type="url"
          value={value ?? ''}
          placeholder="https://"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'media':
    case 'video':
      return <MediaField value={Number(value) || 0} onChange={onChange} />

    case 'gallery':
      return <GalleryField value={Array.isArray(value) ? value : []} onChange={onChange} can={can} />

    case 'term_ids':
    case 'product_ids':
      return <IdListField value={Array.isArray(value) ? value : []} onChange={onChange} field={field} />

    case 'product_query':
      return <ProductQueryField value={value || {}} onChange={onChange} />

    case 'link':
    case 'button':
      return <LinkField value={value || {}} onChange={onChange} withStyle={field.type === 'button'} />

    default:
      return (
        <Input
          value={value ?? ''}
          maxLength={c.max_length || undefined}
          onChange={(e) => onChange(e.target.value)}
        />
      )
  }
}

/* ------------------------------------------------------------------ composites */

function MediaField({ value, onChange }) {
  const [open, setOpen] = useState(false)
  // Only the attachment ID is stored. The thumbnail is whatever the picker just handed us; on a
  // fresh load we show the id rather than firing a request per media field on the screen.
  const [preview, setPreview] = useState('')

  return (
    <div className="flex items-center gap-2">
      <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded border border-divider bg-sunken">
        {preview ? (
          <img src={preview} alt="" className="h-full w-full object-cover" />
        ) : (
          <span className="text-2xs text-faint">{value ? `#${value}` : '—'}</span>
        )}
      </div>
      <Button variant="secondary" onClick={() => setOpen(true)}>
        {value ? 'Replace' : 'Choose image'}
      </Button>
      {value ? (
        <IconButton
          label="Remove image"
          onClick={() => {
            setPreview('')
            onChange(0)
          }}
        >
          <Icons.X />
        </IconButton>
      ) : null}

      <MediaPicker
        open={open}
        onClose={() => setOpen(false)}
        onSelect={(items) => {
          const picked = items?.[0]
          if (picked) {
            setPreview(picked.thumbnail || picked.url || '')
            onChange(picked.id)
          }
          setOpen(false)
        }}
      />
    </div>
  )
}

function GalleryField({ value, onChange, can }) {
  const [open, setOpen] = useState(false)

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-1">
        {value.map((id) => (
          <span key={id} className="rounded bg-sunken px-2 py-1 text-2xs">
            #{id}
            <button type="button" className="ml-1 text-faint" onClick={() => onChange(value.filter((x) => x !== id))}>
              ×
            </button>
          </span>
        ))}
        {value.length === 0 ? <span className="text-2xs text-faint">No images yet.</span> : null}
      </div>
      <Button variant="secondary" onClick={() => setOpen(true)} disabled={!can?.['media.upload']}>
        Add images
      </Button>
      <MediaPicker
        open={open}
        multiple
        onClose={() => setOpen(false)}
        onSelect={(items) => {
          onChange([...value, ...items.map((item) => item.id)])
          setOpen(false)
        }}
      />
    </div>
  )
}

function IdListField({ value, onChange, field }) {
  const [draft, setDraft] = useState('')
  const max = field.constraints?.max_items || 20

  const add = () => {
    const id = Number(draft)
    if (!id || value.includes(id) || value.length >= max) return
    onChange([...value, id])
    setDraft('')
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-1">
        {value.map((id) => (
          <span key={id} className="rounded bg-sunken px-2 py-1 text-2xs">
            #{id}
            <button type="button" className="ml-1 text-faint" onClick={() => onChange(value.filter((x) => x !== id))}>
              ×
            </button>
          </span>
        ))}
        {value.length === 0 ? (
          <span className="text-2xs text-faint">Empty — the theme keeps its automatic selection.</span>
        ) : null}
      </div>
      <div className="flex gap-2">
        <Input
          value={draft}
          placeholder="ID"
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              add()
            }
          }}
        />
        <Button variant="secondary" onClick={add}>
          Add
        </Button>
      </div>
    </div>
  )
}

const SOURCES = ['best_sellers', 'latest', 'featured', 'top_rated', 'on_sale', 'category', 'tag', 'attribute', 'manual']

function ProductQueryField({ value, onChange }) {
  const set = (key, next) => onChange({ ...value, [key]: next })

  return (
    <div className="space-y-2 rounded border border-divider p-3">
      <Field label="Source">
        <Select value={value.source || 'latest'} onChange={(e) => set('source', e.target.value)}>
          {SOURCES.map((source) => (
            <option key={source} value={source}>
              {source.replace(/_/g, ' ')}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="How many">
        <Input
          type="number"
          min={1}
          max={48}
          value={value.limit ?? 4}
          onChange={(e) => set('limit', Number(e.target.value))}
        />
      </Field>
      {['category', 'tag', 'attribute'].includes(value.source) ? (
        <Field label="Term IDs" help="Comma separated.">
          <Input
            value={(value.terms || []).join(',')}
            onChange={(e) =>
              set(
                'terms',
                e.target.value
                  .split(',')
                  .map((part) => Number(part.trim()))
                  .filter(Boolean),
              )
            }
          />
        </Field>
      ) : null}
      {value.source === 'manual' ? (
        <Field label="Product IDs" help="Comma separated. Order is kept.">
          <Input
            value={(value.ids || []).join(',')}
            onChange={(e) =>
              set(
                'ids',
                e.target.value
                  .split(',')
                  .map((part) => Number(part.trim()))
                  .filter(Boolean),
              )
            }
          />
        </Field>
      ) : null}
    </div>
  )
}

function LinkField({ value, onChange, withStyle }) {
  const set = (key, next) => onChange({ ...value, [key]: next })

  return (
    <div className="space-y-2">
      <Input value={value.label ?? ''} placeholder="Label" onChange={(e) => set('label', e.target.value)} />
      <Input value={value.url ?? ''} placeholder="https://" onChange={(e) => set('url', e.target.value)} />
      {withStyle ? (
        <Select value={value.style || 'primary'} onChange={(e) => set('style', e.target.value)}>
          <option value="primary">Primary</option>
          <option value="ghost">Ghost</option>
          <option value="link">Link</option>
        </Select>
      ) : null}
      <Checkbox
        label="Open in a new tab"
        checked={Boolean(value.new_tab)}
        onChange={(next) => set('new_tab', next)}
      />
    </div>
  )
}

function Repeater({ field, path, content, onChange, can, device }) {
  const rows = getPath(content, path, []) || []
  const max = field.constraints?.max_items || 20
  const min = field.constraints?.min_items || 0

  const blank = () => {
    const row = {}
    for (const child of field.fields || []) row[child.key] = child.default
    return row
  }

  const move = (from, to) => {
    if (to < 0 || to >= rows.length) return
    const next = [...rows]
    const [row] = next.splice(from, 1)
    next.splice(to, 0, row)
    onChange(path, next)
  }

  return (
    <div className="space-y-2">
      <p className="text-2xs font-medium uppercase tracking-wide text-faint">{field.label}</p>

      {rows.map((row, index) => (
        <div key={index} className="rounded border border-divider p-3">
          <div className="mb-2 flex items-center justify-between">
            <span className="text-2xs text-faint">#{index + 1}</span>
            <div className="flex gap-1">
              <IconButton label="Move up" onClick={() => move(index, index - 1)}>
                <Icons.ChevronUp />
              </IconButton>
              <IconButton label="Move down" onClick={() => move(index, index + 1)}>
                <Icons.ChevronDown />
              </IconButton>
              {rows.length > min ? (
                <IconButton
                  label="Remove"
                  onClick={() => onChange(path, rows.filter((_, i) => i !== index))}
                >
                  <Icons.Trash2 />
                </IconButton>
              ) : null}
            </div>
          </div>
          <FieldList
            fields={field.fields || []}
            content={content}
            prefix={`${path}.${index}`}
            onChange={onChange}
            can={can}
            device={device}
          />
        </div>
      ))}

      {rows.length < max ? (
        <Button variant="secondary" onClick={() => onChange(path, [...rows, blank()])}>
          Add item
        </Button>
      ) : (
        <p className="text-2xs text-faint">This section allows {max} at most.</p>
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ datetime */

/**
 * Stored as a UTC timestamp; shown in the browser's own zone, which is what a person editing a
 * campaign actually thinks in. The two conversions live together so they cannot drift apart.
 */
function toLocalInput(timestamp) {
  const seconds = Number(timestamp)
  if (!seconds) return ''
  const date = new Date(seconds * 1000)
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function fromLocalInput(value) {
  if (!value) return 0
  const time = new Date(value).getTime()
  return Number.isNaN(time) ? 0 : Math.floor(time / 1000)
}
