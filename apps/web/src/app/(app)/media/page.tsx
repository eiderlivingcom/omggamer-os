"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Button, Card, Empty, Notice, PageHeader, Pill, Stat, cx, fmtDate } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useSession } from "@/lib/session";

type Overview = {
  total: number;
  by_type: Record<string, number>;
  by_status: Record<string, number>;
  hidden: number;
  products_without_cover: number;
  live_without_cover: number;
  live_failed: number;
  last_checked_at: string | null;
};
type Row = {
  id: string;
  product_id: string;
  product_title: string;
  product_sku: string | null;
  product_status: string;
  url: string;
  media_type: string;
  processing_status: string;
  position: number;
  content_type: string | null;
  width: number | null;
  height: number | null;
  bytes: number | null;
  checked_at: string | null;
  error: string | null;
  hidden: boolean;
};
type Missing = { product_id: string; title: string; sku: string | null; status: string; other_images: number };

const STATUS_TONE: Record<string, "ok" | "bad" | "muted" | "info"> = { ready: "ok", failed: "bad", pending: "muted", processing: "info" };
const STATUS_LABEL: Record<string, string> = { ready: "Checked", failed: "Broken", pending: "Not checked yet", processing: "Checking" };
const kb = (n: number | null) => (n == null ? "" : n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

export default function MediaPage() {
  const { can } = useSession();
  const edit = can("catalogue:write");
  const [ov, setOv] = useState<Overview | null>(null);
  const [tab, setTab] = useState<"library" | "broken" | "covers">("library");
  const [rows, setRows] = useState<Row[] | null>(null);
  const [total, setTotal] = useState(0);
  const [missing, setMissing] = useState<Missing[] | null>(null);
  const [f, setF] = useState({ q: "", media_type: "", status: "", scope: "live", hidden: "" });
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);

  const query = useCallback(
    (offset: number) => {
      const p = new URLSearchParams({ limit: "48", offset: String(offset), scope: f.scope });
      if (f.q.trim()) p.set("q", f.q.trim());
      if (f.media_type) p.set("media_type", f.media_type);
      const status = tab === "broken" ? "failed" : f.status;
      if (status) p.set("status", status);
      if (f.hidden) p.set("hidden", f.hidden);
      return p.toString();
    },
    [f, tab],
  );
  const loadOverview = useCallback(() => api<Overview>("/media/overview").then(setOv).catch(() => undefined), []);
  const loadRows = useCallback(async () => {
    setRows(null);
    try {
      const r = await api<{ items: Row[]; total: number }>(`/media?${query(0)}`);
      setRows(r.items);
      setTotal(r.total);
    } catch {
      setRows([]);
    }
  }, [query]);
  useEffect(() => {
    void loadOverview();
  }, [loadOverview]);
  useEffect(() => {
    if (tab === "covers") api<Missing[]>("/media/missing-covers?limit=200").then(setMissing).catch(() => setMissing([]));
    else void loadRows();
  }, [tab, loadRows]);

  async function more() {
    if (!rows) return;
    const r = await api<{ items: Row[]; total: number }>(`/media?${query(rows.length)}`);
    setRows([...rows, ...r.items]);
  }
  async function act(fn: () => Promise<unknown>, ok: string) {
    setBusy(true);
    setNotice(null);
    try {
      await fn();
      setNotice({ tone: "ok", text: ok });
      await Promise.all([loadOverview(), tab === "covers" ? Promise.resolve() : loadRows()]);
    } catch (e) {
      setNotice({ tone: "bad", text: e instanceof ApiError ? e.message : "That did not work." });
    } finally {
      setBusy(false);
    }
  }
  const patch = (id: string, body: Record<string, boolean>) => (r: Row) => {
    setRows((cur) => (cur ? cur.map((x) => (x.id === id ? r : x)) : cur));
    return body;
  };
  async function update(id: string, body: Record<string, boolean>, ok: string) {
    await act(async () => patch(id, body)(await api<Row>(`/media/${id}`, { method: "PATCH", json: body })), ok);
  }

  const pending = ov?.by_status.pending ?? 0;
  return (
    <>
      <PageHeader
        title="Media"
        description="Every product image and screenshot from the suppliers. Images are checked automatically (type, size, broken links); broken and hidden images are never sent to the store."
        actions={
          edit ? (
            <Button disabled={busy} onClick={() => act(async () => { const r = await api<{ checked: number; ready: number; failed: number }>("/media/check", { method: "POST", json: {} }); setNotice({ tone: "ok", text: `Checked ${r.checked}: ${r.ready} fine, ${r.failed} broken.` }); }, "Checked.")}>
              Check images now
            </Button>
          ) : undefined
        }
      />
      {notice && <div className="mb-4"><Notice tone={notice.tone}>{notice.text}</Notice></div>}

      <div className="mb-6 grid grid-cols-2 gap-x-6 gap-y-5 rounded-xl border border-ink-100 bg-white p-5 shadow-sm sm:grid-cols-3 lg:grid-cols-6">
        <Stat label="Images" value={(ov?.total ?? 0).toLocaleString()} hint={ov ? `${(ov.by_type.cover ?? 0).toLocaleString()} covers · ${(ov.by_type.screenshot ?? 0).toLocaleString()} screenshots` : undefined} />
        <Stat label="Checked, fine" value={(ov?.by_status.ready ?? 0).toLocaleString()} tone="ok" />
        <Stat label="Broken" value={(ov?.by_status.failed ?? 0).toLocaleString()} tone={ov?.by_status.failed ? "bad" : undefined} />
        <Stat label="Not checked yet" value={pending.toLocaleString()} hint={ov?.last_checked_at ? `Last check ${fmtDate(ov.last_checked_at)}` : "Checks run every 10 minutes"} />
        <Stat label="Live games without a cover" value={ov?.live_without_cover ?? 0} tone={ov?.live_without_cover ? "warn" : undefined} />
        <Stat label="Broken images on live games" value={ov?.live_failed ?? 0} tone={ov?.live_failed ? "bad" : undefined} />
      </div>

      <div className="mb-5 flex gap-1 border-b border-ink-100">
        {([["library", "Media library"], ["broken", "Broken images"], ["covers", "Missing covers"]] as const).map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} className={cx("border-b-2 px-3 py-2 text-sm font-medium", tab === k ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-900")}>{l}</button>
        ))}
      </div>

      {tab === "covers" ? (
        <Card title="Games without a usable cover" aside={<span className="text-xs text-ink-500">Live games first. Open a game to set one of its images as the cover.</span>}>
          {!missing ? <div className="h-24 animate-pulse rounded-lg bg-ink-50" /> : missing.length === 0 ? <Empty title="Every game has a cover" /> : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full min-w-[640px] text-sm">
                <thead><tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase text-ink-500"><th className="px-5 py-2">Game</th><th className="px-3 py-2">SKU</th><th className="px-3 py-2">Status</th><th className="px-5 py-2 text-right">Other images</th></tr></thead>
                <tbody className="divide-y divide-ink-100">
                  {missing.map((m) => (
                    <tr key={m.product_id}>
                      <td className="px-5 py-2"><Link className="font-medium text-ink-900 hover:text-brand-700" href={`/catalogue/${m.product_id}`}>{m.title}</Link></td>
                      <td className="px-3 py-2 font-mono text-xs text-ink-500">{m.sku}</td>
                      <td className="px-3 py-2"><Pill tone={m.status === "published" ? "warn" : "muted"}>{m.status}</Pill></td>
                      <td className="px-5 py-2 text-right">{m.other_images}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : (
        <Card
          title={tab === "broken" ? "Broken images" : "Media library"}
          aside={<span className="text-xs text-ink-500">{rows ? `${total.toLocaleString()} images` : ""}</span>}
        >
          <div className="mb-4 flex flex-wrap gap-2">
            <input value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} placeholder="Search game or SKU" aria-label="Search game or SKU" className="h-9 min-w-0 flex-1 rounded-lg border border-ink-200 px-3 text-sm sm:max-w-xs" />
            <select aria-label="Games" value={f.scope} onChange={(e) => setF({ ...f, scope: e.target.value })} className="h-9 rounded-lg border border-ink-200 bg-white px-2 text-sm">
              <option value="live">Live games</option>
              <option value="approved">Approved and live</option>
              <option value="all">All games</option>
            </select>
            <select aria-label="Type" value={f.media_type} onChange={(e) => setF({ ...f, media_type: e.target.value })} className="h-9 rounded-lg border border-ink-200 bg-white px-2 text-sm">
              <option value="">All types</option>
              {["cover", "screenshot", "banner", "logo", "video"].map((t) => <option key={t} value={t}>{t}</option>)}
            </select>
            {tab === "library" && (
              <select aria-label="Check" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })} className="h-9 rounded-lg border border-ink-200 bg-white px-2 text-sm">
                <option value="">Any check result</option>
                <option value="ready">Checked, fine</option>
                <option value="failed">Broken</option>
                <option value="pending">Not checked yet</option>
              </select>
            )}
            <select aria-label="Visibility" value={f.hidden} onChange={(e) => setF({ ...f, hidden: e.target.value })} className="h-9 rounded-lg border border-ink-200 bg-white px-2 text-sm">
              <option value="">Shown and hidden</option>
              <option value="false">Shown on the store</option>
              <option value="true">Hidden</option>
            </select>
          </div>
          {!rows ? (
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">{Array.from({ length: 12 }).map((_, i) => <div key={i} className="aspect-[3/4] animate-pulse rounded-lg bg-ink-50" />)}</div>
          ) : rows.length === 0 ? (
            <Empty title={tab === "broken" ? "No broken images" : "No images match"}>{tab === "broken" ? "Images are checked every 10 minutes, live games first." : undefined}</Empty>
          ) : (
            <>
              <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                {rows.map((m) => (
                  <li key={m.id} className={cx("flex flex-col overflow-hidden rounded-lg border bg-white", m.hidden ? "border-dashed border-ink-200 opacity-60" : "border-ink-100")}>
                    <a href={m.url} target="_blank" rel="noreferrer" className="relative block aspect-[4/3] bg-ink-50" aria-label={`Open image of ${m.product_title}`}>
                      {m.media_type !== "video" && (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={m.url} alt="" loading="lazy" className="h-full w-full object-cover" />
                      )}
                      <span className="absolute left-2 top-2"><Pill tone={m.media_type === "cover" ? "info" : "muted"}>{m.media_type}</Pill></span>
                    </a>
                    <div className="flex flex-1 flex-col gap-1 p-3 text-xs">
                      <Link href={`/catalogue/${m.product_id}`} className="line-clamp-2 text-sm font-medium text-ink-900 hover:text-brand-700">{m.product_title}</Link>
                      <div className="flex flex-wrap items-center gap-1">
                        <Pill tone={STATUS_TONE[m.processing_status] ?? "muted"}>{STATUS_LABEL[m.processing_status] ?? m.processing_status}</Pill>
                        {m.hidden && <Pill tone="warn">Hidden</Pill>}
                      </div>
                      {m.width && m.height ? <span className="text-ink-500">{m.width}×{m.height} · {(m.content_type ?? "").replace("image/", "").toUpperCase()} · {kb(m.bytes)}</span> : null}
                      {m.error && <span className="text-rose-700">{m.error}</span>}
                      {edit && (
                        <div className="mt-auto flex flex-wrap gap-x-3 gap-y-1 pt-2">
                          {m.media_type !== "cover" && m.media_type !== "video" && (
                            <button disabled={busy} className="font-semibold text-brand-700 hover:underline" onClick={() => update(m.id, { make_cover: true }, "Set as the cover. Re-publish the game to update the store.")}>Make cover</button>
                          )}
                          <button disabled={busy} className="font-semibold text-ink-700 hover:underline" onClick={() => update(m.id, { hidden: !m.hidden }, m.hidden ? "Shown on the store again." : "Hidden from the store.")}>{m.hidden ? "Show" : "Hide"}</button>
                          <button disabled={busy} className="font-semibold text-ink-700 hover:underline" onClick={() => act(() => api("/media/check", { method: "POST", json: { ids: [m.id] } }), "Checked again.")}>Check again</button>
                        </div>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
              {rows.length < total && (
                <div className="mt-4 text-center"><Button variant="ghost" onClick={() => void more()}>Show more ({(total - rows.length).toLocaleString()} left)</Button></div>
              )}
            </>
          )}
        </Card>
      )}
    </>
  );
}
