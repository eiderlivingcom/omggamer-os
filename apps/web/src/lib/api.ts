// Typed fetch wrapper for the OMGGamer OS API. Same origin: Next forwards /api/* to FastAPI.

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public details?: unknown,
  ) {
    super(message);
  }
}

let refreshing: Promise<boolean> | null = null;

async function refreshSession(): Promise<boolean> {
  if (!refreshing) {
    refreshing = fetch("/api/v1/auth/refresh", { method: "POST", credentials: "same-origin" })
      .then((r) => r.ok)
      .catch(() => false)
      .finally(() => {
        setTimeout(() => (refreshing = null), 0);
      });
  }
  return refreshing;
}

export async function api<T>(path: string, init: RequestInit & { json?: unknown } = {}, retry = true): Promise<T> {
  const { json, headers, ...rest } = init;
  const res = await fetch(`/api/v1${path}`, {
    credentials: "same-origin",
    ...rest,
    headers: { ...(json !== undefined ? { "content-type": "application/json" } : {}), ...headers },
    body: json !== undefined ? JSON.stringify(json) : rest.body,
  });
  if (res.status === 401 && retry && !path.startsWith("/auth/login") && !path.startsWith("/auth/refresh")) {
    if (await refreshSession()) return api<T>(path, init, false);
    if (typeof window !== "undefined" && !window.location.pathname.startsWith("/login")) {
      window.location.href = `/login?next=${encodeURIComponent(window.location.pathname)}`;
    }
  }
  if (res.status === 204) return undefined as T;
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    const err = data?.error ?? {};
    if (err.code === "password_change_required" && typeof window !== "undefined" && window.location.pathname !== "/account") {
      window.location.href = "/account?force=1";
    }
    throw new ApiError(res.status, err.code ?? "http_error", err.message ?? `Request failed (${res.status})`, err.details);
  }
  return data as T;
}

export type Me = {
  id: string;
  email: string;
  name: string;
  role: string;
  role_name: string;
  permissions: string[];
  is_active: boolean;
  must_change_password: boolean;
  totp_enabled: boolean;
  last_login_at: string | null;
  created_at: string;
};

export type AuditEntry = {
  id: string;
  created_at: string;
  actor_email: string | null;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  summary: string;
  data: Record<string, unknown>;
  ip: string | null;
};

export type Summary = {
  catalogue: { products: number; by_status: Record<string, number>; variants: number };
  suppliers: { suppliers: number; connected: number; supplier_products: number; active_offers: number };
  imports: { running: number; queued: number; failed: number; last_finished_at: string | null };
  ai: { queued: number; running: number; failed: number; succeeded: number; cost_usd_30d: string };
  publishing: { published_listings: number; pending_listings: number; sync_failures: number; publish_queue: number };
  dead_letters_open: number;
  users_active: number;
};

export type Health = { ok: boolean; checks: { name: string; ok: boolean; detail: string }[]; version: string; migration: string | null };

export type Environment = "live" | "sandbox";
export type Connection = { environment: Environment; configured: boolean; status: string; last_checked_at: string | null; last_error: string | null };
export type SupplierConnections = { supplier: string; name: string; active_environment: Environment; connections: Connection[] };

export type ImportJob = {
  id: string;
  job_type: "full" | "incremental" | "single" | "retry_failed";
  status: "queued" | "running" | "paused" | "completed" | "failed" | "cancelled";
  params: Record<string, unknown>;
  total_records: number | null;
  processed_records: number;
  created_records: number;
  updated_records: number;
  skipped_records: number;
  failed_records: number;
  saved_records: number;
  api_requests: number;
  current_page: number | null;
  checkpoint: Record<string, unknown>;
  last_checkpoint_at: string | null;
  last_response_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  duration_seconds: number | null;
  last_error: string | null;
  created_at: string;
};

export type ImportOverview = {
  supplier: string;
  name: string;
  active_environment: Environment;
  connection_status: string;
  active_job: ImportJob | null;
  recent_jobs: ImportJob[];
  totals: { supplier_products: number; variants: number; offers: number; offers_in_stock: number; unresolved_failures: number };
};

export type ImportLog = { id: number; level: "info" | "warning" | "error"; message: string; data: Record<string, unknown>; created_at: string };
export type ImportFailure = { id: string; external_product_id: string; error: string; attempts: number; created_at: string };

export type ProductListItem = {
  id: string;
  canonical_title: string;
  slug: string;
  product_type: string;
  product_status: string;
  developer: string | null;
  publisher: string | null;
  cover_url: string | null;
  platforms: string[];
  regions: string[];
  suppliers: string[];
  variant_count: number;
  offer_count: number;
  lowest_price: string | null;
  lowest_price_currency: string | null;
  content_status: string | null;
  external_ids: string[];
  categories: string[];
  editions: string[];
  publishing_status: string;
  updated_at: string;
};
export type ProductPage = { items: ProductListItem[]; total: number; page: number; page_size: number };

export type Variant = {
  id: string;
  sku: string;
  platform: string | null;
  edition: string | null;
  region: string | null;
  activation_type: string | null;
  language: string | null;
  offer_count: number;
  lowest_price: string | null;
  lowest_price_currency: string | null;
};
export type Offer = {
  id: string;
  supplier_product_id: string;
  variant_id: string | null;
  external_offer_id: string;
  seller_id: string | null;
  region: string | null;
  currency: string;
  supplier_price: string;
  available_quantity: number;
  availability_status: string;
  last_synced_at: string | null;
};
export type SupplierProductRef = {
  id: string;
  supplier_code: string;
  external_product_id: string;
  external_product_set_id: string | null;
  original_title: string;
  variant_id: string | null;
  import_status: string;
  last_fetched_at: string | null;
};
export type Content = {
  id: string;
  locale: string;
  version: number;
  origin: string;
  title: string | null;
  short_description: string | null;
  long_description: string | null;
  meta_title: string | null;
  meta_description: string | null;
  image_alt_text: Record<string, string>;
  tags: string[];
  content_status: string;
  model: string | null;
  prompt_version: string | null;
  generated_at: string | null;
  is_current?: boolean;
  created_at?: string;
  [k: string]: unknown;
};
export type ProductDetail = {
  id: string;
  master_product_id: string | null;
  canonical_title: string;
  slug: string;
  match_key: string;
  product_type: string;
  product_status: string;
  brand: string | null;
  developer: string | null;
  publisher: string | null;
  release_date: string | null;
  created_at: string;
  updated_at: string;
  variants: Variant[];
  offers: Offer[];
  supplier_products: SupplierProductRef[];
  categories: { id: string; name: string; slug: string; source: string; confidence: string | null }[];
  media: { id: string; source_url: string; storage_url: string | null; media_type: string; position: number; processing_status: string }[];
  content: Content | null;
  merged_from: string[];
};
export type SupplierProductDetail = SupplierProductRef & { original_description: string | null; raw_payload: Record<string, unknown> };
export type CategoryNode = { id: string; name: string; slug: string; parent_id: string | null; position: number; product_count: number; children: CategoryNode[] };
export type BulkResult = { action: string; requested: number; changed: number; skipped: number; details: Record<string, number> };
export type PublishingHistory = {
  mappings: { storefront: string; external_product_id: string | null; sync_status: string; last_synced_at: string | null; last_error: string | null }[];
  events: { kind: "queue" | "sync"; storefront: string | null; action: string; status: string; detail: string | null; created_at: string }[];
};
export type Activity = { id: string; created_at: string; actor_email: string | null; action: string; summary: string; data: Record<string, unknown> };
