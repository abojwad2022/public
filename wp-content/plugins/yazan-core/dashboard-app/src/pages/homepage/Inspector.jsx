/**
 * The right-hand editor for the selected section — entirely generated from its schema.
 *
 * Tabs mirror the `group` each field declares, and a tab with no visible field is not drawn at all,
 * so a user without design rights sees a Content tab and nothing that hints at what they are
 * missing.
 */
import { useMemo, useState } from 'react'
import { Alert, Button, Field, Input, Switch, Icons } from '../../components/ui/index.js'
import { Can } from '../../components/Protected.jsx'
import { FieldList } from './fields/FieldRenderer.jsx'

const TABS = [
  { key: 'content', label: 'Content' },
  { key: 'design', label: 'Design' },
  { key: 'advanced', label: 'Advanced' },
]

export default function Inspector({ section, component, designSchema, can, device, blocked, onField, onDesignField, onSection, onSaveTemplate, onShare, onDetach, readOnly }) {
  const [tab, setTab] = useState('content')

  // A field is "reachable" when it names no permission, or the user holds any one it names.
  const reachable = (field) => {
    if (!field.permission) return true
    const wanted = Array.isArray(field.permission) ? field.permission : [field.permission]
    return wanted.some((slug) => can?.[slug])
  }

  const visibleTabs = useMemo(() => {
    if (!component) return []
    const fields = component.fields || []
    const shared = designSchema || []

    return TABS.filter((entry) => {
      if (entry.key === 'advanced') return true
      if (entry.key === 'design' && shared.some(reachable)) return true
      return fields.some((field) => (field.group || 'content') === entry.key && reachable(field))
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [component, can, designSchema])

  if (!section) {
    return (
      <div className="p-6 text-center text-xs text-faint">Select a section to edit it.</div>
    )
  }

  if (!component) {
    return (
      <div className="p-4">
        <Alert tone="warning" title="This component is not available">
          The plugin that provides <code>{section.type}</code> is not active. Its content is safe and
          will come back with it — nothing is being rendered meanwhile.
        </Alert>
      </div>
    )
  }

  // A shared section is edited in one place, not here. Showing its fields on this page would
  // invite someone to change every page that uses it while believing they were changing one.
  if (section.type === 'global') {
    return (
      <div className="space-y-3 p-4">
        <p className="text-sm font-medium text-fg">{section.label || 'Shared section'}</p>
        <Alert tone="info" title="This section is shared">
          It is stored once and used on several pages. Editing it changes all of them, so it is not
          edited from here.
        </Alert>
        <Can perm="homepage.sections.edit">
          <Button variant="secondary" onClick={onDetach}>
            Make this page&rsquo;s own copy
          </Button>
        </Can>
        <p className="text-2xs text-faint">
          Detaching leaves the shared section untouched and gives this page a private copy it can
          edit freely.
        </p>
      </div>
    )
  }

  const active = visibleTabs.some((entry) => entry.key === tab) ? tab : visibleTabs[0]?.key

  return (
    <div className="flex h-full flex-col">
      <div className="border-b border-edge px-3 py-2">
        <p className="truncate text-sm font-medium text-fg">{section.label || component.label}</p>
        <p className="text-2xs text-faint">{component.description}</p>
      </div>

      <div className="flex gap-1 border-b border-edge px-2 py-1">
        {visibleTabs.map((entry) => (
          <button
            key={entry.key}
            type="button"
            onClick={() => setTab(entry.key)}
            className={[
              'rounded px-2 py-1 text-2xs',
              active === entry.key ? 'bg-surface2 text-fg' : 'text-faint hover:text-fg',
            ].join(' ')}
          >
            {entry.label}
          </button>
        ))}
      </div>

      {blocked?.length ? (
        <div className="px-3 pt-3">
          <Alert tone="warning" title="Some changes were not saved">
            <ul className="mt-1 space-y-0.5">
              {blocked.map((entry) => (
                <li key={entry.field} className="text-2xs">
                  <strong>{entry.label}</strong> needs the <code>{entry.permission}</code> permission.
                </li>
              ))}
            </ul>
          </Alert>
        </div>
      ) : null}

      <div className="flex-1 overflow-y-auto p-3">
        {active === 'advanced' ? (
          <div className="space-y-4">
            <Field label="Label in the section list" help="For your own reference only. Never shown on the site.">
              <Input
                value={section.label || ''}
                disabled={readOnly}
                onChange={(e) => onSection({ label: e.target.value })}
              />
            </Field>

            <Field label="Show this section">
              <Switch
                checked={section.state !== 'disabled'}
                disabled={readOnly}
                onChange={(next) => onSection({ state: next ? 'enabled' : 'disabled' })}
              />
            </Field>

            <div className="rounded border border-divider p-3">
              <p className="mb-2 flex items-center gap-1 text-2xs font-medium uppercase tracking-wide text-faint">
                <Icons.Info size={12} /> Schedule
              </p>
              <p className="mb-2 text-2xs text-muted">
                Leave both empty to show it always. Times are in the site&rsquo;s timezone.
              </p>
              <Field label="From">
                <Input
                  type="datetime-local"
                  disabled={readOnly}
                  value={toLocalInput(section.schedule?.from)}
                  onChange={(e) =>
                    onSection({ schedule: { ...section.schedule, from: fromLocalInput(e.target.value) } })
                  }
                />
              </Field>
              <Field label="Until">
                <Input
                  type="datetime-local"
                  disabled={readOnly}
                  value={toLocalInput(section.schedule?.to)}
                  onChange={(e) =>
                    onSection({ schedule: { ...section.schedule, to: fromLocalInput(e.target.value) } })
                  }
                />
              </Field>
            </div>

            <Can perm="homepage.templates.create">
              <div className="flex flex-wrap gap-2">
                <Button variant="secondary" onClick={onSaveTemplate}>
                  Save as a template
                </Button>
                <Button variant="secondary" onClick={onShare}>
                  Share across pages
                </Button>
              </div>
            </Can>
            <p className="text-2xs text-faint">
              A <strong>template</strong> is a starting point — each use is a separate copy. A{' '}
              <strong>shared</strong> section is one thing used in several places: edit it once and
              every page changes.
            </p>
          </div>
        ) : (
          <div className="space-y-5">
            {/* The shared design fields come first: they apply to every section, so they are the
                ones an operator is looking for when they open this tab. */}
            {active === 'design' && designSchema?.length ? (
              <div>
                <p className="mb-3 text-2xs font-medium uppercase tracking-wide text-faint">
                  Spacing &amp; appearance
                </p>
                <FieldList
                  fields={designSchema}
                  content={section.design || {}}
                  onChange={onDesignField}
                  can={can}
                  device={device}
                />
              </div>
            ) : null}

            <FieldList
              fields={component.fields || []}
              content={section.content || {}}
              onChange={onField}
              can={can}
              device={device}
              group={active}
            />
          </div>
        )}
      </div>
    </div>
  )
}

/** UTC seconds → the value a datetime-local input expects, in the browser's own zone. */
function toLocalInput(timestamp) {
  if (!timestamp) return ''
  const date = new Date(Number(timestamp) * 1000)
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** datetime-local → UTC seconds. */
function fromLocalInput(value) {
  if (!value) return null
  const time = new Date(value).getTime()
  return Number.isNaN(time) ? null : Math.floor(time / 1000)
}
