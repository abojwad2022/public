import { useCallback, useEffect, useState } from 'react'
import { aiApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { PageHeader } from '../../components/Layout.jsx'
import {
  StatTile,
  Button,
  Card,
  EmptyState,
  Spinner,
  TBody,
  TD,
  TR,
  Table,
} from '../../components/ui/index.js'

/**
 * AI Insights — the Analytics Assistant. The numbers are computed server-side from WooCommerce (so they
 * are exact); the model only interprets them into a plain-language read plus concrete next actions.
 */
const RANGES = [
  { days: 7, label: '7 days' },
  { days: 30, label: '30 days' },
  { days: 90, label: '90 days' },
]

export default function AIInsights() {
  const toast = useToast()
  const [days, setDays] = useState(30)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await aiApi.analytics({ days })
      setData(res)
      if (!res.ok) toast.error(res.error?.message || 'Could not generate insights.')
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [days, toast])

  useEffect(() => {
    load()
  }, [load])

  const m = data?.metrics
  const s = data?.suggestion
  const money = (v) => `${m?.currency || '$'} ${Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

  return (
    <>
      <PageHeader
        title="AI Insights"
        subtitle="Store performance, read and interpreted"
        actions={
          <div className="flex gap-2">
            {RANGES.map((r) => (
              <Button key={r.days} small variant={days === r.days ? 'primary' : 'ghost'} onClick={() => setDays(r.days)}>
                {r.label}
              </Button>
            ))}
            <Button small onClick={load} disabled={loading}>
              {loading ? '…' : 'Refresh'}
            </Button>
          </div>
        }
      />

      {loading && !data ? (
        <Spinner label="Reading the numbers…" />
      ) : !m ? (
        <EmptyState title="No data yet" />
      ) : (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-5">
            <StatTile label={`Revenue · ${m.days}d`} value={money(m.revenue)}  hint={trendLabel(m.revenue_trend_pct)}  />
            <StatTile label="Orders" value={m.orders} />
            <StatTile label="Avg order" value={money(m.aov)} />
            <StatTile label="Repeat rate" value={`${Number(m.repeat_rate || 0)}%`} />
            <StatTile label="Out of stock" value={m.out_of_stock ?? 0} tone={m.out_of_stock > 0 ? 'warn' : undefined} />
            <StatTile label="Customers" value={m.customers_total ?? 0} />
          </div>

          <div className="grid lg:grid-cols-2 gap-5 items-start">
            <Card title="What the AI sees">
              {loading ? (
                <Spinner label="Thinking…" />
              ) : s ? (
                <div className="flex flex-col gap-4">
                  {s.summary && <p className="text-sm text-fg">{pick(s.summary)}</p>}
                  {Array.isArray(s.insights) && s.insights.length > 0 && (
                    <div className="flex flex-col gap-3">
                      {s.insights.map((it, i) => (
                        <div key={i} className="border-s-2 border-agate ps-3">
                          <p className="text-sm text-fg font-medium">{pick(it.title)}</p>
                          <p className="text-sm text-muted">{pick(it.detail)}</p>
                        </div>
                      ))}
                    </div>
                  )}
                  {Array.isArray(s.risks) && s.risks.length > 0 && (
                    <div>
                      <span className="yz-label">Risks</span>
                      <div className="flex flex-col gap-2 mt-1">
                        {s.risks.map((it, i) => (
                          <div key={i} className="border-s-2 border-warn ps-3">
                            <p className="text-sm text-fg font-medium">{pick(it.title)}</p>
                            <p className="text-sm text-muted">{pick(it.detail)}</p>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                  {Array.isArray(s.opportunities) && s.opportunities.length > 0 && (
                    <div>
                      <span className="yz-label">Opportunities</span>
                      <div className="flex flex-col gap-2 mt-1">
                        {s.opportunities.map((it, i) => (
                          <div key={i} className="border-s-2 border-gold ps-3">
                            <p className="text-sm text-fg font-medium">{pick(it.title)}</p>
                            <p className="text-sm text-muted">{pick(it.detail)}</p>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                  {Array.isArray(s.actions) && s.actions.length > 0 && (
                    <div>
                      <span className="yz-label">Suggested actions</span>
                      <ul className="mt-1 flex flex-col gap-1.5">
                        {s.actions.map((a, i) => (
                          <li key={i} className="text-sm text-fg flex gap-2">
                            <span className="text-gold">→</span>
                            <span>{pick(a)}</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              ) : (
                <p className="text-sm text-muted">Add an AI provider key in AI Settings to generate insights.</p>
              )}
            </Card>

            <Card title="Best sellers" bodyClass="p-0">
              {!m.top_products?.length ? (
                <p className="p-6 text-sm text-muted text-center">No paid orders in this window.</p>
              ) : (
                <Table>
                  <TBody>
                    {m.top_products.map((p, i) => (
                      <TR key={i}>
                        <TD className="w-8 text-faint">{i + 1}</TD>
                        <TD className="text-fg">{p.name}</TD>
                        <TD align="end" className="text-muted whitespace-nowrap">×{p.qty}</TD>
                        <TD align="end" className="text-fg whitespace-nowrap">{money(p.revenue)}</TD>
                      </TR>
                    ))}
                  </TBody>
                </Table>
              )}
              {m.categories?.length > 0 && (
                <div className="border-t border-edge p-3">
                  <span className="yz-label">Category performance</span>
                  <Table>
                    <TBody>
                      {m.categories.map((c, i) => (
                        <TR key={i}>
                          <TD className="text-fg">{c.name}</TD>
                          <TD align="end" className="text-muted whitespace-nowrap">×{c.qty}</TD>
                          <TD align="end" className="text-fg whitespace-nowrap">{money(c.revenue)}</TD>
                        </TR>
                      ))}
                    </TBody>
                  </Table>
                </div>
              )}
              {m.weak_products?.length > 0 && (
                <div className="border-t border-edge p-3">
                  <span className="yz-label">No sales this window</span>
                  <div className="flex flex-wrap gap-2 mt-1">
                    {m.weak_products.map((n, i) => (
                      <span key={i} className="yz-badge border-edge text-faint">{n}</span>
                    ))}
                  </div>
                </div>
              )}
              {m.low_stock?.length > 0 && (
                <div className="border-t border-edge p-3">
                  <span className="yz-label">Low stock</span>
                  <div className="flex flex-wrap gap-2 mt-1">
                    {m.low_stock.map((p, i) => (
                      <span key={i} className="yz-badge border-warn text-warn">
                        {p.name} · {p.qty}
                      </span>
                    ))}
                  </div>
                </div>
              )}
            </Card>
          </div>
        </>
      )}
    </>
  )
}


function trendLabel(pct) {
  if (pct === null || pct === undefined) return 'vs prev: —'
  const n = Number(pct)
  return `${Math.abs(n)}% vs previous period`
}

function pick(value) {
  if (value == null) return ''
  if (typeof value === 'string') return value
  if (typeof value === 'object') return value.en || value.ar || ''
  return ''
}
