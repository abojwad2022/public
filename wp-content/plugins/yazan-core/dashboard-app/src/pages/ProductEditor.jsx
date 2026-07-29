import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { productsApi } from '../api/endpoints.js'
import { useMeta } from '../context/MetaContext.jsx'
import { useToast } from '../context/ToastContext.jsx'
import { useAuth } from '../context/AuthContext.jsx'
import { PageHeader } from '../components/Layout.jsx'
import { Button, Card, Checkbox, Field, Input, Select, Spinner, Textarea } from '../components/ui/index.js'
import ProductDataTabs from '../components/product/ProductDataTabs.jsx'
import { JewelryPanel, AuthenticityPanel } from '../components/product/YazanPanels.jsx'
import { FeaturedImageField, GalleryField } from '../components/media/ImageFields.jsx'

const BLANK = {
  id: 0,
  name: '',
  type: 'simple',
  status: 'draft',
  featured: false,
  catalog_visibility: 'visible',
  description: '',
  short_description: '',
  sku: '',
  virtual: false,
  downloadable: false,
  regular_price: '',
  sale_price: '',
  sale_from: '',
  sale_to: '',
  tax_status: 'taxable',
  tax_class: '',
  manage_stock: false,
  stock_quantity: null,
  stock_status: 'instock',
  backorders: 'no',
  low_stock_amount: '',
  sold_individually: false,
  weight: '',
  length: '',
  width: '',
  height: '',
  shipping_class_id: 0,
  categories: [],
  tags: [],
  downloads: [],
  download_limit: '',
  download_expiry: '',
  purchase_note: '',
  menu_order: 0,
  reviews_allowed: true,
  images: { featured: 0, featured_url: '', gallery: [] },
  attributes: [],
  variations: [],
  delete_variations: [],
  // Linked products + type-specific fields. Read as [{id,name,sku}], written as id arrays.
  upsells: [],
  cross_sells: [],
  children: [],
  product_url: '',
  button_text: '',
  publish_date: '',
  jewelry: { attributes: {}, collection: 0, serial: '', silver_weight: '', craftsmanship: '', verification_status: 'draft', qr_id: 0, qr_url: '', certificate_id: 0, certificate_url: '' },
}

export default function ProductEditor() {
  const [confirm, confirmDialog] = useConfirm()
  const { id } = useParams()
  const isNew = !id || id === 'new'
  const navigate = useNavigate()
  const toast = useToast()
  const { meta } = useMeta()
  const { can } = useAuth()

  const [form, setForm] = useState(isNew ? BLANK : null)
  const [loading, setLoading] = useState(!isNew)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (isNew) return
    let cancelled = false
    setLoading(true)
    productsApi
      .get(id)
      .then((data) => {
        if (cancelled) return
        setForm({ ...BLANK, ...data, delete_variations: [] })
      })
      .catch((err) => !cancelled && toast.error(err.message))
      .finally(() => !cancelled && setLoading(false))
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, isNew])

  const set = useCallback((key, value) => {
    setForm((current) => ({ ...current, [key]: value }))
  }, [])

  const save = async (statusOverride) => {
    if (!form.name.trim()) {
      toast.error('Please give the product a title.')
      return
    }
    setSaving(true)
    const payload = { ...form }
    if (statusOverride) payload.status = statusOverride

    // Normalise the shapes the API expects.
    payload.images = {
      featured: form.images.featured,
      gallery: form.images.gallery.map((item) => item.id),
    }
    payload.attributes = form.attributes.map((row) => ({
      taxonomy: row.taxonomy || '',
      name: row.name || '',
      options: row.options || [],
      visible: row.visible,
      variation: row.variation,
    }))

    // Linked products travel as plain id arrays.
    const ids = (list) => (list || []).map((item) => item.id)
    payload.upsell_ids = ids(form.upsells)
    payload.cross_sell_ids = ids(form.cross_sells)
    payload.children = ids(form.children)
    delete payload.upsells
    delete payload.cross_sells

    try {
      const saved = isNew ? await productsApi.create(payload) : await productsApi.update(form.id, payload)
      toast.success(isNew ? 'Product created — it is live in WooCommerce.' : 'Product updated.')
      if (isNew) {
        navigate(`/products/${saved.id}`, { replace: true })
      } else {
        setForm({ ...BLANK, ...saved, delete_variations: [] })
      }
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  const remove = async () => {
    if (!(await confirm({ title: `Move “${form.name}” to trash?` }))) return
    try {
      await productsApi.remove(form.id)
      toast.success('Product moved to trash.')
      navigate('/products')
    } catch (err) {
      toast.error(err.message)
    }
  }

  if (loading || !form) return <Spinner label="Loading product…" />

  return (
    <>
      <PageHeader
        title={isNew ? 'Add product' : form.name || 'Untitled product'}
        subtitle={isNew ? 'New product' : `ID ${form.id} · ${form.type}`}
        actions={
          <>
            <Button onClick={() => navigate('/products')}>Back</Button>
            {!isNew && (
              <Link to={`/ai?product=${form.id}`} className="yz-btn yz-btn-ghost">
                AI Studio
              </Link>
            )}
            {!isNew && form.status === 'publish' && (
              <a href={form.permalink} target="_blank" rel="noreferrer" className="yz-btn yz-btn-ghost">
                View
              </a>
            )}
            <Button variant="primary" onClick={() => save()} disabled={saving}>
              {saving ? 'Saving…' : 'Save'}
            </Button>
          </>
        }
      />

      <div className="grid lg:grid-cols-[1fr_20rem] gap-5 items-start">
        {/* Main column */}
        <div className="space-y-5 min-w-0">
          <Card title="General">
            <div className="space-y-4">
              <Field label="Product title">
                <Input
                  value={form.name}
                  onChange={(event) => set('name', event.target.value)}
                  placeholder="e.g. Sana'a Red Aqeeq Signet"
                  autoFocus={isNew}
                />
              </Field>
              <Field label="Short description" help="The summary beside the gallery.">
                <Textarea
                  rows={3}
                  value={form.short_description}
                  onChange={(event) => set('short_description', event.target.value)}
                />
              </Field>
              <Field label="Description" help="The full story — HTML allowed.">
                <Textarea
                  rows={9}
                  value={form.description}
                  onChange={(event) => set('description', event.target.value)}
                />
              </Field>
            </div>
          </Card>

          <ProductDataTabs form={form} set={set} />

          <JewelryPanel form={form} set={set} />
          <AuthenticityPanel form={form} set={set} />
        </div>

        {/* Sidebar */}
        <div className="space-y-5">
          <Card title="Publish">
            <div className="space-y-4">
              <Field label="Status">
                <Select value={form.status} onChange={(event) => set('status', event.target.value)}>
                  {Object.entries(meta?.statuses || {}).map(([key, label]) => (
                    <option key={key} value={key}>
                      {label}
                    </option>
                  ))}
                </Select>
              </Field>

              {/* Scheduling: a future date makes WordPress hold the product as `future`. */}
              <Field
                label="Publish date"
                help={
                  form.status === 'future'
                    ? 'Scheduled — clear this field to publish immediately.'
                    : 'Set a future date and time to schedule this product.'
                }
              >
                <Input
                  type="datetime-local"
                  value={(form.publish_date || form.date_created || '').replace(' ', 'T').slice(0, 16)}
                  onChange={(event) => set('publish_date', event.target.value.replace('T', ' '))}
                />
              </Field>

              <Field label="Catalog visibility">
                <Select
                  value={form.catalog_visibility}
                  onChange={(event) => set('catalog_visibility', event.target.value)}
                >
                  <option value="visible">Shop and search results</option>
                  <option value="catalog">Shop only</option>
                  <option value="search">Search results only</option>
                  <option value="hidden">Hidden</option>
                </Select>
              </Field>

              <Checkbox
                label="Featured product"
                checked={form.featured}
                onChange={(value) => set('featured', value)}
              />

              <div className="flex flex-wrap gap-2 pt-2 border-t border-edge">
                <Button variant="primary" onClick={() => save('publish')} disabled={saving} className="flex-1">
                  {form.status === 'publish' ? 'Update' : 'Publish'}
                </Button>
                {form.status !== 'publish' && (
                  <Button onClick={() => save('draft')} disabled={saving}>
                    Save draft
                  </Button>
                )}
              </div>

              {!isNew && can('delete_products') && (
                <button
                  type="button"
                  onClick={remove}
                  className="text-xs text-muted hover:text-danger transition-colors"
                >
                  Move to trash
                </button>
              )}
            </div>
          </Card>

          <FeaturedImageField
            value={form.images.featured}
            url={form.images.featured_url}
            onChange={(attachmentId, url) =>
              set('images', { ...form.images, featured: attachmentId, featured_url: url })
            }
          />

          <GalleryField
            items={form.images.gallery}
            onChange={(gallery) => set('images', { ...form.images, gallery })}
          />

          <TermsCard
            title="Categories"
            terms={meta?.categories || []}
            selected={form.categories}
            onChange={(ids) => set('categories', ids)}
            hierarchical
          />

          <TermsCard
            title="Tags"
            terms={meta?.tags || []}
            selected={form.tags}
            onChange={(ids) => set('tags', ids)}
          />
        </div>
      </div>
      {confirmDialog}
    </>
  )
}

function TermsCard({ title, terms, selected, onChange, hierarchical }) {
  const toggle = (termId) =>
    onChange(selected.includes(termId) ? selected.filter((id) => id !== termId) : [...selected, termId])

  if (!terms.length) {
    return (
      <Card title={title}>
        <p className="text-sm text-muted">None available.</p>
      </Card>
    )
  }

  return (
    <Card title={title} bodyClass="p-3 max-h-64 overflow-y-auto">
      <div className="space-y-1.5">
        {terms.map((term) => (
          <label
            key={term.id}
            className="flex items-center gap-2 cursor-pointer text-sm"
            style={hierarchical && term.parent ? { paddingLeft: '1rem' } : undefined}
          >
            <input
              type="checkbox"
              checked={selected.includes(term.id)}
              onChange={() => toggle(term.id)}
              className="size-4 accent-[var(--yz-agate)]"
            />
            <span className={selected.includes(term.id) ? 'text-fg' : 'text-muted'}>{term.name}</span>
          </label>
        ))}
      </div>
    </Card>
  )
}
