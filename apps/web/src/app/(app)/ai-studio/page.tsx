"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Button, Card, Empty, Notice, PageHeader, Pill, Stat, cx, fmtDate } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useSession } from "@/lib/session";

type Settings = {
  model: string;
  temperature: number;
  send_temperature: boolean;
  max_output_tokens: number;
  batch_size: number;
  concurrency: number;
  max_retries: number;
  language: string;
  locale: string;
  brand_voice: string;
  seo_requirements: string;
  pricing: Record<string, { input_per_million: number; output_per_million: number }>;
};
type Overview = {
  paused: boolean;
  key: { configured: boolean; status: string; last_error: string | null };
  settings: Settings;
  prompt_version: number;
  jobs: Record<string, number>;
  eligible_products: number;
  awaiting_review: number;
};
type Job = {
  id: string;
  product_id: string | null;
  product_title: string | null;
  task: string;
  status: string;
  outcome: string | null;
  attempts: number;
  model: string | null;
  token_usage: { input?: number; output?: number };
  estimated_cost: string;
  error_message: string | null;
  created_at: string;
  completed_at: string | null;
};
type Costs = {
  days: number;
  rows: { model: string | null; jobs: number; products: number; input_tokens: number; output_tokens: number; cost: string }[];
  total_cost: string;
  products: number;
  cost_per_product: string;
  failed_requests: number;
};
type Prompt = { id: string; version: number; system_prompt: string; user_template: string; is_active: boolean; notes: string | null; created_at: string };

const usd = (v: string | number) => `$${Number(v).toFixed(Number(v) < 1 ? 4 : 2)}`;
const STATUS_TONE: Record<string, "ok" | "warn" | "bad" | "info" | "muted"> = {
  queued: "info", running: "info", paused: "warn", completed: "ok", failed: "bad", cancelled: "muted",
};

export default function AiStudioPage() {
  const { can } = useSession();
  const [ov, setOv] = useState<Overview | null>(null);
  const [jobs, setJobs] = useState<Job[] | null>(null);
  const [costs, setCosts] = useState<Costs | null>(null);
  const [filter, setFilter] = useState("");
  const [tab, setTab] = useState<"queue" | "costs" | "settings" | "prompts">("queue");
  const [count, setCount] = useState(50);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const [o, j] = await Promise.all([
        api<Overview>("/ai/overview"),
        api<Job[]>(`/ai/jobs?limit=100${filter ? `&status=${filter}` : ""}`),
      ]);
      setOv(o);
      setJobs(j);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load AI Studio.");
    }
  }, [filter]);

  useEffect(() => {
    void load();
    const active = (ov?.jobs.queued ?? 0) + (ov?.jobs.running ?? 0) > 0;
    const t = setInterval(load, active ? 4000 : 20000);
    return () => clearInterval(t);
  }, [load, ov?.jobs.queued, ov?.jobs.running]);

  useEffect(() => {
    if (tab === "costs") api<Costs>("/ai/costs?days=30").then(setCosts).catch(() => undefined);
  }, [tab]);

  useEffect(() => {
    if (ov) setCount(ov.settings.batch_size);
  }, [ov?.settings.batch_size]); // eslint-disable-line react-hooks/exhaustive-deps

  async function act(fn: () => Promise<string>) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      setNotice(await fn());
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "That did not work.");
    } finally {
      setBusy(false);
    }
  }

  const write = can("content:write");
  const ready = ov?.key.configured;

  return (
    <>
      <PageHeader
        title="AI Studio"
        description="Generates OMGGamer product copy from supplier facts with OpenAI. Original supplier data is never changed, and every result waits for approval."
        actions={ov && <Pill tone={ov.paused ? "warn" : ov.key.status === "connected" ? "ok" : "muted"}>{ov.paused ? "Paused" : ov.key.configured ? `OpenAI · ${ov.settings.model}` : "No OpenAI key"}</Pill>}
      />
      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {notice && <div className="mb-4"><Notice tone="ok">{notice}</Notice></div>}
      {ov && !ready && (
        <div className="mb-4">
          <Notice>Add the OpenAI API key under <Link className="font-semibold underline" href="/settings/credentials">Settings → API credentials</Link>.</Notice>
        </div>
      )}

      <div className="mb-6 grid grid-cols-2 gap-x-6 gap-y-5 rounded-xl border border-ink-100 bg-white p-5 shadow-sm sm:grid-cols-3 lg:grid-cols-6">
        <Stat label="Without AI content" value={ov ? ov.eligible_products.toLocaleString() : "—"} hint="eligible products" />
        <Stat label="Queued" value={ov ? (ov.jobs.queued ?? 0) + (ov.jobs.running ?? 0) : "—"} hint={ov?.jobs.running ? `${ov.jobs.running} running` : undefined} />
        <Stat label="Paused" value={ov?.jobs.paused ?? 0} tone={ov?.jobs.paused ? "warn" : undefined} />
        <Stat label="Completed" value={ov ? (ov.jobs.completed ?? 0).toLocaleString() : "—"} />
        <Stat label="Failed" value={ov?.jobs.failed ?? 0} tone={ov?.jobs.failed ? "bad" : undefined} />
        <Stat label="Awaiting review" value={ov ? ov.awaiting_review.toLocaleString() : "—"} hint={<Link className="underline" href="/catalogue?ai_status=pending_review">Review</Link>} />
      </div>

      {write && ov && (
        <Card title="Generation" className="mb-6">
          <div className="flex flex-wrap items-end gap-3">
            <label className="text-xs font-semibold text-ink-700">
              Products
              <input
                type="number"
                min={1}
                max={5000}
                value={count}
                onChange={(e) => setCount(Number(e.target.value))}
                className="mt-1 block h-9 w-28 rounded-md border border-ink-200 px-2 text-sm"
              />
            </label>
            <Button
              disabled={busy || !ready || ov.eligible_products === 0}
              onClick={() =>
                act(async () => {
                  const r = await api<{ queued: number }>("/ai/generate", { method: "POST", json: { scope: "eligible", limit: count } });
                  return `${r.queued} products queued for AI content.`;
                })
              }
            >
              Start generation
            </Button>
            {ov.paused ? (
              <Button variant="ghost" disabled={busy} onClick={() => act(async () => { const r = await api<{ requeued: number }>("/ai/resume", { method: "POST" }); return `Resumed; ${r.requeued} jobs back in the queue.`; })}>
                Resume
              </Button>
            ) : (
              <Button variant="ghost" disabled={busy} onClick={() => act(async () => { await api("/ai/pause", { method: "POST" }); return "Paused. Running requests finish; queued jobs wait."; })}>
                Pause
              </Button>
            )}
            {(ov.jobs.failed ?? 0) > 0 && (
              <Button variant="ghost" disabled={busy} onClick={() => act(async () => { const r = await api<{ queued: number }>("/ai/jobs/retry-failed", { method: "POST" }); return `${r.queued} failed jobs queued again.`; })}>
                Retry failed ({ov.jobs.failed})
              </Button>
            )}
          </div>
          <p className="mt-3 text-xs text-ink-500">
            Picks products without AI content, newest first. To regenerate specific products, select them in the Catalogue and use “Regenerate”. Products whose supplier facts have not changed are skipped automatically.
          </p>
        </Card>
      )}

      <div className="mb-5 flex gap-1 border-b border-ink-100">
        {(["queue", "costs", ...(can("ai:manage") ? (["settings", "prompts"] as const) : [])] as const).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={cx("border-b-2 px-3 py-2 text-sm font-medium capitalize", tab === t ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-900")}
          >
            {t === "queue" ? "AI log" : t}
          </button>
        ))}
      </div>

      {tab === "queue" && (
        <Card
          title="AI jobs"
          aside={
            <select className="h-8 rounded-md border border-ink-200 bg-white px-2 text-sm" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter by status">
              <option value="">All</option>
              {["queued", "running", "paused", "completed", "failed"].map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          }
        >
          {!jobs ? <div className="h-32 animate-pulse rounded-lg bg-ink-50" /> : jobs.length === 0 ? <Empty title="No AI jobs yet" /> : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full min-w-[820px] text-sm">
                <thead>
                  <tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                    <th className="px-5 py-2">Product</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Result</th>
                    <th className="px-3 py-2 text-right">Tokens</th><th className="px-3 py-2 text-right">Cost</th><th className="px-5 py-2">When</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink-100">
                  {jobs.map((j) => (
                    <tr key={j.id}>
                      <td className="px-5 py-2">
                        {j.product_id ? <Link className="font-medium text-ink-900 hover:text-brand-700" href={`/catalogue/${j.product_id}`}>{j.product_title ?? j.product_id}</Link> : "—"}
                        <div className="text-xs text-ink-500">{j.task === "regenerate" ? "Regenerate" : "Generate"} · attempt {j.attempts}</div>
                        {j.error_message && <div className="max-w-md truncate text-xs text-rose-700" title={j.error_message}>{j.error_message}</div>}
                      </td>
                      <td className="px-3 py-2"><Pill tone={STATUS_TONE[j.status] ?? "muted"}>{j.status}</Pill></td>
                      <td className="px-3 py-2 text-ink-700">{j.outcome?.replace(/_/g, " ") ?? "—"}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs">{j.token_usage.input ? `${j.token_usage.input} / ${j.token_usage.output}` : "—"}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs">{Number(j.estimated_cost) ? usd(j.estimated_cost) : "—"}</td>
                      <td className="px-5 py-2 text-xs text-ink-500">{fmtDate(j.completed_at ?? j.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {tab === "costs" && (
        <Card title="Last 30 days">
          {!costs ? <div className="h-24 animate-pulse rounded-lg bg-ink-50" /> : (
            <>
              <div className="mb-5 grid grid-cols-2 gap-5 sm:grid-cols-4">
                <Stat label="Total cost" value={usd(costs.total_cost)} />
                <Stat label="Products" value={costs.products.toLocaleString()} />
                <Stat label="Per product" value={usd(costs.cost_per_product)} />
                <Stat label="Failed requests" value={costs.failed_requests} tone={costs.failed_requests ? "warn" : undefined} />
              </div>
              {costs.rows.length === 0 ? <Empty title="No AI usage yet" /> : (
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase text-ink-500"><th className="py-2">Model</th><th className="py-2 text-right">Jobs</th><th className="py-2 text-right">Input tokens</th><th className="py-2 text-right">Output tokens</th><th className="py-2 text-right">Cost</th></tr></thead>
                  <tbody className="divide-y divide-ink-100">
                    {costs.rows.map((r) => (
                      <tr key={r.model}><td className="py-2">{r.model}</td><td className="py-2 text-right font-mono">{r.jobs}</td><td className="py-2 text-right font-mono">{r.input_tokens.toLocaleString()}</td><td className="py-2 text-right font-mono">{r.output_tokens.toLocaleString()}</td><td className="py-2 text-right font-mono">{usd(r.cost)}</td></tr>
                    ))}
                  </tbody>
                </table>
              )}
              <p className="mt-3 text-xs text-ink-500">Costs use the per-model prices in Settings. Check them against your OpenAI price list.</p>
            </>
          )}
        </Card>
      )}

      {tab === "settings" && ov && <SettingsForm initial={ov.settings} onSaved={load} />}
      {tab === "prompts" && <Prompts />}
    </>
  );
}

function SettingsForm({ initial, onSaved }: { initial: Settings; onSaved: () => Promise<void> }) {
  const [s, setS] = useState(initial);
  const price = s.pricing[s.model] ?? { input_per_million: 0, output_per_million: 0 };
  const [msg, setMsg] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);

  async function save(e: FormEvent) {
    e.preventDefault();
    setMsg(null);
    try {
      await api("/ai/settings", { method: "PUT", json: s });
      setMsg({ tone: "ok", text: "Settings saved. They apply to the next job." });
      await onSaved();
    } catch (err) {
      setMsg({ tone: "bad", text: err instanceof ApiError ? err.message : "Could not save." });
    }
  }

  const num = (k: keyof Settings, label: string, step = 1, min = 0, max?: number) => (
    <label className="block text-xs font-semibold text-ink-700">
      {label}
      <input type="number" step={step} min={min} max={max} value={s[k] as number} onChange={(e) => setS({ ...s, [k]: Number(e.target.value) })} className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal" />
    </label>
  );

  return (
    <Card title="AI settings">
      <form onSubmit={save} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <label className="block text-xs font-semibold text-ink-700 sm:col-span-2">
          Model
          <input value={s.model} onChange={(e) => setS({ ...s, model: e.target.value })} className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal" />
        </label>
        {num("temperature", "Temperature", 0.1, 0, 2)}
        <label className="flex items-end gap-2 pb-2 text-sm text-ink-700">
          <input type="checkbox" checked={s.send_temperature} onChange={(e) => setS({ ...s, send_temperature: e.target.checked })} /> Send temperature
        </label>
        {num("max_output_tokens", "Max output tokens", 100, 200, 16000)}
        {num("batch_size", "Batch size", 1, 1, 5000)}
        {num("concurrency", "Parallel requests", 1, 1, 20)}
        {num("max_retries", "Retry limit", 1, 1, 10)}
        <label className="block text-xs font-semibold text-ink-700">
          Language
          <input value={s.language} onChange={(e) => setS({ ...s, language: e.target.value })} className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal" />
        </label>
        <label className="block text-xs font-semibold text-ink-700">
          Locale code
          <input value={s.locale} onChange={(e) => setS({ ...s, locale: e.target.value })} className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal" />
        </label>
        <label className="block text-xs font-semibold text-ink-700">
          Input $ / 1M tokens
          <input type="number" step="0.01" min={0} value={price.input_per_million} onChange={(e) => setS({ ...s, pricing: { ...s.pricing, [s.model]: { ...price, input_per_million: Number(e.target.value) } } })} className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal" />
        </label>
        <label className="block text-xs font-semibold text-ink-700">
          Output $ / 1M tokens
          <input type="number" step="0.01" min={0} value={price.output_per_million} onChange={(e) => setS({ ...s, pricing: { ...s.pricing, [s.model]: { ...price, output_per_million: Number(e.target.value) } } })} className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal" />
        </label>
        <label className="block text-xs font-semibold text-ink-700 sm:col-span-2">
          Brand voice
          <textarea rows={4} value={s.brand_voice} onChange={(e) => setS({ ...s, brand_voice: e.target.value })} className="mt-1 w-full rounded-md border border-ink-200 p-2 text-sm font-normal" />
        </label>
        <label className="block text-xs font-semibold text-ink-700 sm:col-span-2">
          SEO requirements
          <textarea rows={4} value={s.seo_requirements} onChange={(e) => setS({ ...s, seo_requirements: e.target.value })} className="mt-1 w-full rounded-md border border-ink-200 p-2 text-sm font-normal" />
        </label>
        <div className="sm:col-span-2 lg:col-span-4"><Button type="submit">Save settings</Button></div>
      </form>
      {msg && <div className="mt-4"><Notice tone={msg.tone}>{msg.text}</Notice></div>}
    </Card>
  );
}

function Prompts() {
  const [list, setList] = useState<Prompt[] | null>(null);
  const [draft, setDraft] = useState<{ system_prompt: string; user_template: string; notes: string } | null>(null);
  const [msg, setMsg] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);
  const load = useCallback(async () => {
    const l = await api<Prompt[]>("/ai/prompts");
    setList(l);
    const a = l.find((p) => p.is_active);
    if (a) setDraft((d) => d ?? { system_prompt: a.system_prompt, user_template: a.user_template, notes: "" });
  }, []);
  useEffect(() => {
    void load();
  }, [load]);

  async function save(e: FormEvent) {
    e.preventDefault();
    setMsg(null);
    try {
      const p = await api<Prompt>("/ai/prompts", { method: "POST", json: draft });
      setMsg({ tone: "ok", text: `Saved as version ${p.version}. New jobs use it; existing content is not regenerated.` });
      await load();
    } catch (err) {
      setMsg({ tone: "bad", text: err instanceof ApiError ? err.message : "Could not save." });
    }
  }

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_280px]">
      <Card title="Prompt template">
        {!draft ? <div className="h-40 animate-pulse rounded-lg bg-ink-50" /> : (
          <form onSubmit={save} className="space-y-4">
            <label className="block text-xs font-semibold text-ink-700">
              System prompt — placeholders: {"{language} {brand_voice} {seo_requirements}"}
              <textarea rows={14} value={draft.system_prompt} onChange={(e) => setDraft({ ...draft, system_prompt: e.target.value })} className="mt-1 w-full rounded-md border border-ink-200 p-2 font-mono text-[12.5px] font-normal" />
            </label>
            <label className="block text-xs font-semibold text-ink-700">
              User template — placeholders: {"{facts} {categories} {description} {product_name}"}
              <textarea rows={8} value={draft.user_template} onChange={(e) => setDraft({ ...draft, user_template: e.target.value })} className="mt-1 w-full rounded-md border border-ink-200 p-2 font-mono text-[12.5px] font-normal" />
            </label>
            <label className="block text-xs font-semibold text-ink-700">
              Note
              <input value={draft.notes} onChange={(e) => setDraft({ ...draft, notes: e.target.value })} className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal" placeholder="What changed" />
            </label>
            <Button type="submit">Save as new version</Button>
            {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
          </form>
        )}
      </Card>
      <Card title="Versions">
        <ul className="divide-y divide-ink-100 text-sm">
          {list?.map((p) => (
            <li key={p.id} className="py-2">
              <div className="flex items-center gap-2"><span className="font-mono">v{p.version}</span>{p.is_active && <Pill tone="ok">active</Pill>}</div>
              <div className="text-xs text-ink-500">{p.notes ?? ""} · {fmtDate(p.created_at)}</div>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}
