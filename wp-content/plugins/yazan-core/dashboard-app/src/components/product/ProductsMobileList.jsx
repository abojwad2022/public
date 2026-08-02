import { Link } from 'react-router'
import { formatPrice } from '../../context/MetaContext.jsx'
import { Badge, Dropdown, Icon, Select } from '../ui/index.js'
import {
  Copy,
  ExternalLink,
  Eye,
  Image,
  Pencil,
  RotateCcw,
  SlidersHorizontal,
  Star,
  Trash2,
} from '../ui/icons.js'

/**
 * The products list as it appears on a phone.
 *
 * The generic table-to-cards fallback prints every column as a "Label   value"
 * row, so a single product filled a whole screen with eleven lines — twelve
 * scrolls to see ten products, and the one thing you actually look for (the
 * photo, the name, the price) was buried among labels you already know.
 *
 * Every serious commerce admin (Shopify, Woo mobile, Lightspeed) solves this the
 * same way and so do we: one compact row per product — thumbnail, name, price,
 * and only the badges that carry a warning (stock, non-published status, sale).
 * Everything else moves into the row menu. The table is still what renders from
 * `sm` up; this component owns only the phone.
 *
 * Column visibility (Screen Options) is deliberately ignored here: it exists to
 * fit a grid to a wide screen, and a phone row has one fixed shape.
 */

/**
 * Sorting on a phone has no column headers to click, so it becomes an explicit
 * control. The pairs mirror the sortable table columns exactly — no sort order
 * exists here that the table cannot also produce.
 */
const SORT_OPTIONS = [
  { value: 'date:desc', label: 'Newest first' },
  { value: 'date:asc', label: 'Oldest first' },
  { value: 'title:asc', label: 'Name A–Z' },
  { value: 'title:desc', label: 'Name Z–A' },
  { value: 'price:asc', label: 'Price: low to high' },
  { value: 'price:desc', label: 'Price: high to low' },
  { value: 'sku:asc', label: 'SKU A–Z' },
]

export default function ProductsMobileList({
  items,
  meta,
  sort,
  onSortChange,
  selected,
  onToggleOne,
  onToggleAll,
  allSelected,
  someSelected,
  inTrash,
  can,
  onEdit,
  onQuickEdit,
  onDuplicate,
  onToggleFeatured,
  onTrash,
  onRestore,
  onDeleteForever,
}) {
  const sortValue = `${sort.orderby}:${sort.order}`

  return (
    <div>
      {/* Toolbar: the two controls the table gets from its header row — select-all
          and sort — restated for a layout that has no header row. */}
      <div className="flex items-center gap-2 border-b border-edge px-3 py-2">
        <label className="flex cursor-pointer items-center gap-2 py-1 text-xs text-muted">
          <input
            type="checkbox"
            checked={allSelected}
            ref={(node) => {
              if (node) node.indeterminate = someSelected && !allSelected
            }}
            onChange={onToggleAll}
            className="size-4 rounded-xs accent-[var(--yz-agate)]"
          />
          {someSelected ? (
            <span className="yz-num text-fg">{selected.length} selected</span>
          ) : (
            'Select all'
          )}
        </label>

        <Select
          value={SORT_OPTIONS.some((option) => option.value === sortValue) ? sortValue : 'date:desc'}
          onChange={(event) => {
            const [orderby, order] = event.target.value.split(':')
            onSortChange(orderby, order)
          }}
          aria-label="Sort products"
          className="ms-auto w-auto !min-h-8 py-1 text-xs"
        >
          {SORT_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      </div>

      <ul>
        {items.map((product) => (
          <ProductRow
            key={product.id}
            product={product}
            meta={meta}
            selected={selected.includes(product.id)}
            onToggleOne={onToggleOne}
            inTrash={inTrash}
            can={can}
            onEdit={onEdit}
            onQuickEdit={onQuickEdit}
            onDuplicate={onDuplicate}
            onToggleFeatured={onToggleFeatured}
            onTrash={onTrash}
            onRestore={onRestore}
            onDeleteForever={onDeleteForever}
          />
        ))}
      </ul>
    </div>
  )
}

function ProductRow({
  product,
  meta,
  selected,
  onToggleOne,
  inTrash,
  can,
  onEdit,
  onQuickEdit,
  onDuplicate,
  onToggleFeatured,
  onTrash,
  onRestore,
  onDeleteForever,
}) {
  const to = `/products/${product.id}`
  const categories = (product.categories || []).map((term) => term.name).join(', ')

  return (
    <li
      className={`flex gap-3 border-b border-divider px-3 py-3 last:border-b-0 ${
        selected ? 'bg-selected' : ''
      }`}
    >
      {/* Padded well past the 16px box: a bare checkbox is a 16px tap target,
          which is half of what a thumb can reliably hit. */}
      <label className="-m-1 flex shrink-0 cursor-pointer items-start p-1 pt-2">
        <input
          type="checkbox"
          checked={selected}
          onChange={() => onToggleOne(product.id)}
          aria-label={`Select ${product.name}`}
          className="size-4 rounded-xs accent-[var(--yz-agate)]"
        />
      </label>

      <Link to={to} className="relative shrink-0" tabIndex={-1} aria-hidden="true">
        {product.thumb ? (
          <img
            src={product.thumb}
            alt=""
            loading="lazy"
            className="size-16 rounded-md border border-edge object-cover"
          />
        ) : (
          <div className="grid size-16 place-items-center rounded-md border border-edge bg-sunken text-faint">
            <Icon as={Image} size={18} />
          </div>
        )}
        {/* Featured is a one-glyph fact; it does not deserve its own labelled
            row. The toggle lives in the row menu. */}
        {product.featured && (
          <span className="absolute -top-1 -end-1 grid size-5 place-items-center rounded-full bg-gold text-oncontrast shadow-1">
            <Icon as={Star} size={11} fill="currentColor" />
          </span>
        )}
      </Link>

      <div className="min-w-0 flex-1">
        <div className="flex items-start gap-1">
          <Link
            to={to}
            className="line-clamp-2 flex-1 text-[0.9375rem] leading-snug font-medium text-fg transition-colors hover:text-agate-fg"
          >
            {product.name || '(no title)'}
          </Link>
          <RowMenu
            product={product}
            inTrash={inTrash}
            can={can}
            onEdit={onEdit}
            onQuickEdit={onQuickEdit}
            onDuplicate={onDuplicate}
            onToggleFeatured={onToggleFeatured}
            onTrash={onTrash}
            onRestore={onRestore}
            onDeleteForever={onDeleteForever}
          />
        </div>

        <div className="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-xs">
          <span className="yz-num text-sm font-medium text-fg">
            {product.on_sale && product.regular_price ? (
              <>
                <span className="me-1 font-normal text-faint line-through">
                  {formatPrice(meta, product.regular_price)}
                </span>
                {formatPrice(meta, product.price)}
              </>
            ) : (
              formatPrice(meta, product.price)
            )}
          </span>
          {product.sku && (
            <span className="yz-num truncate font-mono text-faint">{product.sku}</span>
          )}
        </div>

        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
          <StockBadge product={product} />
          {/* Published is the expected state, so it is the one status that says
              nothing — only a draft or a pending review needs flagging. */}
          {product.status !== 'publish' && <Badge tone="muted">{product.status}</Badge>}
          {product.on_sale && <Badge tone="warn">Sale</Badge>}
          {product.type !== 'simple' && <Badge>{product.type}</Badge>}
          {product.edit_serial && <Badge tone="gold">Certified</Badge>}
        </div>

        {categories && (
          <p className="mt-1.5 truncate text-xs text-faint" title={categories}>
            {categories}
          </p>
        )}
      </div>
    </li>
  )
}

function RowMenu({
  product,
  inTrash,
  can,
  onEdit,
  onQuickEdit,
  onDuplicate,
  onToggleFeatured,
  onTrash,
  onRestore,
  onDeleteForever,
}) {
  const items = inTrash
    ? [
        ...(can('products.restore')
          ? [{ key: 'restore', label: 'Restore', icon: RotateCcw, onSelect: () => onRestore(product) }]
          : []),
        ...(can('products.delete')
          ? [
              {
                key: 'delete',
                label: 'Delete permanently',
                icon: Trash2,
                tone: 'danger',
                onSelect: () => onDeleteForever(product),
              },
            ]
          : []),
      ]
    : [
        { key: 'id', label: `ID: ${product.id} · ${product.date}`, disabled: true },
        {
          key: 'edit',
          label: 'Edit',
          icon: Pencil,
          separatorBefore: true,
          onSelect: () => onEdit(product),
        },
        { key: 'quick', label: 'Quick edit', icon: SlidersHorizontal, onSelect: () => onQuickEdit(product) },
        ...(can('products.edit')
          ? [
              {
                key: 'feature',
                label: product.featured ? 'Remove from featured' : 'Mark as featured',
                icon: Star,
                onSelect: () => onToggleFeatured(product),
              },
            ]
          : []),
        { key: 'copy', label: 'Duplicate', icon: Copy, onSelect: () => onDuplicate(product) },
        {
          key: 'view',
          label: product.status === 'publish' ? 'View on store' : 'Preview',
          icon: product.status === 'publish' ? ExternalLink : Eye,
          onSelect: () =>
            window.open(
              product.status === 'publish' ? product.permalink : product.preview_url,
              '_blank',
              'noreferrer',
            ),
        },
        ...(can('products.delete')
          ? [
              {
                key: 'trash',
                label: 'Move to trash',
                icon: Trash2,
                tone: 'danger',
                separatorBefore: true,
                onSelect: () => onTrash(product),
              },
            ]
          : []),
      ]

  return (
    <Dropdown
      label={`Actions for ${product.name}`}
      trigger={({ toggle, open }) => (
        <button
          type="button"
          onClick={toggle}
          aria-haspopup="menu"
          aria-expanded={open}
          aria-label={`Actions for ${product.name}`}
          className="-me-1 -mt-1 grid size-9 shrink-0 place-items-center rounded-md text-muted transition-colors hover:bg-hover hover:text-fg"
        >
          ⋯
        </button>
      )}
      items={items}
    />
  )
}

function StockBadge({ product }) {
  if (product.stock_status === 'outofstock') return <Badge tone="danger">Out of stock</Badge>
  if (product.stock_status === 'onbackorder') return <Badge tone="warn">On backorder</Badge>
  return (
    <Badge tone="ok">
      In stock
      {product.manage_stock && product.stock_qty !== null && product.stock_qty !== undefined
        ? ` · ${product.stock_qty}`
        : ''}
    </Badge>
  )
}
