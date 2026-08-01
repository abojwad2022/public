/**
 * Permissions — the catalog browser.
 *
 * Read-only by design. The catalog is defined in code (Yazan_Permission_Registry), so there is
 * nothing to edit here; what this screen answers is "what does this permission actually control,
 * and which roles hand it out?" — the two questions you have when deciding whether a role is
 * scoped correctly.
 */
import { useCallback, useEffect, useMemo, useState } from 'react'
import { permissionsApi } from '../../api/endpoints.js'
import { PageHeader } from '../../components/Layout.jsx'
import {
  Alert,
  Badge,
  Card,
  SearchInput,
  SegmentedControl,
  SkeletonTable,
  Table,
  TBody,
  TD,
  TH,
  THead,
  Tooltip,
  TR,
} from '../../components/ui/index.js'
import { KeyRound } from '../../components/ui/icons.js'

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Available' },
  { value: 'planned', label: 'Planned' },
  { value: 'unused', label: 'Unused' },
]

export default function Permissions() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState('all')

  const load = useCallback(async () => {
    setError(null)
    try {
      setData(await permissionsApi.list())
    } catch (err) {
      setError(err)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const visible = useMemo(() => {
    if (!data) return []
    const needle = query.trim().toLowerCase()

    return data.modules
      .map((module) => ({
        ...module,
        permissions: module.permissions.filter((permission) => {
          if (filter === 'active' && permission.status !== 'active') return false
          if (filter === 'planned' && permission.status !== 'planned') return false
          if (filter === 'unused' && permission.roles > 0) return false
          if (!needle) return true
          return `${permission.slug} ${permission.label} ${permission.description} ${module.label}`
            .toLowerCase()
            .includes(needle)
        }),
      }))
      .filter((module) => module.permissions.length > 0)
  }, [data, query, filter])

  return (
    <>
      <PageHeader
        title="Permissions"
        subtitle="Every action the dashboard can gate. Defined in code, granted through roles."
        breadcrumbs={[{ label: 'Access' }, { label: 'Permissions' }]}
      />

      {error && (
        <Alert tone="danger" title="Could not load the catalog" onRetry={load} className="mb-4">
          {error.message}
        </Alert>
      )}

      <Card bodyClass="p-3">
        <div className="flex flex-wrap items-center gap-2">
          <SearchInput
            value={query}
            onChange={setQuery}
            placeholder="Search permissions…"
            className="min-w-[200px] flex-1"
          />
          <SegmentedControl
            items={FILTERS}
            value={filter}
            onChange={setFilter}
            label="Filter the catalog"
          />
          {data && (
            <span className="text-2xs tabular-nums text-muted">
              {data.total} permissions across {data.modules.length} modules
            </span>
          )}
        </div>
      </Card>

      {!data && (
        <Card bodyClass="" className="mt-3">
          <SkeletonTable rows={10} cols={4} />
        </Card>
      )}

      {data && visible.length === 0 && (
        <Card className="mt-3">
          <p className="py-6 text-center text-sm text-muted">Nothing matches that filter.</p>
        </Card>
      )}

      <div className="mt-3 space-y-3">
        {visible.map((module) => (
          <Card key={module.module} bodyClass="">
            <header className="flex flex-wrap items-center gap-2 border-b border-edge px-4 py-2.5">
              <h2 className="text-sm font-medium text-fg">{module.label}</h2>
              {module.status === 'planned' && (
                <Tooltip label="This module has not shipped yet. Its permissions can be granted now and start working when it does.">
                  <Badge tone="muted">Planned</Badge>
                </Tooltip>
              )}
              <span className="ms-auto text-2xs tabular-nums text-muted">
                {module.permissions.length}
              </span>
            </header>

            <Table>
              <THead>
                <TR>
                  <TH>Permission</TH>
                  <TH>Slug</TH>
                  <TH>What it controls</TH>
                  <TH align="end">Roles</TH>
                </TR>
              </THead>
              <TBody>
                {module.permissions.map((permission) => (
                  <TR key={permission.slug}>
                    <TD label="Permission" primary>
                      {permission.label}
                    </TD>
                    <TD label="Slug">
                      <code className="font-mono text-2xs text-muted">{permission.slug}</code>
                    </TD>
                    <TD label="What it controls" className="text-muted">
                      {permission.description || <span className="text-faint">—</span>}
                    </TD>
                    <TD label="Roles" align="end">
                      {permission.roles > 0 ? (
                        <span className="tabular-nums text-muted">{permission.roles}</span>
                      ) : (
                        <Tooltip label="No role grants this permission yet">
                          <span className="text-faint">
                            <KeyRound size={13} />
                          </span>
                        </Tooltip>
                      )}
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          </Card>
        ))}
      </div>
    </>
  )
}
