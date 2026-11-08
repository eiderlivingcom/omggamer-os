"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { AiStatus, ProductStatus, PublishStatus, money } from "@/components/status";
import { Button, Empty, Notice, PageHeader, Pill, cx, relTime } from "@/components/ui";
import { api, ApiError, type BulkResult, type CategoryNode, type ProductPage } from "@/lib/api";
import { useSession } from "@/lib/session";

const FILTERS: { key: string; label: string; options: [string, string][] }[] = [
  { key: "supplier", label: "Supplier", options: [["kinguin", "Kinguin"]] },
  {
    key: "product_type",
    label: "Type",
    options: [
      ["game", "Game"], ["dlc", "DLC"], ["game_account", "Account"], ["gift_card", "Gift card"],
      ["software", "Software"], ["subscription", "Subscription"], ["hardware", "Hardware"], ["accessory", "Accessory"],
    ],
  },
  { key: "availability", label: "Availability", options: [["available", "In stock"], ["unavailable", "Out of stock"]] },
  {
    key: "ai_status",
    label: "AI content",
    options: [["none", "None"], ["draft", "Draft"], ["pending_review", "Needs review"], ["approved", "Approved"], ["rejected", "Rejected"]],
  },
  {
    key: "status",
    label: "Approval",
    options: [["draft", "Draft"], ["review", "In review"], ["approved", "Approved"], ["published", "Published"], ["unpublished", "Unpublished"], ["archived", "Archived"]],
  },
  {
    key: "publishing",
    label: "Publishing",
    options: [["not_published", "Not published"], ["pending", "Pending"], ["published", "Live"], ["failed", "Failed"], ["unpublished", "Unpublished"]],
  },
];
const TEXT_FILTERS = [
  { key: "platform", label: "Platform", placeholder: "Steam, PS5…" },
  { key: "region", label: "Region", placeholder: "Global, Europe…" },
  { key: "edition", label: "Edition", placeholder: "Gold Edition…" },
];
const BULK: { action: string; label: string; perm: string; confirm?: string }[] = [
  { action: "generate_ai", label: "Generate AI content", perm: "content:write" },
  { action: "regenerate_ai", label: "Regenerate", perm: "content:write", confirm: "Regenerate AI content for the selected products? Existing AI drafts are replaced by a new version." },
  { action: "approve", label: "Approve", perm: "publishing:approve" },
  { action: "reject", label: "Reject", perm: "publishing:approve" },
  { action: "publish", label: "Publish", perm: "publishing:manage", confirm: "Queue the selected approved products for publishing to WooCommerce?" },
  { action: "archive", label: "Archive", perm: "catalogue:write", confirm: "Archive the selected products?" },
  { action: "retry_failed", label: "Retry failed jobs", perm: "catalogue:write" },
];
const REASONS: Record<string, string> = {
  has_content: "already have AI content",
  already_queued: "already queued",
  not_approved: "are not approved",
  already_approved: "already approved",
  already_archived: "already archived",
  nothing_to_reject: "had nothing to reject",
  nothing_failed: "had no failed jobs",
  archived: "are archived",
  merged: "were merged",
  not_found: "no longer exist",
};

function flatten(nodes: CategoryNode[], depth = 0): { id: string; name: string }[] {
  return nodes.flatMap((n) => [{ id: n.id, name: `${"— ".repeat(depth)}${n.name}` }, ...flatten(n.children, depth + 1)]);
}

function Catalogue() {
  const { can } = useSession();
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const [data, setData] = useState<ProductPage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [assignTo, setAssignTo] = useState("");
  const [busy, setBusy] = useState(false);
  const [q, setQ] = useState(params.get("q") ?? "");
  const [showFilters, setShowFilters] = useState(false);

  const query = params.toString();
  const page = Number(params.get("page") ?? 1);
  const pageSize = Number(params.get("page_size") ?? 50);

  const setParam = useCallback(
    (changes: Record<string, string | null>) => {
      const next = new URLSearchParams(params.toString());
      for (const [k, v] of Object.entries(changes)) {
        if (v) next.set(k, v);
        else next.delete(k);
      }
      if (!("page" in changes)) next.delete("page");
      router.replace(`${pathname}?${next.toString()}`);
    },
    [params, pathname, router],
  );

  const load = useCallback(async () => {
    try {
      setData(await api<ProductPage>(`/catalogue/products?${query}`));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load products.");
    }
  }, [query]);

  useEffect(() => {
    setData(null);
    setSelected(new Set());
    void load();
  }, [load]);

  useEffect(() => {
    api<CategoryNode[]>("/catalogue/categories").then((t) => setCategories(flatten(t))).catch(() => undefined);
  }, []);

  useEffect(() => {
    const t = setTimeout(() => {
      if ((params.get("q") ?? "") !== q) setParam({ q: q.trim() || null });
    }, 350);
    return () => clearTimeout(t);
  }, [q, params, setParam]);

  const ids = useMemo(() => data?.items.map((i) => i.id) ?? [], [data]);
  const allSelected = ids.length > 0 && ids.every((i) => selected.has(i));
  const activeFilters = [...FILTERS, ...TEXT_FILTERS, { key: "category_id" }].filter((f) => params.get(f.key)).length;
  const pages = data ? Math.max(1, Math.ceil(data.total / pageSize)) : 1;

  async function runBulk(action: string, confirmText?: string) {
    if (confirmText && !window.confirm(confirmText)) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const body: Record<string, unknown> = { action, product_ids: [...selected] };
      if (action === "assign_categories") body.category_ids = [assignTo];
      const r = await api<BulkResult>("/catalogue/products/bulk", { method: "POST", json: body });
      const skipped = Object.entries(r.details)
        .map(([k, v]) => `${v} ${REASONS[k] ?? k.replace(/_/g, " ")}`)
        .join(", ");
      setNotice(`${r.changed} of ${r.requested} products updated.${skipped ? ` Skipped: ${skipped}.` : ""}`);
      setSelected(new Set());
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "The bulk action failed.");
    } finally {
      setBusy(false);
    }
  }

  const toggle = (id: string) =>
    setSelected((s) => {
      const n = new Set(s);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });

  return (
    <>
      <PageHeader
        title="Catalogue"
        description="Master products from every supplier. Search and filters run on the server; nothing is loaded in bulk."
        actions={data && <span className="text-sm text-ink-500">{data.total.toLocaleString()} products</span>}
      />

      <div className="mb-4 flex flex-col gap-3 sm:flex-row">
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search title, Kinguin ID, product ID, SKU, developer or publisher"
          aria-label="Search products"
          className="h-10 min-w-0 flex-1 rounded-lg border border-ink-200 bg-white px-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
        />
        <Button variant="ghost" onClick={() => setShowFilters((v) => !v)} aria-expanded={showFilters}>
          Filters{activeFilters ? ` (${activeFilters})` : ""}
        </Button>
        {activeFilters > 0 && (
          <Button variant="ghost" onClick={() => router.replace(pathname + (q ? `?q=${encodeURIComponent(q)}` : ""))}>Clear</Button>
        )}
      </div>

      {showFilters && (
        <div className="mb-4 grid grid-cols-2 gap-3 rounded-xl border border-ink-100 bg-white p-4 sm:grid-cols-3 lg:grid-cols-5">
          {FILTERS.map((f) => (
            <label key={f.key} className="block text-xs font-semibold text-ink-700">
              {f.label}
              <select
                className="mt-1 h-9 w-full rounded-md border border-ink-200 bg-white px-2 text-sm font-normal"
                value={params.get(f.key) ?? ""}
                onChange={(e) => setParam({ [f.key]: e.target.value || null })}
              >
                <option value="">Any</option>
                {f.options.map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </label>
          ))}
          <label className="block text-xs font-semibold text-ink-700">
            Category
            <select
              className="mt-1 h-9 w-full rounded-md border border-ink-200 bg-white px-2 text-sm font-normal"
              value={params.get("category_id") ?? ""}
              onChange={(e) => setParam({ category_id: e.target.value || null })}
            >
              <option value="">Any</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </label>
          {TEXT_FILTERS.map((f) => (
            <label key={f.key} className="block text-xs font-semibold text-ink-700">
              {f.label}
              <input
                defaultValue={params.get(f.key) ?? ""}
                placeholder={f.placeholder}
                onBlur={(e) => setParam({ [f.key]: e.target.value.trim() || null })}
                onKeyDown={(e) => e.key === "Enter" && setParam({ [f.key]: (e.target as HTMLInputElement).value.trim() || null })}
                className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal"
              />
            </label>
          ))}
        </div>
      )}

      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {notice && <div className="mb-4"><Notice tone="ok">{notice}</Notice></div>}

      {selected.size > 0 && (
        <div className="sticky top-2 z-10 mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 p-3 shadow-sm">
          <span className="mr-2 text-sm font-semibold text-brand-800">{selected.size} selected</span>
          {BULK.filter((b) => can(b.perm)).map((b) => (
            <Button key={b.action} variant="ghost" className="h-8 px-2.5 text-[13px]" disabled={busy} onClick={() => runBulk(b.action, b.confirm)}>
              {b.label}
            </Button>
          ))}
          {can("catalogue:write") && categories.length > 0 && (
            <span className="flex items-center gap-1">
              <select
                aria-label="Category to assign"
                className="h-8 rounded-md border border-ink-200 bg-white px-2 text-[13px]"
                value={assignTo}
                onChange={(e) => setAssignTo(e.target.value)}
              >
                <option value="">Assign category…</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
              <Button variant="ghost" className="h-8 px-2.5 text-[13px]" disabled={busy || !assignTo} onClick={() => runBulk("assign_categories")}>
                Assign
              </Button>
            </span>
          )}
          <button className="ml-auto text-xs font-semibold text-brand-700 underline" onClick={() => setSelected(new Set())}>Clear selection</button>
        </div>
      )}

      <div className="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
        {!data ? (
          <div className="h-64 animate-pulse bg-ink-50" />
        ) : data.items.length === 0 ? (
          <div className="p-6">
            <Empty title={activeFilters || q ? "No products match" : "No products yet"}>
              {activeFilters || q ? "Try a different search or clear the filters." : "Products appear here after a Kinguin import (Suppliers)."}
            </Empty>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1100px] text-sm">
              <thead>
                <tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                  <th className="w-10 px-4 py-2.5">
                    <input
                      type="checkbox"
                      aria-label="Select all on this page"
                      checked={allSelected}
                      onChange={() => setSelected(allSelected ? new Set() : new Set(ids))}
                    />
                  </th>
                  <th className="px-2 py-2.5">Product</th>
                  <th className="px-3 py-2.5">Category</th>
                  <th className="px-3 py-2.5">Platform · Region</th>
                  <th className="px-3 py-2.5">Supplier</th>
                  <th className="px-3 py-2.5 text-right">Offers</th>
                  <th className="px-3 py-2.5 text-right">Lowest price</th>
                  <th className="px-3 py-2.5">AI</th>
                  <th className="px-3 py-2.5">Approval</th>
                  <th className="px-3 py-2.5">Publishing</th>
                  <th className="px-4 py-2.5 text-right">Updated</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {data.items.map((p) => (
                  <tr key={p.id} className={cx("hover:bg-ink-50/60", selected.has(p.id) && "bg-brand-50/40")}>
                    <td className="px-4 py-2.5">
                      <input type="checkbox" aria-label={`Select ${p.canonical_title}`} checked={selected.has(p.id)} onChange={() => toggle(p.id)} />
                    </td>
                    <td className="px-2 py-2.5">
                      <Link href={`/catalogue/${p.id}`} className="flex min-w-[240px] items-center gap-3">
                        {p.cover_url ? (
                          // eslint-disable-next-line @next/next/no-img-element
                          <img src={p.cover_url} alt="" loading="lazy" className="h-12 w-9 shrink-0 rounded object-cover ring-1 ring-ink-100" />
                        ) : (
                          <span className="h-12 w-9 shrink-0 rounded bg-ink-100" />
                        )}
                        <span className="min-w-0">
                          <span className="block truncate font-semibold text-ink-900 hover:text-brand-700">{p.canonical_title}</span>
                          <span className="block truncate font-mono text-[11px] text-ink-500">
                            {p.external_ids.length ? `Kinguin ${p.external_ids.slice(0, 3).join(", ")}${p.external_ids.length > 3 ? ` +${p.external_ids.length - 3}` : ""}` : "—"}
                            {p.editions.length > 0 && ` · ${p.editions.join(", ")}`}
                          </span>
                        </span>
                      </Link>
                    </td>
                    <td className="px-3 py-2.5 text-ink-700">{p.categories.slice(0, 2).join(", ") || <span className="text-ink-300">—</span>}</td>
                    <td className="px-3 py-2.5">
                      <div className="text-ink-900">{p.platforms.join(", ") || "—"}</div>
                      <div className="text-xs text-ink-500">{p.regions.join(", ")}</div>
                    </td>
                    <td className="px-3 py-2.5 capitalize text-ink-700">{p.suppliers.join(", ") || "—"}</td>
                    <td className="px-3 py-2.5 text-right font-mono tabular-nums">{p.offer_count}</td>
                    <td className="px-3 py-2.5 text-right font-mono tabular-nums">{money(p.lowest_price, p.lowest_price_currency)}</td>
                    <td className="px-3 py-2.5"><AiStatus value={p.content_status} /></td>
                    <td className="px-3 py-2.5"><ProductStatus value={p.product_status} /></td>
                    <td className="px-3 py-2.5"><PublishStatus value={p.publishing_status} /></td>
                    <td className="px-4 py-2.5 text-right text-xs text-ink-500">{relTime(p.updated_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {data && data.total > 0 && (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-ink-600">
          <span>
            {((page - 1) * pageSize + 1).toLocaleString()}–{Math.min(page * pageSize, data.total).toLocaleString()} of {data.total.toLocaleString()}
          </span>
          <div className="flex items-center gap-2">
            <select
              aria-label="Products per page"
              className="h-8 rounded-md border border-ink-200 bg-white px-2"
              value={pageSize}
              onChange={(e) => setParam({ page_size: e.target.value })}
            >
              {[25, 50, 100, 200].map((n) => (
                <option key={n} value={n}>{n} per page</option>
              ))}
            </select>
            <Button variant="ghost" className="h-8" disabled={page <= 1} onClick={() => setParam({ page: String(page - 1) })}>Previous</Button>
            <Pill>{page} / {pages.toLocaleString()}</Pill>
            <Button variant="ghost" className="h-8" disabled={page >= pages} onClick={() => setParam({ page: String(page + 1) })}>Next</Button>
          </div>
        </div>
      )}
    </>
  );
}

export default function CataloguePage() {
  return (
    <Suspense fallback={<div className="h-64 animate-pulse rounded-xl bg-ink-50" />}>
      <Catalogue />
    </Suspense>
  );
}
