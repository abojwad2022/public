import { Link } from 'react-router'
import { formatPrice } from '../../context/MetaContext.jsx'
import {
  Badge,
  Button,
  Dropdown,
  Icon,
  Table,
  TBody,
  TD,
  TH,
  THead,
  TR,
} from '../ui/index.js'
import { Copy, ExternalLink, Eye, Pencil, RotateCcw, SlidersHorizontal, Star, Trash2 } from '../ui/icons.js'

/**
 * The column registry — one source of truth for order, labels, sortability and which
 * columns start hidden. Screen Options renders straight off this list, and so does the
 * table, so a column can never appear in one and not the other.
 *
 * Order mirrors WooCommerce's own products table (image, name, SKU, GTIN, stock, price,
 * categories, tags, featured, date) so an operator moving between the two screens reads
 * the same row left-to-right. Status is ours: wp-admin appends "— Draft" to the title
 * instead, which is easy to miss at a glance.
 */
export const PRODUCT_COLUMNS = [
  { key: 'thumb', label: 'Image', locked: true },
  { key: 'name', label: 'Product', sortKey: 'title', locked: true },
  { key: 'sku', label: 'SKU', sortKey: 'sku' },
  { key: 'global_unique_id', label: 'GTIN', defaultHidden: true },
  { key: 'stock', label: 'Stock' },
  { key: 'price', label: 'Price', sortKey: 'price' },
  { key: 'categories', label: 'Categories' },
  { key: 'tags', label: 'Tags' },
  { key: 'featured', label: 'Featured' },
  { key: 'date', label: 'Date', sortKey: 'date' },
  { key: 'status', label: 'Status' },
]

export const DEFAULT_VISIBLE_COLUMNS = PRODUCT_COLUMNS.filter((c) => !c.defaultHidden).map((c) => c.key)

export default function ProductsTable({
  items,
  meta,
  columns,
  sort,
  onSort,
  selected,
  onToggleOne,
  onToggleAll,
  allSelected,
  someSelected,
  inTrash,
  can,
  onFilterBy,
  onEdit,
  onQuickEdit,
  onDuplicate,
  onToggleFeatured,
  onTrash,
  onRestore,
  onDeleteForever,
}) {
  const shown = PRODUCT_COLUMNS.filter((column) => column.locked || columns.includes(column.key))

  return (
    <Table>
      <THead>
        <TR>
          <TH className="w-8">
            <input
              type="checkbox"
              checked={allSelected}
              // Half a page selected must not read as "none selected" — the tri-state box
              // is the only thing that says "some", and it has to be set on the node.
              ref={(node) => {
                if (node) node.indeterminate = someSelected && !allSelected
              }}
              onChange={onToggleAll}
              aria-label="Select all products on this page"
              className="size-4 rounded-xs accent-[var(--yz-agate)]"
            />
          </TH>
          {shown.map((column) =>
            column.sortKey ? (
              <TH
                key={column.key}
                sortKey={column.sortKey}
                activeSort={sort.orderby}
                direction={sort.order}
                onSort={onSort}
              >
                {column.label}
              </TH>
            ) : (
              <TH key={column.key} className={column.key === 'thumb' ? 'w-12' : undefined}>
                {column.key === 'thumb' ? <span className="sr-only">Image</span> : column.label}
              </TH>
            ),
          )}
          <TH align="end">Actions</TH>
        </TR>
      </THead>
      <TBody>
        {items.map((product) => (
          <TR key={product.id} selected={selected.includes(product.id)}>
            <TD>
              <input
                type="checkbox"
                checked={selected.includes(product.id)}
                onChange={() => onToggleOne(product.id)}
                aria-label={`Select ${product.name}`}
                className="size-4 rounded-xs accent-[var(--yz-agate)]"
              />
            </TD>

            {shown.map((column) => (
              <Cell
                key={column.key}
                column={column}
                product={product}
                meta={meta}
                inTrash={inTrash}
                can={can}
                onFilterBy={onFilterBy}
                onToggleFeatured={onToggleFeatured}
              />
            ))}

            {/* Two primary actions stay visible; the rest move into a menu.
                Five equal-weight text links per row read as noise and made
                every row 40% wider than its content needed. */}
            <TD align="end" className="whitespace-nowrap">
              {inTrash ? (
                /* A trashed row offers only the two things you can do to it — the same
                   pair wp-admin swaps in on its Trash view. */
                <span className="inline-flex items-center gap-1">
                  {can('products.restore') && (
                    <Button size="sm" variant="quiet" icon={RotateCcw} onClick={() => onRestore(product)}>
                      Restore
                    </Button>
                  )}
                  {can('products.delete') && (
                    <Button size="sm" variant="quiet" icon={Trash2} onClick={() => onDeleteForever(product)}>
                      Delete permanently
                    </Button>
                  )}
                </span>
              ) : (
                <span className="inline-flex items-center gap-1">
                  <Button size="sm" variant="quiet" icon={Pencil} onClick={() => onEdit(product)}>
                    Edit
                  </Button>
                  <Dropdown
                    label={`More actions for ${product.name}`}
                    trigger={({ toggle, open }) => (
                      <button
                        type="button"
                        onClick={toggle}
                        aria-haspopup="menu"
                        aria-expanded={open}
                        aria-label={`More actions for ${product.name}`}
                        className="yz-btn yz-btn-quiet yz-btn-sm yz-btn-icon"
                      >
                        ⋯
                      </button>
                    )}
                    items={[
                      { key: 'id', label: `ID: ${product.id}`, disabled: true },
                      {
                        key: 'quick',
                        label: 'Quick edit',
                        icon: SlidersHorizontal,
                        separatorBefore: true,
                        onSelect: () => onQuickEdit(product),
                      },
                      { key: 'copy', label: 'Duplicate', icon: Copy, onSelect: () => onDuplicate(product) },
                      {
                        // A draft has no public URL, so "View" would 404 for anyone not
                        // already signed in. wp-admin shows Preview instead; so do we.
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
                    ]}
                  />
                </span>
              )}
            </TD>
          </TR>
        ))}
      </TBody>
    </Table>
  )
}

/** One cell, chosen by column key. Keeping them in one place keeps the row readable. */
function Cell({ column, product, meta, inTrash, can, onFilterBy, onToggleFeatured }) {
  switch (column.key) {
    case 'thumb':
      return (
        <TD>
          {product.thumb ? (
            <img
              src={product.thumb}
              alt=""
              className="size-10 rounded-sm border border-edge object-cover"
            />
          ) : (
            <div className="size-10 rounded-sm border border-edge bg-sunken" />
          )}
        </TD>
      )

    case 'name':
      return (
        <TD primary>
          <Link
            to={`/products/${product.id}`}
            className="font-medium text-fg transition-colors hover:text-agate-fg"
          >
            {product.name || '(no title)'}
          </Link>
          <div className="mt-1 flex flex-wrap gap-1.5">
            {product.type !== 'simple' && <Badge>{product.type}</Badge>}
            {product.edit_serial && <Badge tone="gold">Certified</Badge>}
            {product.on_sale && <Badge tone="warn">Sale</Badge>}
          </div>
        </TD>
      )

    case 'sku':
      return (
        <TD className="yz-num font-mono text-xs text-muted" label="SKU">
          {product.sku || '—'}
        </TD>
      )

    case 'global_unique_id':
      return (
        <TD className="yz-num font-mono text-xs text-muted" label="GTIN">
          {product.global_unique_id || '—'}
        </TD>
      )

    case 'stock':
      return (
        <TD className="whitespace-nowrap" label="Stock">
          <StockCell product={product} />
        </TD>
      )

    case 'price':
      return (
        <TD className="yz-num whitespace-nowrap" label="Price">
          {product.on_sale && product.regular_price ? (
            <>
              <span className="me-1 text-faint line-through">
                {formatPrice(meta, product.regular_price)}
              </span>
              <span className="text-fg">{formatPrice(meta, product.price)}</span>
            </>
          ) : (
            <span className="text-fg">{formatPrice(meta, product.price)}</span>
          )}
        </TD>
      )

    case 'categories':
      return (
        <TD className="text-muted sm:max-w-40" label="Categories">
          <TermLinks terms={product.categories} onSelect={(slug) => onFilterBy('category', slug)} />
        </TD>
      )

    case 'tags':
      return (
        <TD className="text-muted sm:max-w-40" label="Tags">
          <TermLinks terms={product.tags} onSelect={(slug) => onFilterBy('tag', slug)} />
        </TD>
      )

    case 'featured':
      return (
        <TD label="Featured">
          <button
            type="button"
            disabled={inTrash || !can('products.edit')}
            onClick={() => onToggleFeatured(product)}
            aria-pressed={Boolean(product.featured)}
            aria-label={
              product.featured ? `Remove ${product.name} from featured` : `Mark ${product.name} as featured`
            }
            title={product.featured ? 'Featured' : 'Not featured'}
            className={`grid size-7 place-items-center rounded-sm transition-colors disabled:pointer-events-none disabled:opacity-40 ${
              product.featured ? 'text-gold' : 'text-faint hover:text-fg'
            }`}
          >
            <Icon as={Star} size={16} fill={product.featured ? 'currentColor' : 'none'} />
          </button>
        </TD>
      )

    case 'date':
      return (
        <TD className="yz-num whitespace-nowrap text-muted" label="Date">
          {product.date}
        </TD>
      )

    case 'status':
      return (
        <TD label="Status">
          <Badge tone={product.status === 'publish' ? 'ok' : 'muted'}>{product.status}</Badge>
        </TD>
      )

    default:
      return <TD />
  }
}

/** Terms rendered as filter controls, the way wp-admin makes its category cells clickable. */
function TermLinks({ terms, onSelect }) {
  if (!terms?.length) return <span>—</span>

  return (
    <span className="inline-flex flex-wrap gap-x-1 gap-y-0.5">
      {terms.map((term, index) => (
        <span key={term.slug}>
          <button
            type="button"
            onClick={() => onSelect(term.slug)}
            className="text-start underline-offset-2 transition-colors hover:text-agate-fg hover:underline"
          >
            {term.name}
          </button>
          {index < terms.length - 1 ? ',' : ''}
        </span>
      ))}
    </span>
  )
}

function StockCell({ product }) {
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
