import { Component, lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router'
import { boot } from './api/client.js'
import { AuthProvider, useAuth } from './context/AuthContext.jsx'
import { MetaProvider, useMeta } from './context/MetaContext.jsx'
import { OrderAlertsProvider } from './context/OrderAlertsContext.jsx'
import { ToastProvider } from './context/ToastContext.jsx'
import Layout, { PageHeader } from './components/Layout.jsx'
import { Protected } from './components/Protected.jsx'
import { Alert, Skeleton, SkeletonTable, Spinner } from './components/ui/index.js'

// Eager: everything needed to paint the first screen. Login in particular must
// never be lazy — it IS the first paint for a logged-out visitor, and a chunk
// round trip in front of the sign-in form is a visible stall.
import Login from './pages/Login.jsx'
import { DashboardHome } from './pages/Misc.jsx'

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
const Users = lazyRoute(() => import('./pages/access/Users.jsx'))
const UserEditor = lazyRoute(() => import('./pages/access/UserEditor.jsx'))
const Roles = lazyRoute(() => import('./pages/access/Roles.jsx'))
const RoleEditor = lazyRoute(() => import('./pages/access/RoleEditor.jsx'))
const Permissions = lazyRoute(() => import('./pages/access/Permissions.jsx'))

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
    /*
     * Every screen declares the permission it needs. Editor routes gate on `.view`, not `.edit` —
     * a viewer who can open a record must not hit a wall on a page they were told they could
     * reach; the Save button inside carries the write permission instead.
     */
    <Routes>
      <Route path="/" element={<Protected perm="dashboard.view" title="Dashboard"><DashboardHome /></Protected>} />
      <Route path="/products" element={<Protected perm="products.view" title="Products"><Products /></Protected>} />
      <Route path="/products/new" element={<Protected perm="products.create" title="Products"><ProductEditor /></Protected>} />
      <Route path="/products/:id" element={<Protected perm="products.view" title="Products"><ProductEditor /></Protected>} />
      <Route path="/categories" element={<Protected perm="categories.view" title="Categories"><Categories /></Protected>} />
      <Route path="/attributes" element={<Protected perm="attributes.view" title="Attributes"><Attributes /></Protected>} />
      <Route path="/inventory" element={<Protected perm="inventory.view" title="Inventory"><Inventory /></Protected>} />
      <Route path="/orders" element={<Protected perm="orders.view" title="Orders"><Orders /></Protected>} />
      {/* Static segment must be declared before the dynamic :id route. */}
      <Route path="/orders/new" element={<Protected perm="orders.create" title="Orders"><NewOrder /></Protected>} />
      <Route path="/orders/:id" element={<Protected perm="orders.view" title="Orders"><OrderDetail /></Protected>} />
      <Route path="/customers" element={<Protected perm="customers.view" title="Customers"><Customers /></Protected>} />
      <Route path="/coupons" element={<Protected perm="coupons.view" title="Coupons"><Coupons /></Protected>} />
      <Route path="/reports" element={<Protected perm="reports.view" title="Reports"><Reports /></Protected>} />
      <Route path="/activity" element={<Protected perm="audit.view" title="Activity log"><ActivityRoute /></Protected>} />
      <Route path="/ai" element={<Protected perm="ai.use" title="AI Studio"><AIStudio /></Protected>} />
      <Route path="/ai/insights" element={<Protected perm="ai.insights_view" title="AI Insights"><AIInsights /></Protected>} />
      <Route path="/ai/settings" element={<Protected perm="ai.configure" title="AI Settings"><AISettings /></Protected>} />
      {/* Yazan Rewards — each screen is an iframe of the plugin's chrome-less WP render. */}
      <Route path="/rewards" element={<Navigate to="/rewards/analytics" replace />} />
      <Route path="/rewards/:screen" element={<Protected perm="rewards.view" title="Yazan Rewards"><RewardsEmbed /></Protected>} />
      {/* Access control. */}
      <Route path="/users" element={<Protected perm="users.view" title="Users"><Users /></Protected>} />
      <Route path="/users/new" element={<Protected perm="users.create" title="Users"><UserEditor /></Protected>} />
      <Route path="/users/:id" element={<Protected perm="users.view" title="Users"><UserEditor /></Protected>} />
      <Route path="/roles" element={<Protected perm="roles.view" title="Roles"><Roles /></Protected>} />
      <Route path="/roles/new" element={<Protected perm="roles.create" title="Roles"><RoleEditor /></Protected>} />
      <Route path="/roles/:id" element={<Protected perm="roles.view" title="Roles"><RoleEditor /></Protected>} />
      <Route path="/permissions" element={<Protected perm="permissions.view" title="Permissions"><Permissions /></Protected>} />
      <Route path="/settings" element={<Protected perm="settings.view" title="Settings"><Settings /></Protected>} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
