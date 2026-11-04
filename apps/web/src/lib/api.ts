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
