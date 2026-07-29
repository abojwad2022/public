import { useRef, useState } from 'react'
import { portingApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import {
  useConfirm,
  Badge,
  Button,
  Card,
  Checkbox,
  Select,
  Spinner,
  TBody,
  TD,
  TH,
  THead,
  TR,
  Table,
} from '../../components/ui/index.js'

/**
 * CSV import/export. Import is deliberately two-step: nothing is written until a dry run has been
 * previewed and explicitly confirmed.
 */
export default function ToolsPanel() {
  const [confirm, confirmDialog] = useConfirm()
  const toast = useToast()
  const fileInput = useRef(null)

  const [exporting, setExporting] = useState(false)
  const [exportStatus, setExportStatus] = useState('')
  const [exportType, setExportType] = useState('products')
  const [importType, setImportType] = useState('products')

  const [csv, setCsv] = useState('')
  const [fileName, setFileName] = useState('')
  const [preview, setPreview] = useState(null)
  const [createMissing, setCreateMissing] = useState(false)
  const [busy, setBusy] = useState(false)

  const doExport = async () => {
    setExporting(true)
    try {
      const params = { type: exportType }
      if (exportStatus) params.status = exportStatus
      const result = await portingApi.exportCsv(params)
      // Trigger the download client-side — the request itself needed our auth nonce.
      const blob = new Blob([result.csv], { type: 'text/csv;charset=utf-8;' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = result.filename
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
      toast.success(`Exported ${result.rows} row(s).`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setExporting(false)
    }
  }

  const readFile = (file) => {
    if (!file) return
    setFileName(file.name)
    setPreview(null)
    const reader = new FileReader()
    reader.onload = () => setCsv(String(reader.result || ''))
    reader.onerror = () => toast.error('Could not read that file.')
    reader.readAsText(file)
  }

  const dryRun = async () => {
    if (!csv.trim()) {
      toast.error('Choose a CSV file first.')
      return
    }
    setBusy(true)
    try {
      setPreview(await portingApi.import(csv, true, createMissing, importType))
    } catch (err) {
      toast.error(err.message)
      setPreview(null)
    } finally {
      setBusy(false)
    }
  }

  const apply = async () => {
    const noun = importType === 'customers' ? 'account' : 'product'
    const summary = `${preview.will_update} update(s) and ${preview.will_create} new ${noun}(s)`
    if (!(await confirm({ title: `Apply ${summary}? This writes directly to your store and cannot be undone.` }))) return
    setBusy(true)
    try {
      const result = await portingApi.import(csv, false, createMissing, importType)
      toast.success(`Imported — ${result.updated} updated, ${result.created} created.`)
      setPreview(null)
      setCsv('')
      setFileName('')
      if (fileInput.current) fileInput.current.value = ''
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
    <div className="space-y-5">
      <Card title="Export">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <span className="yz-label">Data</span>
            <Select value={exportType} onChange={(e) => setExportType(e.target.value)} className="w-auto">
              <option value="products">Products</option>
              <option value="orders">Orders</option>
              <option value="customers">Customers</option>
            </Select>
          </div>
          {exportType !== 'customers' && (
            <div>
              <span className="yz-label">Status</span>
              <Select value={exportStatus} onChange={(e) => setExportStatus(e.target.value)} className="w-auto">
                <option value="">All statuses</option>
                {exportType === 'orders' ? (
                  <>
                    <option value="processing">Processing</option>
                    <option value="completed">Completed</option>
                    <option value="on-hold">On hold</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="refunded">Refunded</option>
                  </>
                ) : (
                  <>
                    <option value="publish">Published only</option>
                    <option value="draft">Drafts only</option>
                  </>
                )}
              </Select>
            </div>
          )}
          <Button variant="primary" onClick={doExport} disabled={exporting}>
            {exporting ? 'Building…' : 'Download CSV'}
          </Button>
        </div>
        <p className="yz-help">
          {exportType === 'products'
            ? 'Includes the Yazan columns — serial, verification status, silver weight and craftsmanship.'
            : exportType === 'orders'
              ? 'One row per order, with line items, ring serials and coupons.'
              : 'All user accounts with billing details, order count and lifetime spend.'}
        </p>
      </Card>

      <Card title="Import">
        <div className="space-y-4">
          <div>
            <span className="yz-label">Data</span>
            <Select
              value={importType}
              onChange={(e) => {
                setImportType(e.target.value)
                setPreview(null)
              }}
              className="w-auto"
            >
              <option value="products">Products</option>
              <option value="customers">Customers</option>
            </Select>
            <p className="yz-help">
              Orders cannot be imported — doing so would fabricate financial records. Create them
              from the Orders screen.
            </p>
          </div>

          <div>
            <span className="yz-label">CSV file</span>
            <div className="flex flex-wrap items-center gap-3">
              <input
                ref={fileInput}
                type="file"
                accept=".csv,text/csv"
                onChange={(e) => readFile(e.target.files?.[0])}
                className="yz-input flex-1 min-w-56 file:me-3 file:border-0 file:bg-transparent file:text-muted"
              />
              {fileName && <Badge tone="muted">{fileName}</Badge>}
            </div>
          </div>

          <Checkbox
            label={
              importType === 'customers'
                ? "Create accounts for emails that don't exist yet"
                : "Create products that don't match an existing id or SKU"
            }
            checked={createMissing}
            onChange={(v) => {
              setCreateMissing(v)
              setPreview(null)
            }}
            help={
              importType === 'customers'
                ? 'Off by default — new accounts get a random password and must use “forgot password”.'
                : 'Off by default — a typo in the SKU column would otherwise create duplicates.'
            }
          />

          <div className="flex gap-2">
            <Button variant="primary" onClick={dryRun} disabled={busy || !csv.trim()}>
              {busy && !preview ? 'Checking…' : 'Preview changes'}
            </Button>
            {preview && (
              <Button variant="danger" onClick={apply} disabled={busy}>
                {busy ? 'Importing…' : 'Apply import'}
              </Button>
            )}
          </div>

          <p className="text-xs text-faint">
            Nothing is written until you press <strong className="text-fg">Apply import</strong>.
            {importType === 'customers'
              ? ' Rows match on email. Imported accounts are always given the customer role and a random password — the person uses “forgot password”.'
              : ' Rows match on id first, then SKU. A serial that already belongs to another product is refused outright.'}
          </p>
        </div>
      </Card>

      {busy && !preview && <Spinner label="Reading file…" />}

      {preview && (
        <Card
          title="Preview"
          actions={
            <div className="flex gap-2">
              <Badge tone="gold">{preview.will_update} update</Badge>
              <Badge tone="ok">{preview.will_create} create</Badge>
              <Badge tone={preview.will_skip ? 'warn' : 'muted'}>{preview.will_skip} skip</Badge>
            </div>
          }
          bodyClass="p-0"
        >
          <div className="max-h-96 overflow-y-auto">
            <Table>
              <THead>
                <TR>
                  <TH className="w-16">Line</TH>
                  <TH className="w-24">Action</TH>
                  <TH>Product</TH>
                  <TH>Changes</TH>
                </TR>
              </THead>
              <TBody>
                {preview.plan.update.map((r) => (
                  <TR key={`u-${r.line}`}>
                    <TD className="text-faint">{r.line}</TD>
                    <TD>
                      <Badge tone="gold">update</Badge>
                    </TD>
                    <TD className="text-fg">
                      {r.name} <span className="text-faint">#{r.id}</span>
                    </TD>
                    <TD className="text-xs text-muted">
                      {r.changes.length ? r.changes.join(' · ') : <span className="text-faint">no change</span>}
                    </TD>
                  </TR>
                ))}
                {preview.plan.create.map((r) => (
                  <TR key={`c-${r.line}`}>
                    <TD className="text-faint">{r.line}</TD>
                    <TD>
                      <Badge tone="ok">create</Badge>
                    </TD>
                    <TD className="text-fg">{r.name}</TD>
                    <TD className="text-xs text-muted">{r.sku ? `SKU ${r.sku}` : '—'}</TD>
                  </TR>
                ))}
                {preview.plan.skip.map((r, i) => (
                  <TR key={`s-${r.line}`}>
                    <TD className="text-faint">{r.line}</TD>
                    <TD>
                      <Badge tone="warn">skip</Badge>
                    </TD>
                    <TD className="text-muted">{r.name || r.sku || '—'}</TD>
                    <TD className="text-xs text-warn">{r.reason.replace(/_/g, ' ')}</TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
        </Card>
      )}
    </div>
      {confirmDialog}
    </>
  )
}
