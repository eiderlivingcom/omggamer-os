"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Button, Card, Empty, Notice, PageHeader, Pill, Stat, cx, fmtDate } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useSession } from "@/lib/session";

type Connector = {
  storefront: string;
  name: string;
  domain: string;
  enabled: boolean;
  url: string;
  secret_set: boolean;
  status: string | null;
  checked_at: string | null;
  error: string | null;
  plugin_version: string | null;
  woocommerce: string | null;
  dokan: string | null;
  currency: string | null;
};
type Overview = { queue: Record<string, number>; listings: Record<string, number>; approved_not_published: number; storefronts: Connector[] };
type Item = { id: string; product_id: string; product_title: string | null; storefront: string | null; action: string; status: string; attempts: number; last_error: string | null; updated_at: string };
type Log = { id: string; storefront: string; product_id: string | null; product_title: string | null; operation: string; status: string; response: Record<string, unknown>; created_at: string };

const TONE: Record<string, "ok" | "warn" | "bad" | "info" | "muted"> = {
  queued: "info", running: "info", completed: "ok", failed: "bad", cancelled: "muted", success: "ok", skipped: "muted",
};

export default function PublishingPage() {
  const { can } = useSession();
  const manage = can("publishing:manage");
  const [ov, setOv] = useState<Overview | null>(null);
  const [items, setItems] = useState<Item[] | null>(null);
  const [logs, setLogs] = useState<Log[] | null>(null);
  const [filter, setFilter] = useState("");
  const [tab, setTab] = useState<"queue" | "logs" | "storefronts">("queue");
  const [secret, setSecret] = useState<{ code: string; value: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [o, q] = await Promise.all([api<Overview>("/publishing/overview"), api<Item[]>(`/publishing/queue?limit=200${filter ? `&status=${filter}` : ""}`)]);
      setOv(o);
      setItems(q);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load publishing.");
    }
  }, [filter]);
  useEffect(() => {
    void load();
    const t = setInterval(load, (ov?.queue.queued ?? 0) + (ov?.queue.running ?? 0) > 0 ? 4000 : 20000);
    return () => clearInterval(t);
  }, [load, ov?.queue.queued, ov?.queue.running]);
  useEffect(() => {
    if (tab === "logs") api<Log[]>("/publishing/sync-logs?limit=200").then(setLogs).catch(() => setLogs([]));
  }, [tab]);

  async function act(fn: () => Promise<unknown>, ok?: string) {
    setError(null);
    setNotice(null);
    try {
      await fn();
      if (ok) setNotice(ok);
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "That did not work.");
    }
  }

  return (
    <>
      <PageHeader
        title="Publishing"
        description="Approved products go to WooCommerce through the OMGGamer Catalogue Connector. Products are matched by their OMGGamer ID, so they are never duplicated."
      />
      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {notice && <div className="mb-4"><Notice tone="ok">{notice}</Notice></div>}

      <div className="mb-6 grid grid-cols-2 gap-x-6 gap-y-5 rounded-xl border border-ink-100 bg-white p-5 shadow-sm sm:grid-cols-3 lg:grid-cols-6">
        <Stat label="Live listings" value={ov?.listings.synced ?? 0} />
        <Stat label="Approved, not published" value={ov?.approved_not_published ?? 0} hint={<Link className="underline" href="/catalogue?status=approved">Open</Link>} />
        <Stat label="Queued" value={(ov?.queue.queued ?? 0) + (ov?.queue.running ?? 0)} />
        <Stat label="Failed" value={ov?.queue.failed ?? 0} tone={ov?.queue.failed ? "bad" : undefined} />
        <Stat label="Sync failures" value={ov?.listings.failed ?? 0} tone={ov?.listings.failed ? "warn" : undefined} />
        <Stat label="Unpublished" value={ov?.listings.unpublished ?? 0} />
      </div>

      <div className="mb-5 flex gap-1 border-b border-ink-100">
        {([["queue", "Publishing queue"], ["logs", "Sync log"], ["storefronts", "Storefronts"]] as const).map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} className={cx("border-b-2 px-3 py-2 text-sm font-medium", tab === k ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-900")}>{l}</button>
        ))}
      </div>

      {tab === "queue" && (
        <Card
          title="Queue"
          aside={
            <div className="flex items-center gap-2">
              {manage && (ov?.queue.failed ?? 0) > 0 && (
                <Button variant="ghost" className="h-8" onClick={() => act(async () => { const r = await api<{ queued: number }>("/publishing/retry-failed", { method: "POST" }); setNotice(`${r.queued} items queued again.`); })}>Retry all failed</Button>
              )}
              <select className="h-8 rounded-md border border-ink-200 bg-white px-2 text-sm" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter">
                <option value="">All</option>
                {["queued", "running", "completed", "failed", "cancelled"].map((s) => <option key={s}>{s}</option>)}
              </select>
            </div>
          }
        >
          {!items ? <div className="h-32 animate-pulse rounded-lg bg-ink-50" /> : items.length === 0 ? (
            <Empty title="Nothing in the queue">Approve products in the Catalogue, then use “Publish”.</Empty>
          ) : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm">
                <thead><tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase text-ink-500"><th className="px-5 py-2">Product</th><th className="px-3 py-2">Store</th><th className="px-3 py-2">Action</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Updated</th><th className="px-5 py-2" /></tr></thead>
                <tbody className="divide-y divide-ink-100">
                  {items.map((i) => (
                    <tr key={i.id}>
                      <td className="px-5 py-2">
                        <Link className="font-medium text-ink-900 hover:text-brand-700" href={`/catalogue/${i.product_id}`}>{i.product_title}</Link>
                        {i.last_error && <div className="max-w-lg truncate text-xs text-rose-700" title={i.last_error}>{i.last_error}</div>}
                      </td>
                      <td className="px-3 py-2">{i.storefront}</td>
                      <td className="px-3 py-2">{i.action === "upsert" ? "Publish / update" : "Unpublish"}</td>
                      <td className="px-3 py-2"><Pill tone={TONE[i.status] ?? "muted"}>{i.status}</Pill>{i.attempts > 1 && <span className="ml-1 text-xs text-ink-500">×{i.attempts}</span>}</td>
                      <td className="px-3 py-2 text-xs text-ink-500">{fmtDate(i.updated_at)}</td>
                      <td className="px-5 py-2 text-right">
                        {manage && ["failed", "cancelled"].includes(i.status) && <button className="text-xs font-semibold text-brand-700" onClick={() => act(() => api(`/publishing/queue/${i.id}/retry`, { method: "POST" }), "Queued again.")}>Retry</button>}
                        {manage && ["queued", "failed"].includes(i.status) && <button className="ml-3 text-xs font-semibold text-rose-700" onClick={() => act(() => api(`/publishing/queue/${i.id}/cancel`, { method: "POST" }), "Cancelled.")}>Cancel</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {tab === "logs" && (
        <Card title="Sync log">
          {!logs ? <div className="h-32 animate-pulse rounded-lg bg-ink-50" /> : logs.length === 0 ? <Empty title="No sync activity yet" /> : (
            <ul className="divide-y divide-ink-100 text-sm">
              {logs.map((l) => (
                <li key={l.id} className="flex flex-wrap items-center gap-3 py-2">
                  <span className="w-36 shrink-0 text-xs text-ink-500">{fmtDate(l.created_at)}</span>
                  <Pill tone={TONE[l.status] ?? "muted"}>{l.status}</Pill>
                  <span className="font-medium">{l.operation}</span>
                  {l.product_id ? <Link className="text-ink-800 hover:text-brand-700" href={`/catalogue/${l.product_id}`}>{l.product_title}</Link> : null}
                  <span className="text-ink-500">→ {l.storefront}</span>
                  {typeof l.response.product_id !== "undefined" && <span className="font-mono text-xs text-ink-500">#{String(l.response.product_id)}</span>}
                  {typeof l.response.error === "string" && <span className="w-full text-xs text-rose-700">{l.response.error}</span>}
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {tab === "storefronts" && (
        <div className="space-y-6">
          {secret && (
            <Notice tone="ok">
              <div className="font-semibold">Connector secret for {secret.code} (shown once)</div>
              <code className="mt-1 block select-all break-all rounded bg-white px-2 py-1 font-mono text-[12.5px] text-ink-900">{secret.value}</code>
              <div className="mt-1 text-xs">Paste it in WordPress → WooCommerce → OMGGamer Connector on that store, save, then press Test here.</div>
              <button className="mt-1 text-xs font-semibold underline" onClick={() => setSecret(null)}>Done</button>
            </Notice>
          )}
          {ov?.storefronts.map((s) => (
            <Card
              key={s.storefront}
              title={s.name}
              aside={<Pill tone={s.enabled ? "ok" : "muted"}>{s.enabled ? "Publishing on" : "Publishing off"}</Pill>}
            >
              <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt className="text-xs text-ink-500">Connector endpoint</dt><dd className="break-all font-mono text-xs">{s.url}</dd></div>
                <div><dt className="text-xs text-ink-500">Connection</dt><dd><Pill tone={s.status === "connected" ? "ok" : s.status === "failed" ? "bad" : "muted"}>{s.status ?? "not tested"}</Pill> {s.checked_at && <span className="text-xs text-ink-500">{fmtDate(s.checked_at)}</span>}</dd></div>
                <div><dt className="text-xs text-ink-500">Store</dt><dd>{s.plugin_version ? `Connector ${s.plugin_version} · WooCommerce ${s.woocommerce ?? "?"}${s.dokan ? ` · Dokan ${s.dokan}` : ""} · ${s.currency ?? ""}` : "—"}</dd></div>
                <div><dt className="text-xs text-ink-500">Secret</dt><dd>{s.secret_set ? "Set" : "Not set"}</dd></div>
              </dl>
              {s.error && s.status === "failed" && <p className="mt-2 text-xs text-rose-700">{s.error}</p>}
              <div className="mt-4 flex flex-wrap gap-2 border-t border-ink-100 pt-4">
                {can("credentials:manage") && (
                  <Button variant="ghost" onClick={() => (!s.secret_set || window.confirm("Generate a new secret? The store stops accepting requests until you paste the new one there.")) && act(async () => { const r = await api<{ secret: string }>(`/storefronts/${s.storefront}/connector/secret`, { method: "POST" }); setSecret({ code: s.storefront, value: r.secret }); })}>
                    {s.secret_set ? "New secret" : "Generate secret"}
                  </Button>
                )}
                {manage && <Button variant="ghost" disabled={!s.secret_set} onClick={() => act(() => api(`/storefronts/${s.storefront}/connector/test`, { method: "POST" }), "Connection tested.")}>Test connection</Button>}
                {manage && (
                  <Button
                    variant={s.enabled ? "danger" : "primary"}
                    disabled={!s.enabled && s.status !== "connected"}
                    onClick={() => (s.enabled || window.confirm(`Turn on publishing to ${s.name} (${s.domain})?`)) && act(() => api(`/storefronts/${s.storefront}/connector`, { method: "PUT", json: { enabled: !s.enabled } }), s.enabled ? "Publishing switched off." : "Publishing switched on.")}
                  >
                    {s.enabled ? "Switch publishing off" : "Switch publishing on"}
                  </Button>
                )}
                <a className="ml-auto self-center text-xs font-semibold text-brand-700 underline" href="/api/v1/connector/plugin.zip">Download connector plugin</a>
              </div>
            </Card>
          ))}
        </div>
      )}
    </>
  );
}
