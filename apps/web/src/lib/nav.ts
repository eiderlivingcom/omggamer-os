// Main navigation. `phase` is when a section becomes functional; pages show that honestly instead of sample data.

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
      { label: "All products", phase: 3 },
      { label: "Drafts", phase: 3 },
      { label: "Product variants", phase: 3 },
      { label: "Supplier offers", phase: 2 },
      { label: "Archived products", phase: 3 },
    ],
  },
  {
    href: "/suppliers",
    label: "Suppliers",
    icon: "truck",
    perm: "suppliers:read",
    sections: [
      { label: "Supplier directory", phase: 2 },
      { label: "API connections", phase: 2 },
      { label: "Import manager", phase: 2 },
      { label: "Import history", phase: 2 },
      { label: "Supplier product mappings", phase: 2 },
    ],
  },
  {
    href: "/ai-studio",
    label: "AI Studio",
    icon: "spark",
    perm: "content:write",
    sections: [
      { label: "Content generation", phase: 4 },
      { label: "SEO generation", phase: 4 },
      { label: "AI job queue", phase: 4 },
      { label: "Prompt management", phase: 4 },
      { label: "AI usage and cost", phase: 4 },
    ],
  },
  {
    href: "/categories",
    label: "Categories",
    icon: "tag",
    perm: "catalogue:read",
    sections: [
      { label: "Product categories", phase: 3 },
      { label: "Subcategories", phase: 3 },
      { label: "Attributes", phase: 3 },
      { label: "Tags", phase: 3 },
      { label: "Supplier category mappings", phase: 3 },
    ],
  },
  {
    href: "/pricing",
    label: "Pricing",
    icon: "coin",
    perm: "pricing:read",
    sections: [
      { label: "Pricing rules", phase: 3 },
      { label: "Margin settings", phase: 3 },
      { label: "Currency settings", phase: 3 },
      { label: "Price history", phase: 3 },
    ],
  },
  {
    href: "/media",
    label: "Media",
    icon: "image",
    perm: "catalogue:read",
    sections: [
      { label: "Product images", phase: 3 },
      { label: "Media library", phase: 3 },
      { label: "Image processing", phase: 3 },
      { label: "Image validation", phase: 3 },
    ],
  },
  {
    href: "/publishing",
    label: "Publishing",
    icon: "send",
    perm: "catalogue:read",
    sections: [
      { label: "Approval queue", phase: 5 },
      { label: "Publishing queue", phase: 5 },
      { label: "Published products", phase: 5 },
      { label: "Synchronisation failures", phase: 5 },
    ],
  },
  {
    href: "/orders",
    label: "Orders",
    icon: "receipt",
    perm: "orders:read",
    sections: [
      { label: "Customer orders", phase: 6 },
      { label: "Supplier fulfilment", phase: 6 },
      { label: "Failed orders", phase: 6 },
      { label: "Refund management", phase: 6 },
    ],
  },
  {
    href: "/analytics",
    label: "Analytics",
    icon: "chart",
    perm: "analytics:read",
    sections: [
      { label: "Catalogue analytics", phase: 7 },
      { label: "Supplier analytics", phase: 7 },
      { label: "AI cost analytics", phase: 7 },
      { label: "Sales analytics", phase: 7 },
    ],
  },
  {
    href: "/automation",
    label: "Automation",
    icon: "clock",
    perm: "automation:manage",
    sections: [
      { label: "Scheduled imports", phase: 7 },
      { label: "Price synchronisation", phase: 7 },
      { label: "Stock synchronisation", phase: 7 },
      { label: "Scheduled AI processing", phase: 7 },
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
      { label: "API credentials", phase: 2 },
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
