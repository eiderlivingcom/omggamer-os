"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { ProductStatus } from "@/components/status";
import { Button, Card, Empty, Notice, PageHeader, Pill, cx, relTime } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useSession } from "@/lib/session";

type Side = {
  id: string;
  title: string;
  product_type: string;
  status: string;
  developer: string | null;
  publisher: string | null;
  release_date: string | null;
  image: string | null;
  suppliers: { code: string; title: string; external_id: string }[];
  variants: string[];
  offers_in_stock: number;
};
type Match = {
  id: string;
  score: number;
  reasons: string[];
  status: string;
  supplier: string | null;
  created_at: string;
  decided_at: string | null;
  product: Side;
  candidate: Side;
};
type Page = {
  items: Match[];
  total: number;
  page: number;
  page_size: number;
  counts: Record<string, number>;
  scan: { last_run_at?: string; complete?: boolean } | null;
};

const STATUSES = [
  ["pending", "To review"],
  ["accepted", "Matched"],
  ["rejected", "Not the same"],
  ["superseded", "Closed"],
] as const;

function SideCard({ s, label }: { s: Side; label: string }) {
  return (
    <div className="min-w-0 rounded-xl border border-ink-100 bg-white p-4">
      <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-500">{label}</div>
      <div className="flex gap-3">
        {s.image ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={s.image} alt="" className="h-20 w-16 flex-none rounded-md bg-ink-50 object-cover" loading="lazy" />
        ) : (
          <div className="h-20 w-16 flex-none rounded-md bg-ink-50" />
        )}
        <div className="min-w-0">
          <Link href={`/catalogue/${s.id}`} className="block font-semibold text-ink-900 hover:text-brand-700">{s.title}</Link>
          <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-500">
            <ProductStatus value={s.status} />
            <span className="capitalize">{s.product_type.replace("_", " ")}</span>
            {s.release_date && <span>· {s.release_date.slice(0, 4)}</span>}
            <span>· {s.offers_in_stock} offers in stock</span>
          </div>
          {(s.developer || s.publisher) && (
            <div className="mt-1 truncate text-xs text-ink-500">{[s.developer, s.publisher].filter(Boolean).join(" · ")}</div>
          )}
        </div>
      </div>
      <ul className="mt-3 space-y-1 text-xs">
        {s.suppliers.map((sp) => (
          <li key={sp.code + sp.external_id} className="truncate">
            <span className="mr-1.5 rounded bg-ink-100 px-1.5 py-0.5 font-mono uppercase text-ink-700">{sp.code}</span>
            <span className="text-ink-700">{sp.title}</span>
          </li>
        ))}
      </ul>
      {s.variants.length > 0 && <div className="mt-2 truncate text-xs text-ink-500">{s.variants.join(" | ")}</div>}
    </div>
  );
}

export default function MatchReviewPage() {
  const { can } = useSession();
  const canEdit = can("catalogue:write");
  const [status, setStatus] = useState<(typeof STATUSES)[number][0]>("pending");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<Page | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ tone: "ok" | "bad" | "info"; text: string } | null>(null);

  const load = useCallback(async () => {
    try {
      setData(await api<Page>(`/catalogue/matches?status=${status}&page=${page}`));
    } catch (e) {
      setMsg({ tone: "bad", text: e instanceof ApiError ? e.message : "Could not load suggestions." });
    }
  }, [status, page]);

  useEffect(() => {
    void load();
  }, [load]);

  async function decide(m: Match, action: "accept" | "reject") {
    setBusy(m.id + action);
    setMsg(null);
    try {
      const r = await api<{ title?: string }>(`/catalogue/matches/${m.id}/${action}`, { method: "POST" });
      setMsg({ tone: "ok", text: action === "accept" ? `Matched. Both suppliers now offer “${r.title}”.` : "Marked as different items." });
      await load();
    } catch (e) {
      setMsg({ tone: "bad", text: e instanceof ApiError ? e.message : "Could not save." });
      await load();
    } finally {
      setBusy(null);
    }
  }

  async function scan() {
    setBusy("scan");
    try {
      await api("/catalogue/matches/scan", { method: "POST", json: { restart: !data?.scan || !!data.scan.complete } });
      setMsg({ tone: "info", text: "Searching the catalogue for possible duplicates. New suggestions appear here as they are found." });
    } catch (e) {
      setMsg({ tone: "bad", text: e instanceof ApiError ? e.message : "Could not start the search." });
    } finally {
      setBusy(null);
    }
  }

  const pages = data ? Math.max(1, Math.ceil(data.total / data.page_size)) : 1;
  return (
    <>
      <div className="mb-1 text-sm"><Link href="/catalogue" className="text-ink-500 hover:text-ink-900">← Catalogue</Link></div>
      <PageHeader
        title="Match review"
        description="Products that may be the same item from different suppliers, e.g. “GTA 6 New” and “GTA 6 2026”. Matching merges them into one listing with both suppliers' offers, and the name is remembered for future imports."
        actions={
          canEdit && (
            <Button variant="ghost" onClick={scan} disabled={busy !== null}>
              {busy === "scan" ? "Starting…" : "Find possible duplicates"}
            </Button>
          )
        }
      />
      <div className="mb-4 flex flex-wrap gap-2">
        {STATUSES.map(([k, label]) => (
          <button
            key={k}
            onClick={() => { setStatus(k); setPage(1); }}
            className={cx("rounded-full border px-3 py-1.5 text-sm font-medium", status === k ? "border-brand-600 bg-brand-50 text-brand-800" : "border-ink-200 text-ink-700 hover:bg-ink-50")}
          >
            {label} {data?.counts[k] ? <span className="ml-1 text-ink-500">{data.counts[k].toLocaleString()}</span> : null}
          </button>
        ))}
        {data?.scan?.last_run_at && (
          <span className="ml-auto self-center text-xs text-ink-500">
            Last catalogue search {relTime(data.scan.last_run_at)}{data.scan.complete ? " (whole catalogue checked)" : " (continues on the next run)"}
          </span>
        )}
      </div>
      {msg && <div className="mb-4"><Notice tone={msg.tone}>{msg.text}</Notice></div>}
      {!data ? (
        <div className="h-48 animate-pulse rounded-xl bg-ink-50" />
      ) : data.items.length === 0 ? (
        <Card>
          <Empty title={status === "pending" ? "Nothing to review" : "Nothing here yet"}>
            {status === "pending"
              ? "New suggestions appear when a supplier import brings a product that looks like one another supplier already has."
              : "Decisions you make in “To review” are listed here."}
          </Empty>
        </Card>
      ) : (
        <div className="space-y-4">
          {data.items.map((m) => (
            <Card key={m.id}>
              <div className="mb-3 flex flex-wrap items-center gap-2">
                <Pill tone={m.score >= 0.9 ? "ok" : m.score >= 0.75 ? "warn" : "muted"}>{Math.round(m.score * 100)}% match</Pill>
                {m.reasons.map((r) => <span key={r} className="text-xs text-ink-500">{r}</span>)}
                <span className="ml-auto text-xs text-ink-500">
                  {m.supplier ? `New from ${m.supplier} · ` : ""}{relTime(m.decided_at ?? m.created_at)}
                </span>
              </div>
              <div className="grid gap-3 lg:grid-cols-2">
                <SideCard s={m.product} label={m.supplier ? `New (${m.supplier})` : "New"} />
                <SideCard s={m.candidate} label="Already in the catalogue" />
              </div>
              {status === "pending" && canEdit && (
                <div className="mt-4 flex flex-wrap justify-end gap-2">
                  <Button variant="ghost" onClick={() => decide(m, "reject")} disabled={busy !== null}>
                    {busy === m.id + "reject" ? "Saving…" : "Not the same item"}
                  </Button>
                  <Button onClick={() => decide(m, "accept")} disabled={busy !== null}>
                    {busy === m.id + "accept" ? "Matching…" : "Same item: match"}
                  </Button>
                </div>
              )}
            </Card>
          ))}
          {pages > 1 && (
            <div className="flex items-center justify-end gap-2 text-sm">
              <Button variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
              <span className="text-ink-500">Page {page} of {pages}</span>
              <Button variant="ghost" disabled={page >= pages} onClick={() => setPage(page + 1)}>Next</Button>
            </div>
          )}
        </div>
      )}
    </>
  );
}
