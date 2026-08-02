/**
 * The section list: select, reorder, enable, duplicate, remove.
 *
 * Drag and drop is native HTML5, not a library. The dashboard's package.json is not extended for a
 * vertical list of at most a few dozen rows, and every drag has an equivalent keyboard control
 * beside it — a drag-only reorder is unusable for anyone who cannot drag.
 */
import { useState } from 'react'
import { Badge, Button, IconButton, Icons } from '../../components/ui/index.js'
import { Can } from '../../components/Protected.jsx'

export default function SectionTree({
  sections,
  components,
  selectedId,
  unavailable,
  onSelect,
  onMove,
  onToggle,
  onDuplicate,
  onRemove,
  onAdd,
  canEditSection,
  isHome = true,
}) {
  const [dragging, setDragging] = useState(null)
  const byType = Object.fromEntries(components.map((component) => [component.type, component]))
  const missing = new Set((unavailable || []).map((entry) => entry.section))

  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center justify-between border-b border-edge px-3 py-2">
        <span className="text-2xs font-medium uppercase tracking-wide text-faint">Sections</span>
        <Can perm="homepage.sections.create">
          <IconButton label="Add a section" onClick={onAdd} icon={Icons.Plus} />
        </Can>
      </div>

      <ul className="flex-1 overflow-y-auto p-2">
        {sections.map((section, index) => {
          const component = byType[section.type]
          const disabled = section.state === 'disabled'
          const unknown = missing.has(section.id)
          const editable = component ? canEditSection(component.type) : false

          return (
            <li
              key={section.id}
              draggable={editable}
              onDragStart={() => setDragging(index)}
              onDragOver={(e) => e.preventDefault()}
              onDrop={() => {
                if (dragging !== null && dragging !== index) onMove(dragging, index)
                setDragging(null)
              }}
              onDragEnd={() => setDragging(null)}
              className={[
                'mb-1 rounded border px-2 py-2',
                section.id === selectedId ? 'border-agate bg-surface2' : 'border-transparent hover:bg-surface2',
                dragging === index ? 'opacity-50' : '',
              ].join(' ')}
            >
              {/*
                Label and actions are on SEPARATE rows. Sharing one row means six icon buttons
                compete with the name for 230px, and the name loses — the first build of this
                showed "Br…" where "Brand story" should be.
              */}
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  className="min-w-0 flex-1 truncate text-start text-sm"
                  onClick={() => onSelect(section.id)}
                  title={section.label || component?.label || section.type}
                >
                  <span className={disabled ? 'text-faint line-through' : 'text-fg'}>
                    {section.label || component?.label || section.type}
                  </span>
                </button>
                {unknown ? <Badge tone="warn">n/a</Badge> : null}
              </div>

              {section.label && component ? (
                <p className="truncate text-2xs text-faint">{component.label}</p>
              ) : null}

              <div className="mt-1 flex justify-end gap-0.5">
                <Can perm="homepage.sections.sort">
                  <IconButton label="Move up" onClick={() => onMove(index, index - 1)} icon={Icons.ChevronUp} />
                </Can>
                <Can perm="homepage.sections.sort">
                  <IconButton label="Move down" onClick={() => onMove(index, index + 1)} icon={Icons.ChevronDown} />
                </Can>
                <IconButton
                  label={disabled ? 'Show this section' : 'Hide this section'}
                  onClick={() => onToggle(section.id, disabled)}
                  disabled={!editable} icon={disabled ? Icons.EyeOff : Icons.Eye} />
                <Can perm="homepage.sections.duplicate">
                  <IconButton label="Duplicate" onClick={() => onDuplicate(section.id)} icon={Icons.Copy} />
                </Can>
                <Can perm="homepage.sections.delete">
                  <IconButton label="Remove" onClick={() => onRemove(section.id)} icon={Icons.Trash2} />
                </Can>
              </div>

              {unknown ? (
                <p className="mt-1 text-2xs text-warn">
                  Its component is not available right now. The content is kept and will come back
                  with it; nothing renders meanwhile.
                </p>
              ) : null}
            </li>
          )
        })}

        {sections.length === 0 ? (
          <li className="px-2 py-6 text-center text-xs text-faint">
            {isHome
              ? 'No sections yet. The homepage is showing the theme’s own layout.'
              : 'No sections yet. The page is showing its own content until this layout is published.'}
            <Can perm="homepage.sections.create">
              <span className="mt-3 block">
                <Button variant="secondary" onClick={onAdd}>
                  Add the first section
                </Button>
              </span>
            </Can>
          </li>
        ) : null}
      </ul>
    </div>
  )
}
