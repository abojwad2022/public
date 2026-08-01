import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { metaApi, ordersApi, productsApi, reportsApi } from '../api/endpoints.js'
import { useAuth } from '../context/AuthContext.jsx'
import { useMeta, formatPrice } from '../context/MetaContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import { Can } from '../components/Protected.jsx'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  Icon,
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
import {
  Boxes,
  ChartNoAxesColumn,
  CircleAlert,
  Package,
  Plus,
  ShoppingCart,
  TrendingUp,
  TriangleAlert,
} from '../components/ui/icons.js'
import { BarSeries, Sparkline } from '../components/charts/TimeSeries.jsx'

const RANGES = [
  { value: 7, label: '7 days' },
  { value: 30, label: '30 days' },
  { value: 90, label: '90 days' },
]

const ATTENTION_TONE = {
  warn: 'text-warn bg-warn-bg',
  info: 'text-info bg-info-bg',
  danger: 'text-danger bg-danger-bg',
  ok: 'text-ok bg-ok-bg',
}

/** Percentage change, guarding the divide-by-zero that a first trading period gives. */
function delta(current, previous) {
  if (!previous) return null
  return ((current - previous) / previous) * 100
}

function sum(series, key) {
  return series.reduce((total, point) => total + (Number(point[key]) || 0), 0)
}

export function DashboardHome() {
  const [days, setDays] = useState(30)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const { meta } = useMeta()
  const { can } = useAuth()
  const toast = useToast()
  const navigate = useNavigate()

  const canOrders = can('orders.view')
  const canReports = can('reports.view')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      /*
       * The previous period is derived rather than requested: /reports/sales takes
       * only `days`, so asking for twice the window and splitting the series in half
       * yields a like-for-like comparison without touching the REST contract. The
       * shorter call still supplies the authoritative totals (items sold, refunds),
       * which the series alone cannot give.
       */
      const [stats, sales, sales2x, stock, recentOrders, recentProducts, onHold, processing] =
        await Promise.all([
          metaApi.stats().catch(() => null),
          canReports ? reportsApi.sales({ days }).catch(() => null) : null,
          canReports ? reportsApi.sales({ days: days * 2 }).catch(() => null) : null,
          canReports ? reportsApi.stock().catch(() => null) : null,
          canOrders ? ordersApi.list({ per_page: 5, orderby: 'date', order: 'desc' }).catch(() => null) : null,
          productsApi.list({ per_page: 5, orderby: 'date', order: 'desc' }).catch(() => null),
          canOrders ? ordersApi.list({ per_page: 1, status: 'on-hold' }).catch(() => null) : null,
          canOrders ? ordersApi.list({ per_page: 1, status: 'processing' }).catch(() => null) : null,
        ])

      setData({ stats, sales, sales2x, stock, recentOrders, recentProducts, onHold, processing })
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [days, canOrders, canReports, toast])

  useEffect(() => {
    load()
  }, [load])

  const kpis = useMemo(() => {
    const sales = data?.sales
    if (!sales) return null

    const series = sales.series || []
    const previousSeries = (data.sales2x?.series || []).slice(0, Math.max(0, (data.sales2x?.series || []).length - series.length))

    const currentRevenue = Number(sales.totals?.net_revenue) || 0
    const currentOrders = Number(sales.totals?.orders) || 0
    const previousRevenue = sum(previousSeries, 'total')
    const previousOrders = sum(previousSeries, 'orders')

    const currentAov = currentOrders ? currentRevenue / currentOrders : 0
    const previousAov = previousOrders ? previousRevenue / previousOrders : 0

    return {
      series,
      revenue: { value: currentRevenue, delta: delta(currentRevenue, previousRevenue) },
      orders: { value: currentOrders, delta: delta(currentOrders, previousOrders) },
      aov: { value: currentAov, delta: delta(currentAov, previousAov) },
      items: { value: Number(sales.totals?.items_sold) || 0 },
      refunded: Number(sales.totals?.refunded) || 0,
    }
  }, [data])

  if (loading) return <HomeSkeleton />

  const money = (value) => formatPrice(meta, value)
  const stock = data?.stock
  const attention = [
    canOrders && {
      key: 'onhold',
      label: 'Orders on hold',
      count: data?.onHold?.total ?? 0,
      to: '/orders?status=on-hold',
      icon: CircleAlert,
      tone: 'warn',
    },
    canOrders && {
      key: 'processing',
      label: 'Orders to fulfil',
      count: data?.processing?.total ?? 0,
      to: '/orders?status=processing',
      icon: ShoppingCart,
      tone: 'info',
    },
    {
      key: 'oos',
      label: 'Out of stock',
      count: stock?.out_of_stock?.length ?? data?.stats?.outofstock ?? 0,
      to: '/inventory',
      icon: TriangleAlert,
      tone: 'danger',
    },
    {
      key: 'low',
      label: `Low stock (≤ ${stock?.low_threshold ?? 2})`,
      count: stock?.low_stock?.length ?? 0,
      to: '/inventory',
      icon: Boxes,
      tone: 'warn',
    },
  ].filter(Boolean)

  const needsAttention = attention.filter((item) => item.count > 0)

  return (
    <>
      <PageHeader
        title="Dashboard"
        subtitle={
          kpis && data?.sales?.range
            ? `${data.sales.range.from} → ${data.sales.range.to}`
            : 'Your store at a glance'
        }
        actions={
          <>
            {canReports && (
              <SegmentedControl
                items={RANGES}
                value={days}
                onChange={setDays}
                label="Reporting period"
              />
            )}
            <Can perm="products.create">
              <Button variant="primary" icon={Plus} onClick={() => navigate('/products/new')}>
                Add product
              </Button>
            </Can>
          </>
        }
      />

      {/* Commerce KPIs. A bare number answers "how much"; the delta answers
          "is that good", which is the question this screen exists to answer. */}
      {kpis ? (
        <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatTile
            label="Net revenue"
            value={money(kpis.revenue.value)}
            delta={kpis.revenue.delta}
            deltaLabel={`vs previous ${days} days`}
            icon={TrendingUp}
            accent={5}
          >
            <Sparkline points={kpis.series.map((point) => point.total)} tone="var(--yz-viz-5)" />
          </StatTile>
          <StatTile
            label="Orders"
            value={kpis.orders.value}
            delta={kpis.orders.delta}
            deltaLabel={`vs previous ${days} days`}
            icon={ShoppingCart}
            accent={4}
          >
            <Sparkline points={kpis.series.map((point) => point.orders)} tone="var(--yz-viz-4)" />
          </StatTile>
          <StatTile
            label="Average order"
            value={money(kpis.aov.value)}
            delta={kpis.aov.delta}
            deltaLabel={`vs previous ${days} days`}
            icon={ChartNoAxesColumn}
            accent={2}
          />
          {/* No danger tone here: refunds existing does not make units sold a bad
              number. The refund figure is context, carried in the hint. */}
          <StatTile
            label="Items sold"
            value={kpis.items.value}
            hint={kpis.refunded > 0 ? `${money(kpis.refunded)} refunded` : 'No refunds this period'}
            icon={Package}
            accent={3}
          />
        </div>
      ) : (
        <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatTile label="Published" value={data?.stats?.products?.publish ?? 0} icon={Package} accent={3} />
          <StatTile label="Drafts" value={data?.stats?.products?.draft ?? 0} />
          <StatTile
            label="Out of stock"
            value={data?.stats?.outofstock ?? 0}
            tone={(data?.stats?.outofstock ?? 0)> 0 ? 'danger' : undefined}
          />
          <StatTile label="Categories" value={data?.stats?.categories ?? 0} />
        </div>
      )}

      {/* Needs attention — the single highest-value addition on this screen.
          Everything here is one click from being resolved. */}
      {needsAttention.length > 0 && (
        <Card title="Needs your attention" className="mb-5" bodyClass="p-3">
          <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            {needsAttention.map((item) => (
              <li key={item.key}>
                <Link
                  to={item.to}
                  className="flex items-center gap-3 rounded-md border border-edge p-3 transition-colors hover:border-edge-strong hover:bg-hover"
                >
                  {/* Static lookup, not `text-${tone}` — Tailwind scans source text,
                      so an interpolated class name is never generated. These keep
                      the STATUS palette, not the viz hues: "on hold" and "out of
                      stock" really are warnings, and status colours are reserved
                      for meaning rather than identity. */}
                  <span
                    className={`grid size-9 shrink-0 place-items-center rounded-md ${ATTENTION_TONE[item.tone]}`}
                  >
                    <Icon as={item.icon} size={17} />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="yz-num block text-lg font-semibold text-fg">{item.count}</span>
                    <span className="block truncate text-xs text-muted">{item.label}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <div className="grid items-start gap-5 lg:grid-cols-3">
        {canReports && kpis && (
          <Card
            title="Revenue"
            subtitle={`Daily net revenue over ${days} days`}
            className="lg:col-span-2"
            actions={
              <Link to="/reports" className="text-xs text-muted transition-colors hover:text-fg">
                Full report
              </Link>
            }
          >
            <BarSeries
              data={kpis.series.map((point) => ({
                label: point.date,
                value: Number(point.total) || 0,
                meta: `${point.orders} order${Number(point.orders) === 1 ? '' : 's'}`,
              }))}
              format={money}
              caption={`Daily net revenue, ${data.sales.range.from} to ${data.sales.range.to}`}
              valueHeading="Net revenue"
              emptyLabel="No revenue in this period."
            />
          </Card>
        )}

        <Card
          title="Recently added"
          bodyClass="p-0"
          className={canReports && kpis ? '' : 'lg:col-span-2'}
          actions={
            <Link to="/products" className="text-xs text-muted transition-colors hover:text-fg">
              View all
            </Link>
          }
        >
          {!data?.recentProducts?.items?.length ? (
            <EmptyState title="No products yet" icon={Package}>
              Create your first ring to get started.
            </EmptyState>
          ) : (
            <Table>
              <THead>
                <TR>
                  <TH>Product</TH>
                  <TH align="end">Price</TH>
                  <TH>Status</TH>
                </TR>
              </THead>
              <TBody>
                {data.recentProducts.items.map((product) => (
                  <TR key={product.id}>
                    <TD>
                      <Link
                        to={`/products/${product.id}`}
                        className="text-fg transition-colors hover:text-agate-fg"
                      >
                        {product.name}
                      </Link>
                      <span className="block text-2xs text-faint">{product.date}</span>
                    </TD>
                    <TD align="end" className="yz-num whitespace-nowrap text-muted">
                      {money(product.price)}
                    </TD>
                    <TD>
                      <Badge tone={product.status === 'publish' ? 'ok' : 'muted'}>
                        {product.status}
                      </Badge>
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          )}
        </Card>
      </div>

      {canOrders && (
        <Card
          title="Recent orders"
          className="mt-5"
          bodyClass="p-0"
          actions={
            <Link to="/orders" className="text-xs text-muted transition-colors hover:text-fg">
              View all
            </Link>
          }
        >
          {!data?.recentOrders?.items?.length ? (
            <EmptyState title="No orders yet" icon={ShoppingCart}>
              Orders placed in your store will appear here.
            </EmptyState>
          ) : (
            <Table>
              <THead>
                <TR>
                  <TH>Order</TH>
                  <TH>Customer</TH>
                  <TH>Status</TH>
                  <TH>Date</TH>
                  <TH align="end">Total</TH>
                </TR>
              </THead>
              <TBody>
                {data.recentOrders.items.map((order) => (
                  <TR key={order.id}>
                    <TD>
                      <Link
                        to={`/orders/${order.id}`}
                        className="yz-num text-fg transition-colors hover:text-agate-fg"
                      >
                        #{order.number || order.id}
                      </Link>
                    </TD>
                    <TD className="text-muted">{order.customer_name || '—'}</TD>
                    <TD>
                      <Badge tone={orderTone(order.status)}>{order.status}</Badge>
                    </TD>
                    <TD className="text-muted">{order.date_created}</TD>
                    <TD align="end" className="yz-num whitespace-nowrap text-fg">
                      {money(order.total)}
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          )}
        </Card>
      )}
    </>
  )
}

/** Shared status→tone mapping so order state reads the same on every screen. */
export function orderTone(status) {
  switch (status) {
    case 'completed':
      return 'ok'
    case 'processing':
      return 'info'
    case 'on-hold':
    case 'pending':
      return 'warn'
    case 'cancelled':
    case 'failed':
    case 'refunded':
      return 'danger'
    default:
      return 'muted'
  }
}

/**
 * Matches the real layout so nothing jumps when the data lands. The previous
 * pattern replaced the whole page with a centred spinner, which threw away the
 * header and made the screen look like it was breaking and rebuilding itself.
 */
function HomeSkeleton() {
  return (
    <div aria-busy="true" aria-label="Loading dashboard">
      <div className="mb-5">
        <Skeleton w="170px" h={28} rounded="md" />
        <Skeleton w="230px" h={14} className="mt-2" />
      </div>
      <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="yz-card p-4">
            <Skeleton w="55%" h={12} />
            <Skeleton w="70%" h={26} rounded="md" className="mt-2.5" />
            <Skeleton w="100%" h={32} className="mt-3" />
          </div>
        ))}
      </div>
      <div className="grid items-start gap-5 lg:grid-cols-3">
        <div className="yz-card p-4 lg:col-span-2">
          <Skeleton w="30%" h={14} />
          <Skeleton w="100%" h={200} rounded="md" className="mt-4" />
        </div>
        <div className="yz-card">
          <SkeletonTable rows={5} cols={3} />
        </div>
      </div>
    </div>
  )
}

/* ---------------------------------------------------------- Placeholders */

export function ComingSoon({ title, phase, children }) {
  return (
    <>
      <PageHeader title={title} />
      <Card>
        <EmptyState title={phase === '—' ? title : `${title} arrives in ${phase}`} icon={ChartNoAxesColumn}>
          {children}
        </EmptyState>
      </Card>
    </>
  )
}
