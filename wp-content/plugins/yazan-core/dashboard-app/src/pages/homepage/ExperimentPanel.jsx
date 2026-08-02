/**
 * The A/B test panel.
 *
 * Deliberately plain. Every number on this screen is one somebody will make a decision with, so
 * there is no chart, no colour-coded verdict, and no "winner!" banner — those invite a call that
 * the data has not earned. What it does say out loud: how many visitors each arm has actually had,
 * and that a rate below the threshold means nothing yet.
 *
 * There is no statistical-significance figure here on purpose. Two counters and no experiment
 * design cannot produce one honestly.
 */
import { useCallback, useEffect, useState } from 'react'
import { homepageApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { Alert, Badge, Button, Field, Modal, Select } from '../../components/ui/index.js'
import { Can } from '../../components/Protected.jsx'

const MONEY = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export default function ExperimentPanel({ open, onClose, docKey, documents, onPromoted }) {
  const toast = useToast()
  const [state, setState] = useState(null)
  const [variant, setVariant] = useState('')
  const [split, setSplit] = useState(50)
  const [busy, setBusy] = useState(false)
  const [showDays, setShowDays] = useState(false)

  const load = useCallback(async () => {
    try {
      const data = await homepageApi.experiment({ doc: docKey })
      setState(data)

      if (data.experiment) {
        setVariant(data.experiment.variant)
        setSplit(data.experiment.split)
      }
    } catch (err) {
      toast.error(err.message)
    }
  }, [docKey, toast])

  useEffect(() => {
    if (open) load()
  }, [open, load])

  const act = async (work, message) => {
    setBusy(true)
    try {
      await work()
      await load()
      if (message) toast.success(message)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  /**
   * Turn the CSV the server built into a file on disk.
   *
   * The text arrives through the same authenticated REST call as everything else on this screen;
   * only the last three lines are about downloading. A link to a download URL would have needed
   * its own way of proving who is asking.
   */
  const download = async () => {
    setBusy(true)
    try {
      const { filename, csv } = await homepageApi.exportExperiment({ doc: docKey })
      const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
      const link = document.createElement('a')
      link.href = url
      link.download = filename || 'ab-test.csv'
      document.body.appendChild(link)
      link.click()
      link.remove()
      // Revoked on the next tick: Safari has not finished reading the blob when click() returns.
      setTimeout(() => URL.revokeObjectURL(url), 0)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const experiment = state?.experiment
  const running = Boolean(experiment?.running)
  const days = state?.daily || []

  // The control cannot be its own challenger. A layout that is bound to a page is still a fair
  // challenger — it would render on the front page for the variant half and on its own URL as
  // usual — so it stays in the list rather than being filtered out on a hunch.
  const candidates = (documents || []).filter((entry) => entry.doc_key !== docKey)

  return (
    <Modal open={open} onClose={onClose} title="A/B test" size="lg">
      <div className="space-y-4">
        <p className="text-2xs text-muted">
          Half — or whatever share you choose — of visitors see another layout instead of this one.
          Everyone taking part is served an uncached page, so a test costs real server work: run it
          for a week, not a quarter.
        </p>

        <div className="grid gap-2 sm:grid-cols-2">
          <Field label="Test against">
            <Select value={variant} onChange={(e) => setVariant(e.target.value)} disabled={running}>
              <option value="">Choose a layout…</option>
              {candidates.map((entry) => (
                <option key={entry.doc_key} value={entry.doc_key}>
                  {entry.title || entry.doc_key}
                </option>
              ))}
            </Select>
          </Field>

          <Field label={`Share sent to the variant: ${split}%`} help="The rest keep the current layout.">
            <input
              type="range"
              min="0"
              max="100"
              step="5"
              value={split}
              disabled={running}
              onChange={(e) => setSplit(Number(e.target.value))}
              className="w-full"
            />
          </Field>
        </div>

        {running ? (
          <Alert tone="info" title="The test is running">
            Settings are locked while it runs. Changing the split halfway would mean the two halves
            were measured under different rules, and the numbers could not be compared.
          </Alert>
        ) : null}

        <div className="flex flex-wrap items-center gap-2">
          <Can perm="homepage.experiment">
            <Button
              variant="secondary"
              disabled={busy || running || !variant}
              onClick={() => act(() => homepageApi.saveExperiment({ doc: docKey, variant, split }), 'Saved.')}
            >
              Save settings
            </Button>
          </Can>

          <Can perm="homepage.experiment">
            {running ? (
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() => act(() => homepageApi.stopExperiment({ doc: docKey }), 'Stopped. Everyone sees the current layout again.')}
              >
                Stop
              </Button>
            ) : (
              <Button
                disabled={busy || !experiment}
                onClick={() => act(() => homepageApi.startExperiment({ doc: docKey }), 'Running.')}
              >
                Start
              </Button>
            )}
          </Can>

          {experiment ? (
            <Can perm="homepage.experiment">
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() =>
                  act(
                    () => homepageApi.removeExperiment({ doc: docKey }),
                    'Removed, along with its numbers.',
                  )
                }
              >
                Delete test
              </Button>
            </Can>
          ) : null}
        </div>

        {state?.results?.length ? (
          <div className="rounded border border-edge">
            <table className="w-full text-xs">
              <thead className="text-faint">
                <tr>
                  <th className="p-2 text-start">Layout</th>
                  <th className="p-2 text-end">Visitors</th>
                  <th className="p-2 text-end">Orders</th>
                  <th className="p-2 text-end">Revenue</th>
                  <th className="p-2 text-end">Converted</th>
                </tr>
              </thead>
              <tbody>
                {state.results.map((row) => (
                  <tr key={row.arm} className="border-t border-divider">
                    <td className="p-2 text-fg">
                      {row.label}{' '}
                      {row.arm !== 'control' ? <Badge tone="info">variant</Badge> : null}
                    </td>
                    <td className="yz-num p-2 text-end">{row.views}</td>
                    <td className="yz-num p-2 text-end">{row.orders}</td>
                    <td className="yz-num p-2 text-end">{MONEY(row.revenue)}</td>
                    <td className="yz-num p-2 text-end">
                      {/* A rate from a handful of visitors is noise, and printing it anyway is how
                          a coin toss gets mistaken for a result. */}
                      {row.enough_data ? `${row.conversion}%` : <span className="text-faint">too few</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}

        {days.length ? (
          <div>
            <div className="flex items-center justify-between">
              <button
                type="button"
                className="text-2xs text-muted underline decoration-dotted hover:text-fg"
                onClick={() => setShowDays((value) => !value)}
              >
                {showDays ? 'Hide the day-by-day figures' : `Show the day-by-day figures (${days.length} days)`}
              </button>

              <Button variant="secondary" disabled={busy} onClick={download}>
                Download CSV
              </Button>
            </div>

            {showDays ? (
              <div className="mt-2 max-h-64 overflow-auto rounded border border-edge">
                <table className="w-full text-xs">
                  <thead className="sticky top-0 bg-surface text-faint">
                    <tr>
                      <th className="p-2 text-start">Day</th>
                      <th className="p-2 text-start">Layout</th>
                      <th className="p-2 text-end">Visitors</th>
                      <th className="p-2 text-end">Orders</th>
                      <th className="p-2 text-end">Revenue</th>
                    </tr>
                  </thead>
                  <tbody>
                    {days.map((day) =>
                      Object.entries(day.arms).map(([arm, cell], index) => (
                        <tr key={`${day.date}-${arm}`} className={index === 0 ? 'border-t border-divider' : ''}>
                          {/* The date is printed once per day, so the eye reads a block per day
                              rather than the same date twice. */}
                          <td className="yz-num p-2 text-faint">{index === 0 ? day.date : ''}</td>
                          <td className="p-2 text-fg">{cell.label}</td>
                          <td className="yz-num p-2 text-end">{cell.views}</td>
                          <td className="yz-num p-2 text-end">{cell.orders}</td>
                          <td className="yz-num p-2 text-end">{MONEY(cell.revenue)}</td>
                        </tr>
                      )),
                    )}
                  </tbody>
                </table>
              </div>
            ) : null}

            {showDays ? (
              <p className="mt-2 text-2xs text-faint">
                No conversion rate per day on purpose — one day of traffic is far below{' '}
                {state?.min_views} visitors, and a rate from it swings enough to look like a result
                when it is noise. The rate stays on the totals above.
              </p>
            ) : null}
          </div>
        ) : null}

        {state?.uplift !== null && state?.uplift !== undefined ? (
          <p className="text-xs text-muted">
            The variant is converting <strong className="text-fg">{state.uplift > 0 ? '+' : ''}{state.uplift}%</strong>{' '}
            against the current layout. That is a difference in the numbers so far, not a verdict —
            let it run until both arms are well past {state.min_views} visitors, and prefer a whole
            number of weeks so weekdays and weekends land in both.
          </p>
        ) : null}

        {experiment ? (
          <Can perm="homepage.publish">
            <div className="rounded border border-divider p-3">
              <p className="mb-2 text-2xs text-muted">
                Promoting copies the variant&rsquo;s published sections into this layout and
                publishes them, then ends the test. The variant layout itself is left untouched.
              </p>
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() =>
                  act(
                    () => homepageApi.promoteExperiment({ doc: docKey }),
                    'Promoted and published. The test is over.',
                  ).then(() => onPromoted?.())
                }
              >
                Promote the variant
              </Button>
            </div>
          </Can>
        ) : null}
      </div>

      <div className="mt-4 flex justify-end">
        <Button variant="secondary" onClick={onClose}>
          Close
        </Button>
      </div>
    </Modal>
  )
}
