/**
 * Typed-ish wrappers around the yazan/v1 REST surface.
 * Keeping every path in one place makes the API contract easy to audit.
 */
import { api, namespace } from './client.js'

export const authApi = {
  me: () => api.get('/auth/me'),
  // Bypasses X-WP-Nonce (see client.js) — this route is public and a stale nonce from an earlier,
  // unrelated session on this browser must never be able to block signing in.
  login: (username, password, remember) =>
    api.post('/auth/login', { username, password, remember }, { skipNonce: true }),
  logout: () => api.post('/auth/logout', {}),
}

export const productsApi = {
  list: (params) => api.get('/products', params),
  get: (id) => api.get(`/products/${id}`),
  create: (payload) => api.post('/products', payload),
  update: (id, payload) => api.put(`/products/${id}`, payload),
  remove: (id, force = false) => api.del(`/products/${id}`, force ? { force: 1 } : undefined),
  bulk: (action, ids) => api.post('/products/bulk', { action, ids }),
  bulkEdit: (ids, changes) => api.post('/products/bulk-edit', { ids, changes }),
  duplicate: (id) => api.post(`/products/${id}/duplicate`, {}),
  restore: (id) => api.post(`/products/${id}/restore`, {}),
  emptyTrash: () => api.post('/products/trash/empty', {}),
  quickEdit: (items) => api.post('/products/quick-edit', { items }),
}

export const mediaApi = {
  list: (params) => api.get('/media', params),
  upload: (file) => {
    const form = new FormData()
    form.append('file', file)
    return api.upload('/media', form)
  },
  remove: (id) => api.del(`/media/${id}`, { force: 1 }),
}

export const ordersApi = {
  list: (params) => api.get('/orders', params),
  get: (id) => api.get(`/orders/${id}`),
  create: (payload) => api.post('/orders', payload),
  update: (id, payload) => api.put(`/orders/${id}`, payload),
  bulkStatus: (status, ids) => api.post('/orders/bulk', { status, ids }),
  notes: (id) => api.get(`/orders/${id}/notes`),
  addNote: (id, note, customerNote = false) =>
    api.post(`/orders/${id}/notes`, { note, customer_note: customerNote }),

  // Money-touching operations — the server re-validates every one of these.
  updateItems: (id, payload) => api.put(`/orders/${id}/items`, payload),
  updateAddresses: (id, payload) => api.put(`/orders/${id}/addresses`, payload),
  addCoupon: (id, code) => api.post(`/orders/${id}/coupons`, { code }),
  removeCoupon: (id, code) => api.del(`/orders/${id}/coupons`, { code }),
  createRefund: (id, payload) => api.post(`/orders/${id}/refunds`, payload),
  deleteRefund: (id, refundId) => api.del(`/orders/${id}/refunds/${refundId}`),
}

export const customersApi = {
  list: (params) => api.get('/customers', params),
  get: (id) => api.get(`/customers/${id}`),
}

export const termsApi = {
  list: (taxonomy, params) => api.get(`/terms/${taxonomy}`, params),
  create: (taxonomy, payload) => api.post(`/terms/${taxonomy}`, payload),
  update: (taxonomy, id, payload) => api.put(`/terms/${taxonomy}/${id}`, payload),
  remove: (taxonomy, id) => api.del(`/terms/${taxonomy}/${id}`),
}

export const attributesApi = {
  list: () => api.get('/attributes'),
  create: (payload) => api.post('/attributes', payload),
  update: (id, payload) => api.put(`/attributes/${id}`, payload),
  remove: (id) => api.del(`/attributes/${id}`),
}

export const inventoryApi = {
  bulkSave: (items) => api.post('/inventory/bulk', { items }),
}

export const couponsApi = {
  list: (params) => api.get('/coupons', params),
  get: (id) => api.get(`/coupons/${id}`),
  create: (payload) => api.post('/coupons', payload),
  update: (id, payload) => api.put(`/coupons/${id}`, payload),
  remove: (id) => api.del(`/coupons/${id}`),
}

export const reportsApi = {
  sales: (params) => api.get('/reports/sales', params),
  stock: () => api.get('/reports/stock'),
}

export const taxApi = {
  get: () => api.get('/tax'),
  saveSettings: (settings) => api.put('/tax', { settings }),
  createClass: (name) => api.post('/tax/classes', { name }),
  deleteClass: (slug) => api.del('/tax/classes', { slug }),
  rates: (taxClass) => api.get('/tax/rates', { class: taxClass }),
  createRate: (payload) => api.post('/tax/rates', payload),
  updateRate: (id, payload) => api.put(`/tax/rates/${id}`, payload),
  deleteRate: (id) => api.del(`/tax/rates/${id}`),
}

export const shippingApi = {
  zones: () => api.get('/shipping/zones'),
  createZone: (payload) => api.post('/shipping/zones', payload),
  updateZone: (id, payload) => api.put(`/shipping/zones/${id}`, payload),
  deleteZone: (id) => api.del(`/shipping/zones/${id}`),
  addMethod: (zoneId, methodId) => api.post(`/shipping/zones/${zoneId}/methods`, { method_id: methodId }),
  updateMethod: (zoneId, instance, payload) => api.put(`/shipping/zones/${zoneId}/methods/${instance}`, payload),
  deleteMethod: (zoneId, instance) => api.del(`/shipping/zones/${zoneId}/methods/${instance}`),
}

export const gatewaysApi = {
  list: () => api.get('/gateways'),
  update: (id, payload) => api.put(`/gateways/${id}`, payload),
  reorder: (order) => api.put('/gateways', { order }),
}

// Write-only by design: get() never returns secret values, only whether each field is set.
export const socialAuthApi = {
  get: () => api.get('/social-auth'),
  save: (providers) => api.put('/social-auth', { providers }),
}

export const emailsApi = {
  list: () => api.get('/emails'),
  saveGlobals: (settings) => api.put('/emails', { settings }),
  update: (id, payload) => api.put(`/emails/${id}`, payload),
}

export const portingApi = {
  exportCsv: (params) => api.get('/porting/export', params),
  import: (csv, dryRun, createMissing, type) =>
    api.post('/porting/import', { csv, dry_run: dryRun, create_missing: createMissing, type }),
}

export const webhooksApi = {
  list: () => api.get('/webhooks'),
  create: (payload) => api.post('/webhooks', payload),
  update: (id, payload) => api.put(`/webhooks/${id}`, payload),
  remove: (id) => api.del(`/webhooks/${id}`),
}

export const statusApi = {
  get: () => api.get('/status'),
  runTool: (tool) => api.post(`/status/tools/${tool}`, {}),
}

export const settingsApi = {
  get: () => api.get('/settings'),
  save: (settings) => api.put('/settings', { settings }),
}

export const auditApi = {
  list: (params) => api.get('/audit', params),
  purge: (days) => api.post('/audit/purge', { days }),
}

export const metaApi = {
  taxonomies: () => api.get('/meta/taxonomies'),
  stats: () => api.get('/stats'),
}

// Full-site backup & restore. Download uses a two-step token flow: mint a single-use token with the
// nonce, then navigate the browser to the returned URL so a large archive streams straight from disk.
export const backupApi = {
  index: () => api.get('/backup'),
  create: (scope = 'full', keep) => api.post('/backup', { scope, keep }),
  remove: (id) => api.del(`/backup/${id}`),
  restore: (id, safety = true) => api.post(`/backup/${id}/restore`, { confirm: 'RESTORE', safety }),
  downloadToken: (id) => api.post(`/backup/${id}/download-token`, {}),
}

// AI Store Manager — the yazan/v1/ai/* surface. Secrets are write-only: getSettings/getCredentials
// return only whether a key is set and its last 4 chars, never the key itself.
export const aiApi = {
  getSettings: () => api.get('/ai/settings'),
  saveSettings: (settings) => api.put('/ai/settings', { settings }),
  getCredentials: () => api.get('/ai/credentials'),
  setCredential: (provider, key) => api.post('/ai/credentials', { provider, key }),
  test: (provider) => api.post('/ai/test', { provider }),
  testAll: () => api.post('/ai/test-all', {}),
  testCore: () => api.post('/ai/core/test', {}),

  // Generation. `product` accepts { media_id | image, hints, language, product_id }.
  product: (payload) => api.post('/ai/product', payload),
  seo: (productId, language) => api.post('/ai/seo', { product_id: productId, language }),
  marketing: (productId, language, channels) =>
    api.post('/ai/marketing', { product_id: productId, language, channels }),

  // Gallery manager. plan = dry-run validation; generate = image-to-image (returned for review).
  galleryPlan: (payload) => api.post('/ai/gallery/plan', payload),
  galleryGenerate: (payload) => api.post('/ai/gallery/generate', payload),

  analytics: (params) => api.get('/ai/analytics', params),
  logs: (params) => api.get('/ai/logs', params),
}

// Access control. Staff accounts — people who hold a Yazan role and can sign into /dashboard.
// Shoppers stay on customersApi; the server refuses to return them here at all.
export const usersApi = {
  list: (params) => api.get('/users', params),
  get: (id) => api.get(`/users/${id}`),
  create: (payload) => api.post('/users', payload),
  update: (id, payload) => api.put(`/users/${id}`, payload),
  remove: (id, reassign) => api.del(`/users/${id}`, reassign ? { reassign } : undefined),

  suspend: (id) => api.post(`/users/${id}/suspend`, {}),
  activate: (id) => api.post(`/users/${id}/activate`, {}),
  forceLogout: (id) => api.post(`/users/${id}/force-logout`, {}),
  activity: (id, params) => api.get(`/users/${id}/activity`, params),

  // mode 'link' emails a WordPress reset link and is the default; 'set' writes a password directly
  // and is refused on your own account, because it destroys every session you hold.
  resetPassword: (id, mode = 'link', password) =>
    api.post(`/users/${id}/reset-password`, { mode, password }),

  uploadPhoto: (id, file) => {
    const form = new FormData()
    form.append('file', file)
    return api.upload(`/users/${id}/photo`, form)
  },
}

// Roles. `update` sends the COMPLETE permission list — the server replaces the set wholesale and
// audits the diff — plus the `updated_at` the editor loaded, so a concurrent save 409s instead of
// silently overwriting someone else's work.
export const rolesApi = {
  list: (params) => api.get('/roles', params),
  get: (id) => api.get(`/roles/${id}`),
  create: (payload) => api.post('/roles', payload),
  update: (id, payload) => api.put(`/roles/${id}`, payload),
  remove: (id, reassignTo) =>
    api.del(`/roles/${id}`, reassignTo ? { reassign_to: reassignTo } : undefined),
  duplicate: (id, name) => api.post(`/roles/${id}/duplicate`, { name }),
  members: (id, params) => api.get(`/roles/${id}/users`, params),
}

// The Homepage Manager. Every path the builder screen can reach, in the order the screen uses
// them: open, save, publish, then the things that are only reached from a panel.
export const homepageApi = {
  // One request opens the builder: document, component catalog, and the permission map the UI
  // draws from. Three calls would let the screen render before it knows what the user may do.
  get: (params) => api.get('/homepage', params),
  components: () => api.get('/homepage/components'),

  // The whole draft in one write. Every section still goes through the same sanitiser and the
  // same field-permission filter as a single-section edit — this is not a shortcut around either.
  save: (payload) => api.put('/homepage', payload),

  publish: (payload) => api.post('/homepage/publish', payload),
  schedule: (payload) => api.post('/homepage/schedule', payload),

  // Puts the PREVIOUS published version back on the live page. Does not touch the draft — the
  // fast fix for something that shipped by mistake.
  revertPublish: (payload) => api.post('/homepage/revert-publish', payload),

  // Section-level routes. The builder edits locally and saves in bulk, so these exist for
  // integrations and scripts rather than for the screen itself.
  createSection: (payload) => api.post('/homepage/sections', payload),
  updateSection: (id, payload) => api.put(`/homepage/sections/${id}`, payload),
  removeSection: (id, params) => api.del(`/homepage/sections/${id}`, params),
  duplicateSection: (id, payload) => api.post(`/homepage/sections/${id}/duplicate`, payload),
  toggleSection: (id, payload) => api.post(`/homepage/sections/${id}/toggle`, payload),
  reorder: (payload) => api.post('/homepage/sections/reorder', payload),

  previewSource: (params) => api.get('/homepage/sources/preview', params),

  // History. Restore lands in the DRAFT, never straight on the live page.
  revisions: (params) => api.get('/homepage/revisions', params),
  revision: (id) => api.get(`/homepage/revisions/${id}`),
  revisionDiff: (id, params) => api.get(`/homepage/revisions/${id}/diff`, params),
  restore: (id, payload) => api.post(`/homepage/revisions/${id}/restore`, payload),

  // Landing pages: the same builder, bound to a WordPress page instead of the front page.
  documents: () => api.get('/homepage/documents'),
  createDocument: (payload) => api.post('/homepage/documents', payload),
  bindDocument: (key, payload) => api.post(`/homepage/documents/${key}/bind`, payload),
  removeDocument: (key) => api.del(`/homepage/documents/${key}`),
  pages: () => api.get('/homepage/pages'),

  // The template library. Applying one goes through the same factory and the same permission
  // checks as building a section by hand — it is a convenience, not a second way in.
  templates: () => api.get('/homepage/templates'),
  saveTemplate: (payload) => api.post('/homepage/templates', payload),
  applyTemplate: (id, payload) => api.post(`/homepage/templates/${id}/apply`, payload),
  removeTemplate: (id) => api.del(`/homepage/templates/${id}`),

  // Shared sections are stored once and referenced. Sharing replaces the section with a pointer;
  // detaching turns the pointer back into a private copy.
  shareSection: (id, payload) => api.post(`/homepage/sections/${id}/share`, payload),
  detachSection: (id, payload) => api.post(`/homepage/sections/${id}/detach`, payload),

  // Packages. `confirm` is what separates the dry run from the real thing.
  export: (params) => api.get('/homepage/export', params),
  import: (payload) => api.post('/homepage/import', payload),

  // The rendered HTML of named sections, for the preview patcher. Behind homepage.preview, and
  // the only route here that returns markup rather than data.
  previewSections: (params) => api.get('/homepage/preview-sections', params),

  // A/B testing. Reading the numbers needs homepage.view; changing anything needs
  // homepage.experiment; promoting a winner needs homepage.publish on top of it.
  experiment: (params) => api.get('/homepage/experiment', params),
  saveExperiment: (payload) => api.put('/homepage/experiment', payload),
  startExperiment: (payload) => api.post('/homepage/experiment/start', payload),
  stopExperiment: (payload) => api.post('/homepage/experiment/stop', payload),
  removeExperiment: (params) => api.del('/homepage/experiment', params),
  promoteExperiment: (payload) => api.post('/homepage/experiment/promote', payload),
  // Returns the CSV as text rather than a download URL: a second way into the same data would
  // need a second way of proving who you are.
  exportExperiment: (params) => api.get('/homepage/experiment/export', params),
}

// The permission catalog, grouped by module. `grantable` is the subset the CALLER may hand out,
// so the role editor can disable the rest without a second request.
export const permissionsApi = {
  list: () => api.get('/permissions'),
}


/**
 * Store engine — a namespace this plugin does not own.
 *
 * `yazan-stores/v1` belongs to yazan-store-engine. Every admin call carries the target store as
 * `?store=`; the server checks the permission IN that store before doing anything, so switching
 * stores in the UI can never grant rights the user does not hold there.
 */
const stores = namespace('yazan-stores/v1')

const withStore = (params, storeId) => (storeId ? { ...(params || {}), store: storeId } : params)

export const storesApi = {
  list: (params) => stores.get('/stores', params),
  get: (id) => stores.get(`/stores/${id}`, { store: id }),
  create: (payload) => stores.post('/stores', payload),
  update: (id, payload) => stores.put(`/stores/${id}?store=${id}`, payload),

  activate: (id) => stores.post(`/stores/${id}/activate?store=${id}`, {}),
  suspend: (id) => stores.post(`/stores/${id}/suspend?store=${id}`, {}),
  archive: (id) => stores.post(`/stores/${id}/archive?store=${id}`, {}),
  clone: (id, payload) => stores.post(`/stores/${id}/clone?store=${id}`, payload),

  settings: (id) => stores.get(`/stores/${id}/settings`, { store: id }),
  saveSettings: (id, settings) => stores.put(`/stores/${id}/settings?store=${id}`, { settings }),

  domains: (id) => stores.get(`/stores/${id}/domains`, { store: id }),
  addDomain: (id, payload) => stores.post(`/stores/${id}/domains?store=${id}`, payload),
  removeDomain: (id, domainId) => stores.del(`/stores/${id}/domains/${domainId}`, { store: id }),

  modules: (id) => stores.get(`/stores/${id}/modules`, { store: id }),
  saveModules: (id, modules) => stores.put(`/stores/${id}/modules?store=${id}`, { modules }),

  users: (id) => stores.get(`/stores/${id}/users`, { store: id }),
  setUserRoles: (id, userId, roleIds) =>
    stores.put(`/stores/${id}/users?store=${id}`, { user_id: userId, role_ids: roleIds }),

  context: (storeId) => stores.get('/context', withStore(undefined, storeId)),
  switchable: () => stores.get('/switchable'),
}
