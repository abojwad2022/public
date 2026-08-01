import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { customersApi, ordersApi } from '../api/endpoints.js'
import { useToast } from '../context/ToastContext.jsx'
import { useMeta, formatPrice } from '../context/MetaContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  Modal,
  Pagination,
  SearchInput,
  Select,
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
import { ShoppingCart, Users } from '../components/ui/icons.js'
import { statusTone } from './Orders.jsx'

const SORTABLE = [
  { key: 'name', label: 'Customer' },
  { key: 'email', label: 'Email' },
  { key: 'registered', label: 'Registered' },
]

export default function Customers() {
  const toast = useToast()
  const navigate = useNavigate()
  const { meta } = useMeta()

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  // Default to every role: a young store often has no `customer`-role users yet (guest
  // checkouts create no account), and an empty screen reads as broken.
  const [role, setRole] = useState('')
  const [sort, setSort] = useState({ orderby: 'name', order: 'asc' })
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  // Quick view.
  const [detail, setDetail] = useState(null) // { id, name } while loading
  const [detailData, setDetailData] = useState(null)
  const [recentOrders, setRecentOrders] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(
        await customersApi.list({
          search,
          role,
          ...sort,
          stats: 1,
          page,
          per_page: 20,
        }),
      )
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoading(false)
    }
  }, [search, role, sort, page, toast])

  useEffect(() => {
    load()
  }, [load])

  // Debounce the search box.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch((current) => (current === searchInput ? current : searchInput))
      setPage(1)
    }, 350)
    return () => clearTimeout(timer)
  }, [searchInput])

  const toggleSort = (key) => {
    setSort((current) =>
      current.orderby === key
        ? { orderby: key, order: current.order === 'asc' ? 'desc' : 'asc' }
        : { orderby: key, order: 'asc' },
    )
    setPage(1)
  }

  const openDetail = async (customer) => {
    setDetail({ id: customer.id, name: customer.name })
    setDetailData(null)
    setRecentOrders(null)
    try {
      const [full, orders] = await Promise.all([
        customersApi.get(customer.id),
        ordersApi.list({ customer_id: customer.id, per_page: 5, orderby: 'date', order: 'desc' }),
      ])
      setDetailData(full)
      setRecentOrders(orders?.items || [])
    } catch (err) {
      toast.error(err.message)
      setDetail(null)
    }
  }

  const closeDetail = () => {
    setDetail(null)
    setDetailData(null)
    setRecentOrders(null)
  }

  const items = data?.items || []
  const hasFilters = !!search || role !== ''
  const money = (value) => formatPrice(meta, value)

  const clearFilters = () => {
    setSearchInput('')
    setSearch('')
    setRole('')
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Customers"
        subtitle={data ? `${data.total} account${data.total === 1 ? '' : 's'}` : ' '}
        breadcrumbs={[{ label: 'Sales' }, { label: 'Customers' }]}
      />

      <Card className="mb-4" bodyClass="p-3">
        <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
          <SearchInput
            value={searchInput}
            onChange={setSearchInput}
            placeholder="Search by name, email or username…"
            className="col-span-2 min-w-56 flex-1"
          />

          <Select
            value={role}
            onChange={(e) => {
              setRole(e.target.value)
              setPage(1)
            }}
            className="w-full sm:w-auto"
          >
            <option value="">All roles</option>
            <option value="customer">Customers</option>
            <option value="subscriber">Subscribers</option>
            <option value="shop_manager">Shop managers</option>
            <option value="administrator">Administrators</option>
          </Select>

          {hasFilters && (
            <Button size="sm" onClick={clearFilters}>
              Clear
            </Button>
          )}
        </div>
      </Card>

      <Card bodyClass="p-0">
        {loading ? (
          <SkeletonTable rows={8} cols={5} />
        ) : items.length === 0 ? (
          <EmptyState
            title="No customers found"
            icon={Users}
            action={hasFilters ? <Button onClick={clearFilters}>Clear filters</Button> : null}
          >
            {hasFilters
              ? 'No accounts match the current filters.'
              : 'Customer accounts will appear here once people register or check out.'}
          </EmptyState>
        ) : (
          <>
            <Table>
              <THead>
                <TR>
                  {SORTABLE.map((column) => (
                    <TH
                      key={column.key}
                      sortKey={column.key}
                      activeSort={sort.orderby}
                      direction={sort.order}
                      onSort={toggleSort}
                    >
                      {column.label}
                    </TH>
                  ))}
                  <TH>Role</TH>
                  <TH align="end">Orders</TH>
                  <TH align="end">Total spent</TH>
                  <TH align="end">Actions</TH>
                </TR>
              </THead>
              <TBody>
                {items.map((customer) => (
                  <TR key={customer.id}>
                    <TD>
                      <span className="flex items-center gap-2">
                        {customer.avatar ? (
                          <img
                            src={customer.avatar}
                            alt=""
                            className="size-7 shrink-0 rounded-full border border-edge"
                            loading="lazy"
                          />
                        ) : (
                          <span className="grid size-7 shrink-0 place-items-center rounded-full bg-surface2 text-2xs text-muted">
                            {(customer.name || '?').charAt(0).toUpperCase()}
                          </span>
                        )}
                        <button
                          type="button"
                          onClick={() => openDetail(customer)}
                          className="rounded-xs text-start font-medium text-fg transition-colors hover:text-agate-fg"
                        >
                          {customer.name}
                        </button>
                      </span>
                    </TD>
                    <TD className="text-muted" label="Email">{customer.email || '—'}</TD>
                    <TD className="yz-num whitespace-nowrap text-muted" label="Registered">{customer.registered || '—'}</TD>
                    <TD label="Role">
                      <Badge tone="muted">{customer.roles?.[0] || '—'}</Badge>
                    </TD>
                    <TD align="end" className="yz-num text-fg" label="Orders">
                      {customer.order_count ?? 0}
                    </TD>
                    <TD align="end" className="yz-num whitespace-nowrap text-fg" label="Total spent">
                      {money(customer.total_spent)}
                    </TD>
                    <TD align="end">
                      <span className="inline-flex items-center justify-end gap-1.5">
                        <Button size="sm" variant="quiet" onClick={() => openDetail(customer)}>
                          View
                        </Button>
                        <Button
                          size="sm"
                          variant="quiet"
                          icon={ShoppingCart}
                          onClick={() => navigate(`/orders?customer_id=${customer.id}`)}
                          disabled={!customer.order_count}
                        >
                          Orders
                        </Button>
                      </span>
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>

            <Pagination
              page={data.page}
              pages={data.total_pages}
              total={data.total}
              perPage={20}
              onPage={setPage}
              label="customers"
            />
          </>
        )}
      </Card>

      {/* Quick view */}
      <Modal open={!!detail} onClose={closeDetail} title={detail ? detail.name : ''} size="lg">
        {!detailData ? (
          <div className="space-y-4">
            <Skeleton w="45%" h={16} />
            <div className="grid gap-3 sm:grid-cols-3">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} w="100%" h={70} rounded="lg" />
              ))}
            </div>
            <Skeleton w="100%" h={120} rounded="lg" />
          </div>
        ) : (
          <div className="space-y-5 text-sm">
            <div className="flex flex-wrap items-center gap-3">
              <span className="text-muted">{detailData.email}</span>
              <Badge tone="muted">{detailData.roles?.[0] || '—'}</Badge>
              <span className="text-faint">Registered {detailData.registered || '—'}</span>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
              <StatTile label="Orders" value={detailData.order_count ?? 0} />
              <StatTile label="Lifetime spend" value={money(detailData.total_spent)} />
              <StatTile
                label="Last order"
                value={detailData.last_order ? `#${detailData.last_order.number}` : '—'}
                hint={detailData.last_order?.date}
              />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <p className="yz-label">Billing</p>
                <Address address={detailData.billing} />
              </div>
              <div>
                <p className="yz-label">Shipping</p>
                <Address address={detailData.shipping} />
              </div>
            </div>

            <div>
              <p className="yz-label">Recent orders</p>
              {recentOrders === null ? (
                <Skeleton w="100%" h={90} rounded="lg" />
              ) : recentOrders.length === 0 ? (
                <p className="text-muted">No orders yet.</p>
              ) : (
                <Table>
                  <TBody>
                    {recentOrders.map((order) => (
                      <TR key={order.id}>
                        <TD>
                          <button
                            type="button"
                            onClick={() => {
                              closeDetail()
                              navigate(`/orders/${order.id}`)
                            }}
                            className="yz-num rounded-xs text-fg transition-colors hover:text-agate-fg"
                          >
                            #{order.number}
                          </button>
                        </TD>
                        <TD className="yz-num whitespace-nowrap text-muted">{order.date}</TD>
                        <TD>
                          <Badge tone={statusTone(order.status)}>{order.status_label}</Badge>
                        </TD>
                        <TD align="end" className="yz-num whitespace-nowrap text-fg">
                          {order.total_html}
                        </TD>
                      </TR>
                    ))}
                  </TBody>
                </Table>
              )}
            </div>
          </div>
        )}
      </Modal>
    </>
  )
}

/** Renders a WooCommerce address object as readable lines. */
function Address({ address }) {
  if (!address) return <p className="text-muted">—</p>
  const lines = [
    [address.first_name, address.last_name].filter(Boolean).join(' '),
    address.company,
    address.address_1,
    address.address_2,
    [address.postcode, address.city].filter(Boolean).join(' '),
    [address.state, address.country].filter(Boolean).join(', '),
    address.phone,
    address.email,
  ].filter(Boolean)

  if (lines.length === 0) return <p className="text-muted">—</p>
  return (
    <p className="leading-relaxed text-fg">
      {lines.map((line, index) => (
        <span key={index} className="block">
          {line}
        </span>
      ))}
    </p>
  )
}
