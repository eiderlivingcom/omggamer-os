// Main navigation. `phase` is when a section becomes functional; pages show that honestly instead of sample data.

/** The highest phase that is deployed. Sections up to it are live. */
export const CURRENT_PHASE = 6;

export type NavSection = { label: string; phase: number };
export type NavItem = { href: string; label: string; icon: string; perm: string; sections: NavSection[] };

export const NAV: NavItem[] = [
  {
    href: "/",
    label: "Dashboard",
    icon: "grid",
    perm: "dashboard:read",
    sections: [
      { label: "Overview", phase: 1 },
      { label: "System health", phase: 1 },
      { label: "Recent activity", phase: 1 },
    ],
  },
  {
    href: "/catalogue",
    label: "Catalogue",
    icon: "box",
    perm: "catalogue:read",
    sections: [
      { label: "All products", phase: 4 },
      { label: "Drafts", phase: 4 },
      { label: "Product variants", phase: 4 },
      { label: "Supplier offers", phase: 3 },
      { label: "Archived products", phase: 4 },
    ],
  },
  {
    href: "/suppliers",
    label: "Suppliers",
    icon: "truck",
    perm: "suppliers:read",
    sections: [
      { label: "Supplier directory", phase: 3 },
      { label: "API connections", phase: 3 },
      { label: "Import manager", phase: 3 },
      { label: "Import history", phase: 3 },
      { label: "Supplier product mappings", phase: 3 },
    ],
  },
  {
    href: "/ai-studio",
    label: "AI Studio",
    icon: "spark",
    perm: "content:write",
    sections: [
      { label: "Content generation", phase: 5 },
      { label: "SEO generation", phase: 5 },
      { label: "AI job queue", phase: 5 },
      { label: "Prompt management", phase: 5 },
      { label: "AI usage and cost", phase: 5 },
    ],
  },
  {
    href: "/categories",
    label: "Categories",
    icon: "tag",
    perm: "catalogue:read",
    sections: [
      { label: "Product categories", phase: 6 },
      { label: "Subcategories", phase: 6 },
      { label: "Attributes", phase: 6 },
      { label: "Tags", phase: 6 },
      { label: "Supplier category mappings", phase: 6 },
    ],
  },
  {
    href: "/pricing",
    label: "Pricing",
    icon: "coin",
    perm: "pricing:read",
    sections: [
      { label: "Pricing rules", phase: 6 },
      { label: "Margin settings", phase: 6 },
      { label: "Currency settings", phase: 6 },
      { label: "Price history", phase: 6 },
    ],
  },
  {
    href: "/media",
    label: "Media",
    icon: "image",
    perm: "catalogue:read",
    sections: [
      { label: "Product images", phase: 4 },
      { label: "Media library", phase: 4 },
      { label: "Image processing", phase: 4 },
      { label: "Image validation", phase: 4 },
    ],
  },
  {
    href: "/publishing",
    label: "Publishing",
    icon: "send",
    perm: "catalogue:read",
    sections: [
      { label: "Approval queue", phase: 7 },
      { label: "Publishing queue", phase: 7 },
      { label: "Published products", phase: 7 },
      { label: "Synchronisation failures", phase: 7 },
    ],
  },
  {
    href: "/orders",
    label: "Orders",
    icon: "receipt",
    perm: "orders:read",
    sections: [
      { label: "Customer orders", phase: 9 },
      { label: "Supplier fulfilment", phase: 9 },
      { label: "Failed orders", phase: 9 },
      { label: "Refund management", phase: 9 },
    ],
  },
  {
    href: "/analytics",
    label: "Analytics",
    icon: "chart",
    perm: "analytics:read",
    sections: [
      { label: "Catalogue analytics", phase: 10 },
      { label: "Supplier analytics", phase: 10 },
      { label: "AI cost analytics", phase: 10 },
      { label: "Sales analytics", phase: 10 },
    ],
  },
  {
    href: "/automation",
    label: "Automation",
    icon: "clock",
    perm: "automation:manage",
    sections: [
      { label: "Scheduled imports", phase: 10 },
      { label: "Price synchronisation", phase: 8 },
      { label: "Stock synchronisation", phase: 8 },
      { label: "Scheduled AI processing", phase: 10 },
    ],
  },
  {
    href: "/settings/users",
    label: "Settings",
    icon: "cog",
    perm: "dashboard:read",
    sections: [
      { label: "User management", phase: 1 },
      { label: "Roles and permissions", phase: 1 },
      { label: "API credentials", phase: 3 },
      { label: "Audit logs", phase: 1 },
    ],
  },
];

export const SETTINGS_TABS = [
  { href: "/settings/users", label: "Users", perm: "users:manage" },
  { href: "/settings/roles", label: "Roles and permissions", perm: "dashboard:read" },
  { href: "/settings/credentials", label: "API credentials", perm: "credentials:manage" },
  { href: "/settings/audit", label: "Audit log", perm: "audit:read" },
];
