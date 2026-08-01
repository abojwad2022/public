/**
 * The curated icon set.
 *
 * Every icon the dashboard is allowed to use is re-exported from here, so the
 * set stays a small, reviewable list instead of 200 ad-hoc imports. Import from
 * '../components/ui' — never from 'lucide-react' directly, and never from a deep
 * 'lucide-react/dist/...' path (that defeats tree-shaking).
 *
 * Sizing: lucide defaults to 24px with a 2px stroke. The dashboard's default is
 * 16px at 1.75 stroke, set once in <Icon>, so icons optically match 13-14px text
 * rather than towering over it.
 */
export {
  // Navigation / structure
  LayoutDashboard,
  Package,
  FolderTree,
  Tags,
  Boxes,
  ShoppingCart,
  Users,
  Ticket,
  ChartNoAxesColumn,
  Sparkles,
  Lightbulb,
  SlidersHorizontal,
  Settings,
  Gift,
  ScrollText,
  // Actions
  Plus,
  Pencil,
  Trash2,
  Copy,
  Save,
  Search,
  Filter,
  Download,
  Upload,
  Printer,
  Eye,
  ExternalLink,
  EyeOff,
  RefreshCw,
  RotateCcw,
  Check,
  Minus,
  X,
  Menu,
  LogOut,
  // Direction
  ChevronRight,
  ChevronLeft,
  ChevronDown,
  ChevronUp,
  ChevronsUpDown,
  ArrowRight,
  ArrowLeft,
  ArrowUp,
  ArrowDown,
  PanelLeftClose,
  PanelLeftOpen,
  // Status / feedback
  CircleCheck,
  CircleAlert,
  TriangleAlert,
  Info,
  CircleHelp,
  LoaderCircle,
  // Content
  Image,
  ImagePlus,
  FileText,
  Mail,
  Sun,
  Moon,
  Store,
  BadgeCheck,
  TrendingUp,
  TrendingDown,
  Inbox,
  // Yazan Rewards — one distinct, semantic glyph per screen
  Gavel, // Rules
  Coins, // Points
  Award, // Rewards
  Wrench, // Service Queue
  Megaphone, // Campaigns
  UserPlus, // Referrals
  Share2, // Social
  ShieldAlert, // Fraud
  ChartPie, // Analytics
  Bell, // Notifications
  DatabaseZap, // Data Integrity
  // Access control — users, roles, permissions
  UserRound, // The neutral stand-in when an account has no uploaded photo
  UserCog, // Users (staff)
  ShieldCheck, // Roles
  KeyRound, // Permissions
  Lock, // Locked / system role / no access
  Ban, // Suspended
  UserCheck, // Activate
  Crown, // Super admin
} from 'lucide-react'
