import { Tabs } from '../ui/index.js'

/**
 * The status views above the products table — WooCommerce's
 * "All | Published | Drafts | Pending | Private | Trash" strip.
 *
 * Two rules copied deliberately from wp-admin:
 *   • the counts are NOT filter-aware. They answer "how many products are in this status",
 *     not "how many match the current search", so they stay a stable map of the catalogue.
 *   • a status with no rows is hidden rather than shown as a zero — except All and the
 *     currently-selected tab, which must never vanish from under the user.
 */
const VIEWS = [
  { key: '', label: 'All', countKey: 'all' },
  { key: 'publish', label: 'Published', countKey: 'publish' },
  { key: 'draft', label: 'Drafts', countKey: 'draft' },
  { key: 'pending', label: 'Pending', countKey: 'pending' },
  { key: 'private', label: 'Private', countKey: 'private' },
  { key: 'trash', label: 'Trash', countKey: 'trash' },
]

export default function ProductStatusTabs({ counts, value, onChange }) {
  const items = VIEWS.filter(
    (view) => view.key === '' || view.key === value || (counts?.[view.countKey] ?? 0) > 0,
  ).map((view) => ({
    key: view.key,
    label: view.label,
    count: counts ? (counts[view.countKey] ?? 0) : undefined,
  }))

  return <Tabs items={items} value={value} onChange={onChange} className="mb-4" />
}
