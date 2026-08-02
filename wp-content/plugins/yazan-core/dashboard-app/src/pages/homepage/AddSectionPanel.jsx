/**
 * The section library.
 *
 * Grouped by the component's own `group`, and a component already at its instance limit is shown
 * disabled with the reason — clearer than hiding it and leaving someone hunting for a section they
 * remember existing.
 */
import { Button, Modal, Icons } from '../../components/ui/index.js'
import TemplatesList from './TemplatesList.jsx'
import { Can } from '../../components/Protected.jsx'

const GROUP_LABELS = {
  content: 'Content',
  commerce: 'Shop',
  social: 'Social proof',
  marketing: 'Marketing',
}

export default function AddSectionPanel({ open, onClose, components, sections, sectionRights, onAdd, version, onTemplateApplied, onSaveHomepageTemplate }) {
  const counts = sections.reduce((acc, section) => {
    acc[section.type] = (acc[section.type] || 0) + 1
    return acc
  }, {})

  const groups = components.reduce((acc, component) => {
    const group = component.group || 'content'
    acc[group] = acc[group] || []
    acc[group].push(component)
    return acc
  }, {})

  return (
    <Modal open={open} onClose={onClose} title="Add a section" size="lg">
      <div className="space-y-5">
        <TemplatesList
          open={open}
          version={version}
          onApplied={(result) => {
            onTemplateApplied(result)
            onClose()
          }}
        />

        {Object.entries(groups).map(([group, list]) => (
          <div key={group}>
            <p className="mb-2 text-2xs font-medium uppercase tracking-wide text-faint">
              {GROUP_LABELS[group] || group}
            </p>
            <div className="grid gap-2 sm:grid-cols-2">
              {list.map((component) => {
                const atLimit =
                  component.max_instances > 0 && (counts[component.type] || 0) >= component.max_instances
                const allowed = sectionRights?.[component.type]?.create !== false

                return (
                  <button
                    key={component.type}
                    type="button"
                    disabled={atLimit || !allowed}
                    onClick={() => onAdd(component.type)}
                    className="rounded border border-edge p-3 text-start hover:bg-surface2 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    <span className="flex items-center gap-2 text-sm text-fg">
                      <Icons.Plus size={14} />
                      {component.label}
                    </span>
                    <span className="mt-1 block text-2xs text-muted">{component.description}</span>
                    {atLimit ? (
                      <span className="mt-1 block text-2xs text-warn">
                        Already used the maximum ({component.max_instances}).
                      </span>
                    ) : null}
                    {!allowed ? (
                      <span className="mt-1 block text-2xs text-warn">
                        You do not have permission to add this one.
                      </span>
                    ) : null}
                  </button>
                )
              })}
            </div>
          </div>
        ))}
      </div>

      <div className="mt-4 flex justify-between">
        <Can perm="homepage.templates.create">
          <Button variant="secondary" onClick={onSaveHomepageTemplate}>
            Save this homepage as a template
          </Button>
        </Can>
        <Button variant="secondary" onClick={onClose}>
          Close
        </Button>
      </div>
    </Modal>
  )
}
