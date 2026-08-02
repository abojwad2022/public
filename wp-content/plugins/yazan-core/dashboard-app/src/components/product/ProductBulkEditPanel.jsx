import { useState } from 'react'
import { Alert, Button, Drawer, Field, Input, Select } from '../ui/index.js'

/**
 * wp-admin's Bulk Edit panel.
 *
 * The governing idea is WooCommerce's: every field defaults to "— No change —", and only
 * the fields an operator actually touches are sent. That is why the payload omits
 * untouched keys entirely rather than sending empty strings — on the server, "absent"
 * is the only unambiguous way to say "leave this alone".
 *
 * Numeric fields carry a mode (change / increase by / decrease by), and a value ending in
 * "%" is treated as a percentage — the same input convention wp-admin uses.
 */

const NO_CHANGE = ''

/** Fields that take a mode + value. Sale price gets WooCommerce's fourth mode. */
const NUMERIC_FIELDS = [
  { key: 'regular_price', label: 'Regular price', money: true },
  { key: 'sale_price', label: 'Sale price', money: true, fromRegular: true },
  { key: 'weight', label: 'Weight' },
  { key: 'length', label: 'Length' },
  { key: 'width', label: 'Width' },
  { key: 'height', label: 'Height' },
]

export default function ProductBulkEditPanel({ open, count, meta, can, busy, onClose, onApply }) {
  const [numeric, setNumeric] = useState({})
  const [plain, setPlain] = useState({})

  const canPrice = can('products.change_price')
  const canStock = can('products.change_stock')

  const setMode = (key, mode) =>
    setNumeric((current) => ({ ...current, [key]: { mode, value: current[key]?.value ?? '' } }))
  const setValue = (key, value) =>
    setNumeric((current) => ({ ...current, [key]: { mode: current[key]?.mode || 'change', value } }))
  const setPlainField = (key, value) =>
    setPlain((current) => {
      const next = { ...current }
      if (value === NO_CHANGE) delete next[key]
      else next[key] = value
      return next
    })

  /** Only fields the operator actually set travel to the server. */
  const buildChanges = () => {
    const changes = { ...plain }
    for (const [key, entry] of Object.entries(numeric)) {
      if (entry?.mode && entry.value !== '') changes[key] = entry
    }
    return changes
  }

  const changes = buildChanges()
  const nothingToDo = Object.keys(changes).length === 0

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={`Bulk edit ${count} product${count === 1 ? '' : 's'}`}
      width="max-w-lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" disabled={nothingToDo} loading={busy} onClick={() => onApply(changes)}>
            Update
          </Button>
        </div>
      }
    >
      <div className="space-y-4">
        <Alert tone="info">
          Every field is left alone unless you change it. Prices and dimensions accept a
          percentage — type <code className="font-mono">10%</code> instead of an amount.
        </Alert>

        {NUMERIC_FIELDS.filter((field) => !field.money || canPrice).map((field) => (
          <NumericRow
            key={field.key}
            field={field}
            entry={numeric[field.key]}
            onMode={(mode) => setMode(field.key, mode)}
            onValue={(value) => setValue(field.key, value)}
          />
        ))}

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Tax status">
            <Select value={plain.tax_status ?? NO_CHANGE} onChange={(e) => setPlainField('tax_status', e.target.value)}>
              <option value={NO_CHANGE}>— No change —</option>
              <option value="taxable">Taxable</option>
              <option value="shipping">Shipping only</option>
              <option value="none">None</option>
            </Select>
          </Field>
          <Field label="Tax class">
            <Select value={plain.tax_class ?? NO_CHANGE} onChange={(e) => setPlainField('tax_class', e.target.value)}>
              <option value={NO_CHANGE}>— No change —</option>
              {(meta?.tax_classes || []).map((cls) => (
                <option key={cls.slug || 'standard'} value={cls.slug}>
                  {cls.name}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Shipping class">
            <Select
              value={plain.shipping_class_id ?? NO_CHANGE}
              onChange={(e) => setPlainField('shipping_class_id', e.target.value)}
            >
              <option value={NO_CHANGE}>— No change —</option>
              <option value="0">No shipping class</option>
              {(meta?.shipping_classes || []).map((cls) => (
                <option key={cls.id} value={cls.id}>
                  {cls.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Visibility">
            <Select
              value={plain.catalog_visibility ?? NO_CHANGE}
              onChange={(e) => setPlainField('catalog_visibility', e.target.value)}
            >
              <option value={NO_CHANGE}>— No change —</option>
              <option value="visible">Catalog &amp; search</option>
              <option value="catalog">Catalog only</option>
              <option value="search">Search only</option>
              <option value="hidden">Hidden</option>
            </Select>
          </Field>

          <Field label="Featured">
            <Select value={plain.featured ?? NO_CHANGE} onChange={(e) => setPlainField('featured', e.target.value)}>
              <option value={NO_CHANGE}>— No change —</option>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </Select>
          </Field>
          <Field label="Sold individually">
            <Select
              value={plain.sold_individually ?? NO_CHANGE}
              onChange={(e) => setPlainField('sold_individually', e.target.value)}
            >
              <option value={NO_CHANGE}>— No change —</option>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </Select>
          </Field>

          {canStock && (
            <>
              <Field label="Manage stock">
                <Select
                  value={plain.manage_stock ?? NO_CHANGE}
                  onChange={(e) => setPlainField('manage_stock', e.target.value)}
                >
                  <option value={NO_CHANGE}>— No change —</option>
                  <option value="1">Yes</option>
                  <option value="0">No</option>
                </Select>
              </Field>
              <Field label="Stock status">
                <Select
                  value={plain.stock_status ?? NO_CHANGE}
                  onChange={(e) => setPlainField('stock_status', e.target.value)}
                >
                  <option value={NO_CHANGE}>— No change —</option>
                  {Object.entries(meta?.stock_statuses || {}).map(([key, label]) => (
                    <option key={key} value={key}>
                      {label}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Backorders">
                <Select
                  value={plain.backorders ?? NO_CHANGE}
                  onChange={(e) => setPlainField('backorders', e.target.value)}
                >
                  <option value={NO_CHANGE}>— No change —</option>
                  <option value="no">Do not allow</option>
                  <option value="notify">Allow, but notify customer</option>
                  <option value="yes">Allow</option>
                </Select>
              </Field>
            </>
          )}
        </div>

        {canStock && (
          <NumericRow
            field={{ key: 'stock_quantity', label: 'Stock quantity' }}
            entry={numeric.stock_quantity}
            onMode={(mode) => setMode('stock_quantity', mode)}
            onValue={(value) => setValue('stock_quantity', value)}
          />
        )}

        {canPrice && (
          <p className="text-xs text-muted">
            Prices apply to simple and external products only — a variable product’s prices
            live on its variations.
          </p>
        )}
      </div>
    </Drawer>
  )
}

function NumericRow({ field, entry, onMode, onValue }) {
  const mode = entry?.mode || NO_CHANGE

  return (
    <div className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end">
      <Field label={field.label}>
        <Select value={mode} onChange={(e) => onMode(e.target.value)}>
          <option value={NO_CHANGE}>— No change —</option>
          <option value="change">Change to:</option>
          <option value="inc">Increase by (amount or %):</option>
          <option value="dec">Decrease by (amount or %):</option>
          {field.fromRegular && (
            <option value="from_regular_dec">Set to regular price decreased by (amount or %):</option>
          )}
        </Select>
      </Field>
      {/* The value box only appears once a mode is chosen — same progressive reveal
          wp-admin uses, so an empty row can never be mistaken for a pending edit. */}
      {mode !== NO_CHANGE && (
        <Input
          value={entry?.value ?? ''}
          onChange={(e) => onValue(e.target.value)}
          placeholder={field.money ? '0.00 or 10%' : '0 or 10%'}
          aria-label={`${field.label} value`}
          className="sm:w-40"
        />
      )}
    </div>
  )
}
