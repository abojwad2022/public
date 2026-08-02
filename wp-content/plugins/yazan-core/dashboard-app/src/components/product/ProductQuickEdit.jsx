import { Alert, Button, Field, Input, Modal, Select } from '../ui/index.js'

/**
 * wp-admin's Quick Edit panel, as a modal.
 *
 * Field set and conditional rules are taken from WooCommerce's own quick-edit.js:
 *   • price fields only apply to simple and external products — a variable product's
 *     prices live on its variations, so showing them here would promise an edit that
 *     silently does nothing;
 *   • dimensions are meaningless for a virtual product;
 *   • changing stock status on a variable product hits every variation, which is
 *     surprising enough to warn about.
 *
 * Values are pre-filled from the row already in memory (the API sends them with the
 * list), so opening this costs no request.
 */
const PRICEABLE = ['simple', 'external']

export default function ProductQuickEdit({ product, meta, can, onChange, onClose, onSave, busy }) {
  const { changes } = product
  const set = (patch) => onChange({ ...product, changes: { ...changes, ...patch } })

  const canPrice = can('products.change_price')
  const canStock = can('products.change_stock')
  const showPrices = PRICEABLE.includes(product.type)
  const showDimensions = !product.virtual
  const isVariable = product.type === 'variable'

  return (
    <Modal
      open
      onClose={onClose}
      title="Quick edit"
      description={product.name}
      size="lg"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" onClick={onSave} loading={busy}>
            Save
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <section className="space-y-3">
          <Field label="Name">
            <Input value={changes.name} onChange={(e) => set({ name: e.target.value })} data-autofocus />
          </Field>

          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="SKU">
              <Input value={changes.sku} onChange={(e) => set({ sku: e.target.value })} />
            </Field>
            <Field label="GTIN, UPC, EAN or ISBN">
              <Input
                value={changes.global_unique_id}
                onChange={(e) => set({ global_unique_id: e.target.value })}
              />
            </Field>
            <Field label="Status">
              <Select value={changes.status} onChange={(e) => set({ status: e.target.value })}>
                {Object.entries(meta?.statuses || {}).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </Select>
            </Field>
          </div>

          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="Visibility">
              <Select
                value={changes.catalog_visibility}
                onChange={(e) => set({ catalog_visibility: e.target.value })}
              >
                <option value="visible">Catalog &amp; search</option>
                <option value="catalog">Catalog only</option>
                <option value="search">Search only</option>
                <option value="hidden">Hidden</option>
              </Select>
            </Field>
            <Field label="Featured">
              <Select
                value={changes.featured ? '1' : '0'}
                onChange={(e) => set({ featured: e.target.value === '1' })}
              >
                <option value="0">No</option>
                <option value="1">Yes</option>
              </Select>
            </Field>
            <Field label="Sold individually">
              <Select
                value={changes.sold_individually ? '1' : '0'}
                onChange={(e) => set({ sold_individually: e.target.value === '1' })}
              >
                <option value="0">No</option>
                <option value="1">Yes</option>
              </Select>
            </Field>
          </div>
        </section>

        {showPrices && canPrice && (
          <section className="grid gap-3 sm:grid-cols-2">
            <Field label="Regular price">
              <Input
                type="number"
                step="0.01"
                inputMode="decimal"
                value={changes.regular_price}
                onChange={(e) => set({ regular_price: e.target.value })}
              />
            </Field>
            <Field label="Sale price">
              <Input
                type="number"
                step="0.01"
                inputMode="decimal"
                value={changes.sale_price}
                onChange={(e) => set({ sale_price: e.target.value })}
              />
            </Field>
          </section>
        )}

        <section className="grid gap-3 sm:grid-cols-2">
          <Field label="Tax status">
            <Select value={changes.tax_status} onChange={(e) => set({ tax_status: e.target.value })}>
              <option value="taxable">Taxable</option>
              <option value="shipping">Shipping only</option>
              <option value="none">None</option>
            </Select>
          </Field>
          <Field label="Tax class">
            <Select value={changes.tax_class} onChange={(e) => set({ tax_class: e.target.value })}>
              {(meta?.tax_classes || []).map((cls) => (
                <option key={cls.slug} value={cls.slug}>
                  {cls.name}
                </option>
              ))}
            </Select>
          </Field>
        </section>

        {showDimensions && (
          <section className="grid gap-3 sm:grid-cols-5">
            <Field label="Weight">
              <Input value={changes.weight} onChange={(e) => set({ weight: e.target.value })} inputMode="decimal" />
            </Field>
            <Field label="Length">
              <Input value={changes.length} onChange={(e) => set({ length: e.target.value })} inputMode="decimal" />
            </Field>
            <Field label="Width">
              <Input value={changes.width} onChange={(e) => set({ width: e.target.value })} inputMode="decimal" />
            </Field>
            <Field label="Height">
              <Input value={changes.height} onChange={(e) => set({ height: e.target.value })} inputMode="decimal" />
            </Field>
            <Field label="Shipping class">
              <Select
                value={changes.shipping_class_id}
                onChange={(e) => set({ shipping_class_id: Number(e.target.value) })}
              >
                <option value={0}>No shipping class</option>
                {(meta?.shipping_classes || []).map((cls) => (
                  <option key={cls.id} value={cls.id}>
                    {cls.name}
                  </option>
                ))}
              </Select>
            </Field>
          </section>
        )}

        {canStock && (
          <section className="space-y-3">
            {isVariable && (
              <Alert tone="warn">
                This is a variable product — changing its stock status here applies to every
                variation.
              </Alert>
            )}
            <div className="grid gap-3 sm:grid-cols-4">
              <Field label="Manage stock">
                <Select
                  value={changes.manage_stock ? '1' : '0'}
                  onChange={(e) => set({ manage_stock: e.target.value === '1' })}
                >
                  <option value="0">No</option>
                  <option value="1">Yes</option>
                </Select>
              </Field>
              <Field label="Stock status">
                <Select value={changes.stock_status} onChange={(e) => set({ stock_status: e.target.value })}>
                  {Object.entries(meta?.stock_statuses || {}).map(([key, label]) => (
                    <option key={key} value={key}>
                      {label}
                    </option>
                  ))}
                </Select>
              </Field>
              {/* Quantity only means anything while WooCommerce is tracking it. */}
              {changes.manage_stock && (
                <>
                  <Field label="Stock quantity">
                    <Input
                      type="number"
                      inputMode="numeric"
                      value={changes.stock_quantity ?? ''}
                      onChange={(e) => set({ stock_quantity: e.target.value })}
                    />
                  </Field>
                  <Field label="Backorders">
                    <Select value={changes.backorders} onChange={(e) => set({ backorders: e.target.value })}>
                      <option value="no">Do not allow</option>
                      <option value="notify">Allow, but notify customer</option>
                      <option value="yes">Allow</option>
                    </Select>
                  </Field>
                </>
              )}
            </div>
          </section>
        )}
      </div>
    </Modal>
  )
}

/** The editable snapshot a row hands to the panel. */
export function quickEditStateFrom(product) {
  return {
    id: product.id,
    name: product.name,
    type: product.type,
    virtual: product.virtual,
    changes: {
      name: product.name,
      sku: product.sku || '',
      global_unique_id: product.global_unique_id || '',
      status: product.status,
      catalog_visibility: product.catalog_visibility || 'visible',
      featured: Boolean(product.featured),
      sold_individually: Boolean(product.sold_individually),
      regular_price: product.regular_price || '',
      sale_price: product.sale_price || '',
      tax_status: product.tax_status || 'taxable',
      tax_class: product.tax_class || '',
      weight: product.weight || '',
      length: product.length || '',
      width: product.width || '',
      height: product.height || '',
      shipping_class_id: product.shipping_class_id || 0,
      manage_stock: Boolean(product.manage_stock),
      stock_status: product.stock_status,
      stock_quantity: product.stock_qty ?? '',
      backorders: product.backorders || 'no',
    },
  }
}
