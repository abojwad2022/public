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
  Undo2, // Homepage Manager — undo the last publish
  Check,
  Minus,
  Star, // Featured toggle on the products table
  Columns3, // Screen options — which columns are shown
  X,
  Menu,
  LogOut,
  // Direction
  ChevronRight,
  ChevronLeft,
  ChevronsRight, // Pagination — jump to the last page
  ChevronsLeft, // Pagination — jump to the first page
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
  LayoutGrid, // Homepage Manager — the swatch/banner component
  BookOpen, // Homepage Manager — the brand-story component
  Images, // Homepage Manager — the story-panels component
  Quote, // Homepage Manager — testimonials
  LayoutTemplate, // Homepage Manager — the module's own nav entry
  ImagePlus,
  FileText,
  Mail,
  Sun,
  Moon,
  Store,
  // Added for Administration → Stores: Globe labels a store address, Rocket the publish action.
  Globe,
  Rocket,
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
