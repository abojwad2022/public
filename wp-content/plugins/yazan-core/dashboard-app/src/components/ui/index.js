/**
 * The Yazan design system — the single public entry point for UI components.
 *
 * Import from here (or from '../components/ui.jsx', which re-exports this file
 * during the migration). Never import a module inside ui/ directly, and never
 * import from 'lucide-react' directly — icons come from ./icons.js so the icon
 * set stays a curated, reviewable list.
 */

export { Icon, DirIcon } from './primitives.jsx'
export {
  Button,
  IconButton,
  Field,
  Input,
  Textarea,
  Select,
  Checkbox,
  Radio,
  Switch,
  SearchInput,
  PasswordInput,
} from './primitives.jsx'

export { Card, Modal, Drawer, Alert, ConfirmDialog, useConfirm } from './surfaces.jsx'

export {
  Avatar,
  Badge,
  Table,
  THead,
  TBody,
  TR,
  TH,
  TD,
  Pagination,
  StatTile,
  Spinner,
  Skeleton,
  SkeletonText,
  SkeletonTable,
  EmptyState,
  ProgressBar,
} from './data.jsx'

export { Tabs, SegmentedControl, Breadcrumbs, Dropdown, Tooltip } from './nav.jsx'

export { useFocusTrap } from './useFocusTrap.js'

export * as Icons from './icons.js'
