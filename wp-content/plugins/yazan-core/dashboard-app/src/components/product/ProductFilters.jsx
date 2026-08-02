import { Button, SearchInput, Select } from '../ui/index.js'
import { Trash2 } from '../ui/icons.js'

/**
 * The filter bar above the products table — WooCommerce's category / type / stock
 * dropdowns plus the search box, with tag and featured filters it never offered.
 */
export default function ProductFilters({
  meta,
  filters,
  searchInput,
  onSearchInput,
  onFilter,
  hasFilters,
  onClear,
  inTrash,
  trashCount,
  canEmptyTrash,
  onEmptyTrash,
}) {
  return (
    <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
      <SearchInput
        value={searchInput}
        onChange={onSearchInput}
        placeholder="Search name, SKU or ID…"
        className="col-span-2 min-w-52 flex-1"
      />

      <Select
        value={filters.category}
        onChange={(e) => onFilter('category', e.target.value)}
        aria-label="Filter by category"
        className="w-full sm:w-auto"
      >
        <option value="">All categories</option>
        {(meta?.categories || []).map((term) => (
          <option key={term.id} value={term.slug}>
            {term.parent ? '— ' : ''}
            {term.name} ({term.count})
          </option>
        ))}
      </Select>

      <Select
        value={filters.tag}
        onChange={(e) => onFilter('tag', e.target.value)}
        aria-label="Filter by tag"
        className="w-full sm:w-auto"
      >
        <option value="">All tags</option>
        {(meta?.tags || []).map((term) => (
          <option key={term.id} value={term.slug}>
            {term.name} ({term.count})
          </option>
        ))}
      </Select>

      <Select
        value={filters.stock_status}
        onChange={(e) => onFilter('stock_status', e.target.value)}
        aria-label="Filter by stock status"
        className="w-full sm:w-auto"
      >
        <option value="">Any stock</option>
        {Object.entries(meta?.stock_statuses || {}).map(([key, label]) => (
          <option key={key} value={key}>
            {label}
          </option>
        ))}
      </Select>

      <Select
        value={filters.type}
        onChange={(e) => onFilter('type', e.target.value)}
        aria-label="Filter by product type"
        className="w-full sm:w-auto"
      >
        <option value="">Any type</option>
        {Object.entries(meta?.product_types || {}).flatMap(([key, label]) => [
          <option key={key} value={key}>
            {label}
          </option>,
          /* Virtual and Downloadable are flags, not types — WooCommerce lists them as
             indented pseudo-options under Simple, so the two dropdowns agree. */
          ...(key === 'simple'
            ? [
                <option key="downloadable" value="downloadable">
                  &nbsp;&nbsp;→ Downloadable
                </option>,
                <option key="virtual" value="virtual">
                  &nbsp;&nbsp;→ Virtual
                </option>,
              ]
            : []),
        ])}
      </Select>

      <Select
        value={filters.featured}
        onChange={(e) => onFilter('featured', e.target.value)}
        aria-label="Filter by featured"
        className="w-full sm:w-auto"
      >
        <option value="">Any feature state</option>
        <option value="1">Featured only</option>
        <option value="0">Not featured</option>
      </Select>

      {hasFilters && (
        <Button size="sm" onClick={onClear}>
          Clear
        </Button>
      )}

      {inTrash && trashCount > 0 && canEmptyTrash && (
        <Button size="sm" variant="danger" icon={Trash2} onClick={onEmptyTrash} className="ms-auto">
          Empty trash
        </Button>
      )}
    </div>
  )
}
