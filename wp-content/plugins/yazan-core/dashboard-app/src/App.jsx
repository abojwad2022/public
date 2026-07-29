import { Component, lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { boot } from './api/client.js'
import { AuthProvider, useAuth } from './context/AuthContext.jsx'
import { MetaProvider, useMeta } from './context/MetaContext.jsx'
import { OrderAlertsProvider } from './context/OrderAlertsContext.jsx'
import { ToastProvider } from './context/ToastContext.jsx'
import Layout, { PageHeader } from './components/Layout.jsx'
import { Alert, Skeleton, SkeletonTable, Spinner } from './components/ui/index.js'

// Eager: everything needed to paint the first screen. Login in particular must
// never be lazy — it IS the first paint for a logged-out visitor, and a chunk
// round trip in front of the sign-in form is a visible stall.
import Login from './pages/Login.jsx'
import { ComingSoon, DashboardHome } from './pages/Misc.jsx'

const RELOAD_FLAG = 'yz-chunk-reload'

/**
 * Route chunks fail to load when a browser tab holds a cached entry whose hashed
 * chunks no longer exist after a rebuild. Reload once to pick up the new entry;
 * the sessionStorage guard stops that becoming a loop when the real cause is
 * simply being offline. The flag is cleared on the first success so the same
 * self-heal is available again after the next deploy.
 */
const lazyRoute = (factory) =>
  lazy(() =>
    factory()
      .then((module) => {
        try {
          sessionStorage.removeItem(RELOAD_FLAG)
        } catch {
          /* storage disabled — the reload guard simply stays unset */
        }
        return module
      })
      .catch((error) => {
        try {
          if (!sessionStorage.getItem(RELOAD_FLAG)) {
            sessionStorage.setItem(RELOAD_FLAG, '1')
            window.location.reload()
          }
        } catch {
          /* storage disabled — fall through to the error boundary */
        }
        throw error
      })
  )

/**
 * Keeps a broken screen from taking down the application.
 *
 * React unmounts the entire root when an error escapes with no boundary above
 * it, so a single failing route chunk turned into a blank page with no sidebar
 * and no way back. Scoped here — inside <Layout>, around the routes — the shell
 * survives and the failure costs only the content area.
 */
class RouteErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidUpdate(prevProps) {
    // A new route should get a clean slate rather than inheriting the old error.
    if (this.state.error && prevProps.routeKey !== this.props.routeKey) {
      this.setState({ error: null })
    }
  }

  render() {
    if (!this.state.error) return this.props.children

    return (
      <Alert
        tone="danger"
        title="This screen failed to load"
        onRetry={() => window.location.reload()}
      >
        {this.state.error.message || 'An unexpected error occurred.'}
      </Alert>
    )
  }
}

/** Gives the boundary a key that changes on navigation, so errors do not stick. */
function RouteBoundary({ children }) {
  const { pathname } = useLocation()
  return <RouteErrorBoundary routeKey={pathname}>{children}</RouteErrorBoundary>
}

const Products = lazyRoute(() => import('./pages/Products.jsx'))
const ProductEditor = lazyRoute(() => import('./pages/ProductEditor.jsx'))
const Orders = lazyRoute(() => import('./pages/Orders.jsx'))
const OrderDetail = lazyRoute(() => import('./pages/OrderDetail.jsx'))
const NewOrder = lazyRoute(() => import('./pages/NewOrder.jsx'))
const Customers = lazyRoute(() => import('./pages/Customers.jsx'))
const Categories = lazyRoute(() => import('./pages/Categories.jsx'))
const Attributes = lazyRoute(() => import('./pages/Attributes.jsx'))
const Inventory = lazyRoute(() => import('./pages/Inventory.jsx'))
const Settings = lazyRoute(() => import('./pages/Settings.jsx'))
const Coupons = lazyRoute(() => import('./pages/Coupons.jsx'))
const Reports = lazyRoute(() => import('./pages/Reports.jsx'))
const ActivityLog = lazyRoute(() => import('./pages/ActivityLog.jsx'))
const AIStudio = lazyRoute(() => import('./pages/ai/AIStudio.jsx'))
const AIInsights = lazyRoute(() => import('./pages/ai/AIInsights.jsx'))
const AISettings = lazyRoute(() => import('./pages/ai/AISettings.jsx'))
const RewardsEmbed = lazyRoute(() => import('./pages/RewardsEmbed.jsx'))

export default function App() {
  return (
    <ToastProvider>
      <AuthProvider>
        <Gate />
      </AuthProvider>
    </ToastProvider>
  )
}

/**
 * Unauthenticated visitors get the standalone login screen; everyone else gets the app.
 * Server-side the same capability is enforced on every REST route, so this is purely UX.
 */
function Gate() {
  const { user } = useAuth()
  if (!user) return <Login />

  return (
    <MetaProvider>
      <BrowserRouter basename={boot.basePath || '/dashboard'}>
        {/* Inside the router because the new-order toast navigates to /orders; inside the
            auth gate because only a signed-in owner should be polling for orders. */}
        <OrderAlertsProvider>
          <Layout>
            {/* Both wrappers sit INSIDE Layout on purpose: the sidebar and header stay
                painted while a route chunk loads, and a screen that throws costs only
                the content area rather than unmounting the whole application. */}
            <RouteBoundary>
              <Suspense fallback={<RouteFallback />}>
                <Shell />
              </Suspense>
            </RouteBoundary>
          </Layout>
        </OrderAlertsProvider>
      </BrowserRouter>
    </MetaProvider>
  )
}

/**
 * Shown while a route chunk downloads. A skeleton rather than a spinner: once the
 * chrome has already painted, a spinner appearing in the content area reads as an
 * error rather than as progress.
 */
function RouteFallback() {
  return (
    <div aria-busy="true" aria-label="Loading screen">
      <div className="mb-5">
        <Skeleton w="180px" h={28} rounded="md" />
        <Skeleton w="260px" h={14} className="mt-2" />
      </div>
      <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="yz-card p-4">
            <Skeleton w="60%" h={12} />
            <Skeleton w="45%" h={26} rounded="md" className="mt-2.5" />
          </div>
        ))}
      </div>
      <div className="yz-card">
        <SkeletonTable rows={5} cols={4} />
      </div>
    </div>
  )
}

/**
 * Orders need `edit_shop_orders`, which a product-only role may not have. The server enforces
 * this on every route; this just gives a clear message instead of a wall of 403 toasts.
 */
function OrdersGate({ view }) {
  const { can } = useAuth()
  if (!can('edit_shop_orders')) {
    return (
      <ComingSoon title="Orders" phase="—">
        Your account does not have permission to view orders. Ask an administrator for the Shop
        Manager role.
      </ComingSoon>
    )
  }
  if (view === 'new') return <NewOrder />
  if (view === 'detail') return <OrderDetail />
  return <Orders />
}

/** Customers read from the same order data, so they need the same capability. */
function CustomersGate() {
  const { can } = useAuth()
  if (!can('edit_shop_orders')) {
    return (
      <ComingSoon title="Customers" phase="—">
        Your account does not have permission to view customers. Ask an administrator for the Shop
        Manager role.
      </ComingSoon>
    )
  }
  return <Customers />
}

/**
 * Feature pages that touch privileged areas gate on a capability — AI on the
 * WooCommerce-admin cap by default, Yazan Rewards on `manage_yazan_rewards`.
 */
function AdminGate({ title, cap = 'manage_woo', children }) {
  const { can } = useAuth()
  if (!can(cap)) {
    return (
      <ComingSoon title={title} phase="—">
        Your account does not have permission for this. Ask an administrator for the Shop Manager role.
      </ComingSoon>
    )
  }
  return children
}

/** The audit log is also a Settings tab, but it deserves a linkable route of its own. */
function ActivityRoute() {
  return (
    <>
      <PageHeader
        title="Activity log"
        subtitle="Every write made through the dashboard, with who and when."
        breadcrumbs={[{ label: 'System' }, { label: 'Activity log' }]}
      />
      <ActivityLog />
    </>
  )
}

/** Blocks rendering until the shared reference data has loaded. */
function Shell() {
  const { meta, error } = useMeta()

  if (error) {
    return (
      <Alert tone="danger" title="Could not load store data" onRetry={() => window.location.reload()}>
        {error.message}
      </Alert>
    )
  }
  if (!meta) return <Spinner label="Loading store data…" />

  return (
    <Routes>
      <Route path="/" element={<DashboardHome />} />
      <Route path="/products" element={<Products />} />
      <Route path="/products/new" element={<ProductEditor />} />
      <Route path="/products/:id" element={<ProductEditor />} />
      <Route path="/categories" element={<Categories />} />
      <Route path="/attributes" element={<Attributes />} />
      <Route path="/inventory" element={<Inventory />} />
      <Route path="/orders" element={<OrdersGate />} />
      {/* Static segment must be declared before the dynamic :id route. */}
      <Route path="/orders/new" element={<OrdersGate view="new" />} />
      <Route path="/orders/:id" element={<OrdersGate view="detail" />} />
      <Route path="/customers" element={<CustomersGate />} />
      <Route path="/coupons" element={<Coupons />} />
      <Route path="/reports" element={<Reports />} />
      <Route
        path="/activity"
        element={
          <AdminGate title="Activity log">
            <ActivityRoute />
          </AdminGate>
        }
      />
      <Route path="/ai" element={<AIStudio />} />
      <Route path="/ai/insights" element={<AdminGate title="AI Insights"><AIInsights /></AdminGate>} />
      <Route path="/ai/settings" element={<AdminGate title="AI Settings"><AISettings /></AdminGate>} />
      {/* Yazan Rewards — each screen is an iframe of the plugin's chrome-less WP render. */}
      <Route path="/rewards" element={<Navigate to="/rewards/analytics" replace />} />
      <Route
        path="/rewards/:screen"
        element={
          <AdminGate title="Yazan Rewards" cap="manage_yazan_rewards">
            <RewardsEmbed />
          </AdminGate>
        }
      />
      <Route path="/settings" element={<Settings />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
