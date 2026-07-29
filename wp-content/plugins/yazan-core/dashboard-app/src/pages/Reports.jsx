import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { reportsApi } from '../api/endpoints.js'
import { useMeta, formatPrice } from '../context/MetaContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import {
  Badge,
  Card,
  EmptyState,
  SegmentedControl,
  Skeleton,
  SkeletonTable,
  StatTile,
  Table,
  TBody,
  TD,
  TH,
  THead,
  TR,
} from '../components/ui/index.js'
import { Boxes, CircleCheck, Package, ShoppingCart, TrendingUp, TriangleAlert } from '../components/ui/icons.js'
import { BarSeries } from '../components/charts/TimeSeries.jsx'

const RANGES = [
  { value: 7, label: '7 days' },
  { value: 30, label: '30 days' },
  { value: 90, label: '90 days' },
  { value: 365, label: '12 months' },
]

export default function Reports() {
  const toast = useToast()
  const { meta } = useMeta()
  const [days, setDays] = useState(30)
  const [sales, setSales] = useState(null)
  const [stock, setStock] = useState(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [s, st] = await Promise.all([reportsApi.sales({ days }), reportsApi.stock()])
      setSales(s)
      setStock(st)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [days, toast])

  useEffect(() => {
    load()
  }, [load])

  const money = (value) => formatPrice(meta, value)

  if (loading || !sales) return <ReportsSkeleton />

  const totals = sales.totals

  return (
    <>
      <PageHeader
        title="Reports"
        subtitle={`${sales.range.from} → ${sales.range.to}`}
        breadcrumbs={[{ label: 'Intelligence' }, { label: 'Reports' }]}
        actions={
          <SegmentedControl items={RANGES} value={days} onChange={setDays} label="Reporting period" />
        }
      />

      <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <StatTile label="Net revenue" value={money(totals.net_revenue)} icon={TrendingUp} />
        <StatTile label="Orders" value={totals.orders} icon={ShoppingCart} />
        <StatTile label="Items sold" value={totals.items_sold} icon={Package} />
        <StatTile label="Average order" value={money(totals.average_order)} />
        <StatTile
          label="Refunded"
          value={money(totals.refunded)}
          tone={Number(totals.refunded)> 0 ? 'danger' : undefined}
        />
      </div>

      <div className="grid items-start gap-5 lg:grid-cols-3">
        <Card
          title="Revenue per day"
          subtitle={`Counts orders that are ${sales.statuses_counted.join(', ')} — refunds subtracted`}
          className="lg:col-span-2"
        >
          <BarSeries
            data={(sales.series || []).map((point) => ({
              label: point.date,
              value: Number(point.total) || 0,
              meta: `${point.orders} order${Number(point.orders) === 1 ? '' : 's'}`,
            }))}
            format={money}
            height={240}
            caption={`Daily net revenue, ${sales.range.from} to ${sales.range.to}`}
            valueHeading="Net revenue"
            emptyLabel="No revenue in this period."
          />
        </Card>

        <Card title="Best sellers" bodyClass="p-0">
          {!sales.top?.length ? (
            <EmptyState title="No sales in this period" icon={Package} />
          ) : (
            <Table>
              <THead>
                <TR>
                  <TH>Product</TH>
                  <TH align="end">Sold</TH>
                  <TH align="end">Revenue</TH>
                </TR>
              </THead>
              <TBody>
                {sales.top.map((product, index) => (
                  <TR key={product.product_id}>
                    <TD>
                      <span className="flex items-center gap-2">
                        <span className="yz-num w-4 shrink-0 text-2xs text-faint">{index + 1}</span>
                        <Link
                          to={`/products/${product.product_id}`}
                          className="text-fg transition-colors hover:text-agate-fg"
                        >
                          {product.name}
                        </Link>
                      </span>
                    </TD>
                    <TD align="end" className="yz-num whitespace-nowrap text-muted">
                      {product.qty}
                    </TD>
                    <TD align="end" className="yz-num whitespace-nowrap text-fg">
                      {money(product.revenue)}
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          )}
        </Card>
      </div>

      <div className="mt-5 grid items-start gap-5 lg:grid-cols-2">
        <StockCard
          title="Out of stock"
          icon={TriangleAlert}
          rows={stock?.out_of_stock || []}
          tone="danger"
          empty="Nothing is out of stock."
        />
        <StockCard
          title={`Low stock (≤ ${stock?.low_threshold ?? 2})`}
          icon={Boxes}
          rows={stock?.low_stock || []}
          tone="warn"
          empty="No products are running low."
        />
      </div>
    </>
  )
}

function StockCard({ title, rows, tone, empty, icon }) {
  return (
    <Card
      title={title}
      bodyClass="p-0"
      actions={<Badge tone={rows.length ? tone : 'ok'}>{rows.length}</Badge>}
    >
      {rows.length === 0 ? (
        <EmptyState title={empty} icon={CircleCheck} />
      ) : (
        <div className="yz-scroll-y max-h-80 overflow-y-auto">
          <Table>
            <THead>
              <TR>
                <TH>Product</TH>
                <TH align="end">Remaining</TH>
              </TR>
            </THead>
            <TBody>
              {rows.map((row) => (
                <TR key={row.id}>
                  <TD>
                    <Link
                      to={`/products/${row.id}`}
                      className="text-fg transition-colors hover:text-agate-fg"
                    >
                      {row.name}
                    </Link>
                    {row.sku && <span className="block font-mono text-2xs text-faint">{row.sku}</span>}
                  </TD>
                  <TD align="end" className="whitespace-nowrap">
                    {row.managed ? (
                      <Badge tone={tone}>{row.qty ?? 0} left</Badge>
                    ) : (
                      <span className="text-xs text-faint">not tracked</span>
                    )}
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        </div>
      )}
    </Card>
  )
}

function ReportsSkeleton() {
  return (
    <div aria-busy="true" aria-label="Building report">
      <div className="mb-5">
        <Skeleton w="140px" h={28} rounded="md" />
        <Skeleton w="220px" h={14} className="mt-2" />
      </div>
      <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
        {[0, 1, 2, 3, 4].map((i) => (
          <div key={i} className="yz-card p-4">
            <Skeleton w="60%" h={12} />
            <Skeleton w="75%" h={26} rounded="md" className="mt-2.5" />
          </div>
        ))}
      </div>
      <div className="grid items-start gap-5 lg:grid-cols-3">
        <div className="yz-card p-4 lg:col-span-2">
          <Skeleton w="28%" h={14} />
          <Skeleton w="100%" h={240} rounded="md" className="mt-4" />
        </div>
        <div className="yz-card">
          <SkeletonTable rows={5} cols={3} />
        </div>
      </div>
    </div>
  )
}
