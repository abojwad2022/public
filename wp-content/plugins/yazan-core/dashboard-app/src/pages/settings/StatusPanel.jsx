import { useCallback, useEffect, useState } from 'react'
import { statusApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import {
  useConfirm,
  Button,
  Card,
  Spinner,
  TBody,
  TD,
  TR,
  Table,
} from '../../components/ui/index.js'

export default function StatusPanel() {
  const [confirm, confirmDialog] = useConfirm()
  const toast = useToast()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [running, setRunning] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await statusApi.get())
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const run = async (tool) => {
    if (!(await confirm({ title: `Run “${tool.name}”?` }))) return
    setRunning(tool.key)
    try {
      const result = await statusApi.runTool(tool.key)
      toast.success(result.message || 'Done.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setRunning('')
    }
  }

  const copyReport = () => {
    const lines = [
      '### Yazan store — system report',
      ...data.environment.map((r) => `${r.label}: ${r.value}`),
      '',
      '### Counts',
      ...Object.entries(data.counts).map(([k, v]) => `${k}: ${v}`),
      '',
      '### Active plugins',
      ...data.plugins.map((p) => `${p.name} ${p.version}`),
    ]
    navigator.clipboard?.writeText(lines.join('\n'))
    toast.success('Report copied to the clipboard.')
  }

  if (loading || !data) return <Spinner />

  return (
    <>
    <div className="space-y-5">
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
        {Object.entries(data.counts).map(([key, value]) => (
          <div key={key} className="yz-card p-4">
            <span className="yz-label">{key}</span>
            <span className="block text-2xl text-fg" style={{ fontFamily: 'var(--font-display)' }}>
              {value}
            </span>
          </div>
        ))}
      </div>

      <Card
        title="Environment"
        actions={
          <button type="button" onClick={copyReport} className="text-xs text-muted hover:text-agate-fg transition-colors">
            Copy report
          </button>
        }
        bodyClass="p-0"
      >
        <Table>
          <TBody>
            {data.environment.map((row) => (
              <TR key={row.label}>
                <TD className="text-muted w-56">{row.label}</TD>
                <TD className={row.warn ? 'text-warn' : 'text-fg'}>
                  {row.value}
                  {row.warn && <span className="ms-2 text-xs">⚠</span>}
                </TD>
              </TR>
            ))}
          </TBody>
        </Table>
      </Card>

      <Card title={`Active plugins (${data.plugins.length})`} bodyClass="p-0">
        <Table>
          <TBody>
            {data.plugins.map((p) => (
              <TR key={p.name}>
                <TD className="text-fg">{p.name}</TD>
                <TD className="text-muted text-end w-24 font-mono text-xs">{p.version}</TD>
              </TR>
            ))}
          </TBody>
        </Table>
      </Card>

      <Card title="Maintenance tools">
        {!data.can_run_tools && (
          <p className="text-sm text-muted mb-3">
            Running tools requires the <code>manage_options</code > capability.
          </p>
        )}
        <ul className="divide-y divide-divider">
          {data.tools.map((tool) => (
            <li key={tool.key} className="flex flex-wrap items-center gap-3 py-3">
              <span className="flex-1 min-w-56">
                <span className="block text-sm text-fg">{tool.name}</span>
                <span className="block text-xs text-faint">{tool.description}</span>
              </span>
              <Button
                small
                onClick={() => run(tool)}
                disabled={!data.can_run_tools || running === tool.key}
              >
                {running === tool.key ? 'Running…' : tool.button}
              </Button>
            </li>
          ))}
        </ul>
        <p className="text-xs text-faint mt-3">
          Destructive tools — resetting capabilities, deleting taxes or orphaned data — are
          deliberately not offered here. Run those from WooCommerce → Status → Tools, with a backup.
        </p>
      </Card>
    </div>
      {confirmDialog}
    </>
  )
}
