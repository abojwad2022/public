import { useState } from 'react'
import { useMeta } from '../../context/MetaContext.jsx'
import { Button, Checkbox, Field, Input, Select, Textarea } from '../ui.jsx'
import ProductPicker from '../order/ProductPicker.jsx'

/**
 * Faithful re-creation of the WooCommerce "Product data" metabox: a type selector with
 * virtual/downloadable toggles, and the General/Inventory/Shipping/Attributes/Variations/Advanced
 * tabs. Which tabs show depends on the product type, exactly as in wp-admin.
 */
export default function ProductDataTabs({ form, set, meta: _meta }) {
  const { meta } = useMeta()
  const [tab, setTab] = useState('general')

  const isVariable = form.type === 'variable'
  const isGrouped = form.type === 'grouped'
  const isExternal = form.type === 'external'

  const tabs = [
    { key: 'general', label: 'General', show: !isGrouped },
    { key: 'inventory', label: 'Inventory', show: true },
    { key: 'shipping', label: 'Shipping', show: !form.virtual && !isExternal },
    { key: 'linked', label: 'Linked products', show: true },
    { key: 'attributes', label: 'Attributes', show: true },
    { key: 'variations', label: 'Variations', show: isVariable },
    { key: 'advanced', label: 'Advanced', show: true },
  ].filter((entry) => entry.show)

  const activeTab = tabs.some((entry) => entry.key === tab) ? tab : tabs[0].key

  return (
    <div className="yz-card">
      {/* Type selector */}
      <div className="yz-card-head flex-wrap gap-3">
        <h2 className="yz-card-title">Product data</h2>
        <div className="flex flex-wrap items-center gap-4">
          <Select
            value={form.type}
            onChange={(event) => set('type', event.target.value)}
            className="w-auto"
            aria-label="Product type"
          >
            {Object.entries(meta?.product_types || { simple: 'Simple product' }).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </Select>
          {!isGrouped && !isExternal && (
            <>
              <Checkbox label="Virtual" checked={form.virtual} onChange={(value) => set('virtual', value)} />
              <Checkbox
                label="Downloadable"
                checked={form.downloadable}
                onChange={(value) => set('downloadable', value)}
              />
            </>
          )}
        </div>
      </div>

      <div className="flex flex-col sm:flex-row">
        {/* Tab rail */}
        <nav className="sm:w-44 shrink-0 border-b sm:border-b-0 sm:border-e border-edge flex sm:flex-col overflow-x-auto">
          {tabs.map((entry) => (
            <button
              key={entry.key}
              type="button"
              onClick={() => setTab(entry.key)}
              className={`px-4 py-2.5 text-sm text-start whitespace-nowrap border-s-2 transition-colors ${
                activeTab === entry.key
                  ? 'border-agate bg-surface2 text-fg'
                  : 'border-transparent text-muted hover:text-fg'
              }`}
            >
              {entry.label}
            </button>
          ))}
        </nav>

        <div className="flex-1 p-4 min-w-0">
          {activeTab === 'general' && <GeneralTab form={form} set={set} meta={meta} />}
          {activeTab === 'inventory' && <InventoryTab form={form} set={set} isVariable={isVariable} />}
          {activeTab === 'shipping' && <ShippingTab form={form} set={set} meta={meta} />}
          {activeTab === 'linked' && (
            <LinkedTab form={form} set={set} isGrouped={isGrouped} isExternal={isExternal} />
          )}
          {activeTab === 'attributes' && <AttributesTab form={form} set={set} meta={meta} />}
          {activeTab === 'variations' && <VariationsTab form={form} set={set} meta={meta} />}
          {activeTab === 'advanced' && <AdvancedTab form={form} set={set} />}
        </div>
      </div>
    </div>
  )
}

/* ---------------------------------------------------------------- General */

function GeneralTab({ form, set, meta }) {
  const currency = meta?.currency?.symbol ?? '$'
  const isVariable = form.type === 'variable'

  if (isVariable) {
    return (
      <p className="text-sm text-muted">
        Pricing for variable products is set per variation — see the Variations tab.
      </p>
    )
  }

  return (
    <div className="space-y-4">
      <div className="grid sm:grid-cols-2 gap-4">
        <Field label={`Regular price (${currency})`}>
          <Input
            type="number"
            step="0.01"
            min="0"
            value={form.regular_price}
            onChange={(event) => set('regular_price', event.target.value)}
          />
        </Field>
        <Field label={`Sale price (${currency})`}>
          <Input
            type="number"
            step="0.01"
            min="0"
            value={form.sale_price}
            onChange={(event) => set('sale_price', event.target.value)}
          />
        </Field>
      </div>

      <div className="grid sm:grid-cols-2 gap-4">
        <Field label="Sale starts" help="Leave blank to start immediately.">
          <Input type="date" value={form.sale_from} onChange={(event) => set('sale_from', event.target.value)} />
        </Field>
        <Field label="Sale ends">
          <Input type="date" value={form.sale_to} onChange={(event) => set('sale_to', event.target.value)} />
        </Field>
      </div>

      <div className="grid sm:grid-cols-2 gap-4 pt-2 border-t border-edge">
        <Field label="Tax status">
          <Select value={form.tax_status} onChange={(event) => set('tax_status', event.target.value)}>
            <option value="taxable">Taxable</option>
            <option value="shipping">Shipping only</option>
            <option value="none">None</option>
          </Select>
        </Field>
        <Field label="Tax class">
          <Select value={form.tax_class} onChange={(event) => set('tax_class', event.target.value)}>
            {(meta?.tax_classes || []).map((entry) => (
              <option key={entry.slug} value={entry.slug}>
                {entry.name}
              </option>
            ))}
          </Select>
        </Field>
      </div>

      {form.downloadable && (
        <div className="pt-3 border-t border-edge space-y-4">
          <DownloadsEditor form={form} set={set} />
          <div className="grid sm:grid-cols-2 gap-4">
            <Field label="Download limit" help="Blank or -1 for unlimited.">
              <Input
                type="number"
                value={form.download_limit}
                onChange={(event) => set('download_limit', event.target.value)}
              />
            </Field>
            <Field label="Download expiry (days)" help="Blank or -1 for never.">
              <Input
                type="number"
                value={form.download_expiry}
                onChange={(event) => set('download_expiry', event.target.value)}
              />
            </Field>
          </div>
        </div>
      )}
    </div>
  )
}

function DownloadsEditor({ form, set }) {
  const rows = form.downloads || []
  const update = (index, key, value) => {
    const next = rows.map((row, i) => (i === index ? { ...row, [key]: value } : row))
    set('downloads', next)
  }
  return (
    <div>
      <span className="yz-label">Downloadable files</span>
      <div className="space-y-2">
        {rows.map((row, index) => (
          <div key={index} className="flex gap-2">
            <Input
              placeholder="Name"
              value={row.name}
              onChange={(event) => update(index, 'name', event.target.value)}
              className="w-1/3"
            />
            <Input
              placeholder="File URL"
              value={row.file}
              onChange={(event) => update(index, 'file', event.target.value)}
            />
            <Button
              type="button"
              variant="danger"
              small
              onClick={() => set('downloads', rows.filter((_, i) => i !== index))}
            >
              ✕
            </Button>
          </div>
        ))}
      </div>
      <Button type="button" small className="mt-2" onClick={() => set('downloads', [...rows, { name: '', file: '' }])}>
        + Add file
      </Button>
    </div>
  )
}

/* -------------------------------------------------------------- Inventory */

function InventoryTab({ form, set, isVariable }) {
  return (
    <div className="space-y-4">
      <Field label="SKU" help="Stock keeping unit — must be unique across the store.">
        <Input value={form.sku} onChange={(event) => set('sku', event.target.value)} />
      </Field>

      <Checkbox
        label="Track stock quantity for this product"
        checked={form.manage_stock}
        onChange={(value) => set('manage_stock', value)}
        disabled={isVariable}
        help={isVariable ? 'Variable products manage stock per variation.' : undefined}
      />

      {form.manage_stock ? (
        <div className="grid sm:grid-cols-3 gap-4">
          <Field label="Quantity">
            <Input
              type="number"
              value={form.stock_quantity ?? ''}
              onChange={(event) => set('stock_quantity', event.target.value)}
            />
          </Field>
          <Field label="Allow backorders">
            <Select value={form.backorders} onChange={(event) => set('backorders', event.target.value)}>
              <option value="no">Do not allow</option>
              <option value="notify">Allow, but notify customer</option>
              <option value="yes">Allow</option>
            </Select>
          </Field>
          <Field label="Low stock threshold">
            <Input
              type="number"
              value={form.low_stock_amount ?? ''}
              onChange={(event) => set('low_stock_amount', event.target.value)}
            />
          </Field>
        </div>
      ) : (
        <Field label="Stock status">
          <Select value={form.stock_status} onChange={(event) => set('stock_status', event.target.value)}>
            <option value="instock">In stock</option>
            <option value="outofstock">Out of stock</option>
            <option value="onbackorder">On backorder</option>
          </Select>
        </Field>
      )}

      <Checkbox
        label="Limit purchases to one item per order"
        checked={form.sold_individually}
        onChange={(value) => set('sold_individually', value)}
        help="Recommended for one-of-one stones."
      />
    </div>
  )
}

/* --------------------------------------------------------------- Shipping */

function ShippingTab({ form, set, meta }) {
  return (
    <div className="space-y-4">
      <Field label="Weight">
        <Input value={form.weight} onChange={(event) => set('weight', event.target.value)} />
      </Field>

      <div>
        <span className="yz-label">Dimensions (L × W × H)</span>
        <div className="grid grid-cols-3 gap-2">
          <Input placeholder="Length" value={form.length} onChange={(event) => set('length', event.target.value)} />
          <Input placeholder="Width" value={form.width} onChange={(event) => set('width', event.target.value)} />
          <Input placeholder="Height" value={form.height} onChange={(event) => set('height', event.target.value)} />
        </div>
      </div>

      <Field label="Shipping class">
        <Select
          value={form.shipping_class_id || 0}
          onChange={(event) => set('shipping_class_id', Number(event.target.value))}
        >
          <option value={0}>No shipping class</option>
          {(meta?.shipping_classes || []).map((entry) => (
            <option key={entry.id} value={entry.id}>
              {entry.name}
            </option>
          ))}
        </Select>
      </Field>
    </div>
  )
}

/* ------------------------------------------------------------- Attributes */

function AttributesTab({ form, set, meta }) {
  const jewelryTaxonomies = new Set((meta?.jewelry_fields || []).map((field) => field.taxonomy))
  // Jewelry taxonomies are owned by the Jewelry panel — hide them here to avoid two editors
  // writing the same taxonomy.
  const rows = (form.attributes || []).filter((row) => !jewelryTaxonomies.has(row.taxonomy))

  const replaceRows = (next) => {
    const jewelryRows = (form.attributes || []).filter((row) => jewelryTaxonomies.has(row.taxonomy))
    set('attributes', [...jewelryRows, ...next])
  }

  const update = (index, patch) => replaceRows(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)))

  const addGlobal = (taxonomy) => {
    if (!taxonomy) return
    const attribute = meta.attributes.find((entry) => entry.taxonomy === taxonomy)
    replaceRows([
      ...rows,
      { key: taxonomy, taxonomy, name: taxonomy, label: attribute.name, options: [], visible: true, variation: false },
    ])
  }

  const addCustom = () =>
    replaceRows([...rows, { key: '', taxonomy: '', name: '', label: '', options: [], visible: true, variation: false }])

  const available = (meta?.attributes || []).filter(
    (entry) => !entry.jewelry && !rows.some((row) => row.taxonomy === entry.taxonomy),
  )

  return (
    <div className="space-y-4">
      <p className="text-xs text-faint">
        Agate, silver, size and rarity are managed in the Jewelry panel below — they are the same
        WooCommerce attributes, just presented as proper fields.
      </p>

      {rows.length === 0 && <p className="text-sm text-muted">No additional attributes.</p>}

      {rows.map((row, index) => {
        const definition = row.taxonomy ? meta.attributes.find((entry) => entry.taxonomy === row.taxonomy) : null
        return (
          <div key={index} className="border border-edge p-3 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <strong className="text-sm text-fg">{definition ? definition.name : 'Custom attribute'}</strong>
              <Button type="button" variant="danger" small onClick={() => replaceRows(rows.filter((_, i) => i !== index))}>
                Remove
              </Button>
            </div>

            {definition ? (
              <div className="flex flex-wrap gap-1.5">
                {definition.terms.map((term) => {
                  const active = row.options.map(Number).includes(term.id)
                  return (
                    <button
                      key={term.id}
                      type="button"
                      onClick={() =>
                        update(index, {
                          options: active
                            ? row.options.filter((id) => Number(id) !== term.id)
                            : [...row.options, term.id],
                        })
                      }
                      className={`yz-badge transition-colors ${
                        active ? 'border-gold text-gold' : 'border-edge text-muted hover:text-fg'
                      }`}
                    >
                      {term.name}
                    </button>
                  )
                })}
              </div>
            ) : (
              <div className="grid sm:grid-cols-2 gap-2">
                <Input
                  placeholder="Attribute name"
                  value={row.name}
                  onChange={(event) => update(index, { name: event.target.value })}
                />
                <Input
                  placeholder="Values, comma separated"
                  value={(row.options || []).join(', ')}
                  onChange={(event) =>
                    update(index, { options: event.target.value.split(',').map((value) => value.trim()) })
                  }
                />
              </div>
            )}

            <div className="flex flex-wrap gap-4">
              <Checkbox
                label="Visible on the product page"
                checked={row.visible}
                onChange={(value) => update(index, { visible: value })}
              />
              {form.type === 'variable' && (
                <Checkbox
                  label="Used for variations"
                  checked={row.variation}
                  onChange={(value) => update(index, { variation: value })}
                />
              )}
            </div>
          </div>
        )
      })}

      <div className="flex flex-wrap gap-2 pt-2 border-t border-edge">
        <Select
          value=""
          onChange={(event) => addGlobal(event.target.value)}
          className="w-auto"
          aria-label="Add existing attribute"
        >
          <option value="">Add existing attribute…</option>
          {available.map((entry) => (
            <option key={entry.taxonomy} value={entry.taxonomy}>
              {entry.name}
            </option>
          ))}
        </Select>
        <Button type="button" small onClick={addCustom}>
          + Custom attribute
        </Button>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------- Variations */

function VariationsTab({ form, set, meta }) {
  const variationAttributes = (form.attributes || []).filter((row) => row.variation && row.taxonomy)

  const update = (index, patch) =>
    set('variations', form.variations.map((row, i) => (i === index ? { ...row, ...patch } : row)))

  const add = () =>
    set('variations', [
      ...(form.variations || []),
      {
        id: 0,
        attributes: {},
        sku: '',
        regular_price: '',
        sale_price: '',
        manage_stock: false,
        stock_quantity: null,
        stock_status: 'instock',
        description: '',
      },
    ])

  const remove = (index) => {
    const row = form.variations[index]
    if (row.id) set('delete_variations', [...(form.delete_variations || []), row.id])
    set('variations', form.variations.filter((_, i) => i !== index))
  }

  if (variationAttributes.length === 0) {
    return (
      <p className="text-sm text-muted">
        To build variations, first add an attribute in the Attributes tab and tick{' '}
        <em className="text-fg">Used for variations</em>.
      </p>
    )
  }

  return (
    <div className="space-y-3">
      {(form.variations || []).map((row, index) => (
        <div key={row.id || `new-${index}`} className="border border-edge p-3 space-y-3">
          <div className="flex items-center justify-between">
            <strong className="text-sm text-fg">
              {row.id ? `Variation #${row.id}` : 'New variation'}
            </strong>
            <Button type="button" variant="danger" small onClick={() => remove(index)}>
              Remove
            </Button>
          </div>

          <div className="grid sm:grid-cols-2 gap-2">
            {variationAttributes.map((attribute) => {
              const definition = meta.attributes.find((entry) => entry.taxonomy === attribute.taxonomy)
              return (
                <Field key={attribute.taxonomy} label={definition?.name || attribute.taxonomy}>
                  <Select
                    value={row.attributes?.[attribute.taxonomy] || ''}
                    onChange={(event) =>
                      update(index, {
                        attributes: { ...row.attributes, [attribute.taxonomy]: event.target.value },
                      })
                    }
                  >
                    <option value="">Any</option>
                    {(definition?.terms || [])
                      .filter((term) => attribute.options.map(Number).includes(term.id))
                      .map((term) => (
                        <option key={term.id} value={term.slug}>
                          {term.name}
                        </option>
                      ))}
                  </Select>
                </Field>
              )
            })}
          </div>

          <div className="grid sm:grid-cols-3 gap-2">
            <Field label="SKU">
              <Input value={row.sku} onChange={(event) => update(index, { sku: event.target.value })} />
            </Field>
            <Field label="Regular price">
              <Input
                type="number"
                step="0.01"
                value={row.regular_price}
                onChange={(event) => update(index, { regular_price: event.target.value })}
              />
            </Field>
            <Field label="Sale price">
              <Input
                type="number"
                step="0.01"
                value={row.sale_price}
                onChange={(event) => update(index, { sale_price: event.target.value })}
              />
            </Field>
          </div>

          <div className="flex flex-wrap items-end gap-4">
            <Checkbox
              label="Manage stock"
              checked={row.manage_stock}
              onChange={(value) => update(index, { manage_stock: value })}
            />
            {row.manage_stock ? (
              <Field label="Quantity" className="w-32">
                <Input
                  type="number"
                  value={row.stock_quantity ?? ''}
                  onChange={(event) => update(index, { stock_quantity: event.target.value })}
                />
              </Field>
            ) : (
              <Field label="Stock status" className="w-44">
                <Select
                  value={row.stock_status}
                  onChange={(event) => update(index, { stock_status: event.target.value })}
                >
                  <option value="instock">In stock</option>
                  <option value="outofstock">Out of stock</option>
                  <option value="onbackorder">On backorder</option>
                </Select>
              </Field>
            )}
          </div>
        </div>
      ))}

      <Button type="button" small onClick={add}>
        + Add variation
      </Button>
    </div>
  )
}

/* --------------------------------------------------------------- Advanced */

/* ------------------------------------------------------------- Linked products */

/**
 * A list of linked products (upsells, cross-sells, grouped children) shown as removable
 * rows with an "Add" button that opens the shared ProductPicker.
 *
 * Values are kept as [{ id, name, sku }] to match what the API returns; ProductEditor maps
 * them down to plain id arrays when saving.
 */
function LinkedList({ label, help, value, onChange, excludeId }) {
  const [picking, setPicking] = useState(false)
  const rows = value || []

  const add = (product) => {
    setPicking(false)
    if (!product || product.id === excludeId) return
    if (rows.some((row) => row.id === product.id)) return // already linked
    onChange([...rows, { id: product.id, name: product.name, sku: product.sku || '' }])
  }

  return (
    <Field label={label} help={help}>
      <div className="space-y-2">
        {rows.length === 0 ? (
          <p className="text-sm text-muted">None selected.</p>
        ) : (
          <ul className="space-y-1">
            {rows.map((row) => (
              <li
                key={row.id}
                className="flex items-center justify-between gap-3 border border-edge px-3 py-2"
              >
                <span className="text-fg">
                  {row.name}
                  {row.sku && <span className="ms-2 text-xs text-faint font-mono">{row.sku}</span>}
                </span>
                <button
                  type="button"
                  onClick={() => onChange(rows.filter((entry) => entry.id !== row.id))}
                  className="text-muted hover:text-danger transition-colors text-lg leading-none"
                  aria-label={`Remove ${row.name}`}
                >
                  ×
                </button>
              </li>
            ))}
          </ul>
        )}

        <Button small onClick={() => setPicking(true)}>
          + Add product
        </Button>
      </div>

      <ProductPicker open={picking} onClose={() => setPicking(false)} onSelect={add} />
    </Field>
  )
}

function LinkedTab({ form, set, isGrouped, isExternal }) {
  return (
    <div className="space-y-5">
      {isGrouped && (
        <LinkedList
          label="Grouped products"
          help="The products sold together as this group."
          value={form.children}
          onChange={(value) => set('children', value)}
          excludeId={form.id}
        />
      )}

      {isExternal && (
        <>
          <Field label="Product URL" help="Where customers are sent to buy this item.">
            <Input
              type="url"
              placeholder="https://"
              value={form.product_url || ''}
              onChange={(event) => set('product_url', event.target.value)}
            />
          </Field>
          <Field label="Button text" help="Replaces “Add to cart” on the product page.">
            <Input
              value={form.button_text || ''}
              placeholder="Buy product"
              onChange={(event) => set('button_text', event.target.value)}
            />
          </Field>
        </>
      )}

      <LinkedList
        label="Upsells"
        help="Shown on the product page as a step up from this ring."
        value={form.upsells}
        onChange={(value) => set('upsells', value)}
        excludeId={form.id}
      />

      <LinkedList
        label="Cross-sells"
        help="Promoted in the cart alongside this ring."
        value={form.cross_sells}
        onChange={(value) => set('cross_sells', value)}
        excludeId={form.id}
      />
    </div>
  )
}

function AdvancedTab({ form, set }) {
  return (
    <div className="space-y-4">
      <Field label="Purchase note" help="Sent to the customer after they buy.">
        <Textarea
          rows={3}
          value={form.purchase_note}
          onChange={(event) => set('purchase_note', event.target.value)}
        />
      </Field>

      <Field label="Menu order" help="Lower numbers appear first in the catalogue.">
        <Input
          type="number"
          value={form.menu_order}
          onChange={(event) => set('menu_order', event.target.value)}
          className="w-32"
        />
      </Field>

      <Checkbox
        label="Enable reviews"
        checked={form.reviews_allowed}
        onChange={(value) => set('reviews_allowed', value)}
      />
    </div>
  )
}
