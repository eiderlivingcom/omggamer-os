"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Button, Card, Notice, PageHeader, Pill, fmtDate } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useSession } from "@/lib/session";

type SyncSettings = {
  enabled: boolean;
  interval_minutes: number;
  cron: string | null;
  full_check_hour: number;
  strict_checkout: boolean;
  last_run_at?: string;
  last_push_at?: string;
  last_full_at?: string;
};
type SyncOverview = {
  settings: SyncSettings;
  pending_changes: number;
  webhooks: Record<string, number>;
  kinguin_webhook_configured: boolean;
  kinguin_webhook_url: string;
  recent: { storefront: string; status: string; response: Record<string, unknown>; created_at: string }[];
};

const INTERVALS = [5, 15, 30, 60, 120, 360, 720, 1440];

type Rules = {
  full_import: { enabled: boolean; weekday: number; hour: number };
  ai_daily: { enabled: boolean; hour: number; limit: number };
  auto_approve: { enabled: boolean; product_types: string[] };
  auto_retry: { enabled: boolean; max: number };
  notify: { enabled: boolean; webhook_url: string };
  last_runs?: Record<string, string>;
};
const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
const TYPES = ["game", "dlc", "software", "gift_card", "game_account", "subscription"];

function RulesCard() {
  const [r, setR] = useState<Rules | null>(null);
  const [msg, setMsg] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);
  useEffect(() => {
    api<Rules>("/automation/settings").then(setR).catch(() => undefined);
  }, []);
  if (!r) return null;
  const input = "h-8 rounded-md border border-ink-200 bg-white px-2 text-sm";
  async function save() {
    setMsg(null);
    try {
      const { last_runs, ...body } = r!;
      void last_runs;
      setR(await api<Rules>("/automation/settings", { method: "PUT", json: body }));
      setMsg({ tone: "ok", text: "Automation rules saved." });
    } catch (e) {
      setMsg({ tone: "bad", text: e instanceof ApiError ? e.message : "Could not save." });
    }
  }
  async function test() {
    const t = await api<{ ok: boolean; message: string }>("/automation/test-notification", { method: "POST" }).catch((e) => ({ ok: false, message: e instanceof Error ? e.message : "Failed" }));
    setMsg({ tone: t.ok ? "ok" : "bad", text: t.ok ? "Test alert sent." : t.message });
  }
  return (
    <Card title="Automation rules">
      <div className="space-y-4 text-sm">
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex items-center gap-2 font-medium"><input type="checkbox" checked={r.full_import.enabled} onChange={(e) => setR({ ...r, full_import: { ...r.full_import, enabled: e.target.checked } })} /> Full Kinguin import every</label>
          <select className={input} value={r.full_import.weekday} onChange={(e) => setR({ ...r, full_import: { ...r.full_import, weekday: Number(e.target.value) } })}>{DAYS.map((d, i) => <option key={d} value={i}>{d}</option>)}</select>
          at <input type="number" min={0} max={23} className={`${input} w-16`} value={r.full_import.hour} onChange={(e) => setR({ ...r, full_import: { ...r.full_import, hour: Number(e.target.value) } })} />:00 UTC
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex items-center gap-2 font-medium"><input type="checkbox" checked={r.ai_daily.enabled} onChange={(e) => setR({ ...r, ai_daily: { ...r.ai_daily, enabled: e.target.checked } })} /> Generate AI content daily for up to</label>
          <input type="number" min={1} max={5000} className={`${input} w-20`} value={r.ai_daily.limit} onChange={(e) => setR({ ...r, ai_daily: { ...r.ai_daily, limit: Number(e.target.value) } })} /> products at
          <input type="number" min={0} max={23} className={`${input} w-16`} value={r.ai_daily.hour} onChange={(e) => setR({ ...r, ai_daily: { ...r.ai_daily, hour: Number(e.target.value) } })} />:00 UTC
        </div>
        <div>
          <label className="flex items-center gap-2 font-medium"><input type="checkbox" checked={r.auto_approve.enabled} onChange={(e) => setR({ ...r, auto_approve: { ...r.auto_approve, enabled: e.target.checked } })} /> Approve AI content automatically when nothing was flagged</label>
          <div className="ml-6 mt-1 flex flex-wrap gap-3 text-xs text-ink-600">
            Only for:
            {TYPES.map((t) => (
              <label key={t} className="flex items-center gap-1"><input type="checkbox" checked={r.auto_approve.product_types.includes(t)} onChange={(e) => setR({ ...r, auto_approve: { ...r.auto_approve, product_types: e.target.checked ? [...r.auto_approve.product_types, t] : r.auto_approve.product_types.filter((x) => x !== t) } })} />{t.replace("_", " ")}</label>
            ))}
            <span>(none ticked = all types). Publishing stays manual.</span>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex items-center gap-2 font-medium"><input type="checkbox" checked={r.auto_retry.enabled} onChange={(e) => setR({ ...r, auto_retry: { ...r.auto_retry, enabled: e.target.checked } })} /> Retry failed AI and publishing jobs hourly, up to</label>
          <input type="number" min={1} max={10} className={`${input} w-16`} value={r.auto_retry.max} onChange={(e) => setR({ ...r, auto_retry: { ...r.auto_retry, max: Number(e.target.value) } })} /> times
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex items-center gap-2 font-medium"><input type="checkbox" checked={r.notify.enabled} onChange={(e) => setR({ ...r, notify: { ...r.notify, enabled: e.target.checked } })} /> Send error alerts to</label>
          <input type="url" placeholder="https://hooks.slack.com/… or Discord webhook" className={`${input} min-w-0 flex-1`} value={r.notify.webhook_url} onChange={(e) => setR({ ...r, notify: { ...r.notify, webhook_url: e.target.value } })} />
          <Button variant="ghost" className="h-8" onClick={test}>Send test</Button>
        </div>
        <Button onClick={save}>Save rules</Button>
        {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
        {r.last_runs && Object.keys(r.last_runs).length > 0 && (
          <p className="text-xs text-ink-500">Last runs: {Object.entries(r.last_runs).map(([k, v]) => `${k.replace("_", " ")} ${fmtDate(v)}`).join(" · ")}</p>
        )}
      </div>
    </Card>
  );
}

export default function AutomationPage() {
  const { can } = useSession();
  const manage = can("automation:manage");
  const [ov, setOv] = useState<SyncOverview | null>(null);
  const [form, setForm] = useState<SyncSettings | null>(null);
  const [mode, setMode] = useState<"interval" | "cron">("interval");
  const [secret, setSecret] = useState<{ secret: string; url: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const o = await api<SyncOverview>("/sync/overview");
      setOv(o);
      setForm((f) => f ?? o.settings);
      setMode(o.settings.cron ? "cron" : "interval");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load automation.");
    }
  }, []);
  useEffect(() => {
    void load();
  }, [load]);

  async function act(fn: () => Promise<unknown>, ok: string) {
    setError(null);
    setNotice(null);
    try {
      await fn();
      setNotice(ok);
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "That did not work.");
    }
  }

  function save(e: FormEvent) {
    e.preventDefault();
    if (!form) return;
    void act(
      () =>
        api("/sync/settings", {
          method: "PUT",
          json: {
            enabled: form.enabled,
            interval_minutes: form.interval_minutes,
            cron: mode === "cron" ? form.cron : "",
            full_check_hour: form.full_check_hour,
            strict_checkout: form.strict_checkout,
          },
        }),
      "Schedule saved.",
    );
  }

  return (
    <>
      <PageHeader
        title="Automation"
        description="Price, stock and offer synchronisation. It never reruns AI content: only supplier offers, selling prices and stock change."
      />
      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {notice && <div className="mb-4"><Notice tone="ok">{notice}</Notice></div>}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div className="min-w-0 space-y-6">
          <Card title="Price and stock synchronisation" aside={ov && <Pill tone={ov.settings.enabled ? "ok" : "muted"}>{ov.settings.enabled ? "On" : "Off"}</Pill>}>
            {!form ? <div className="h-32 animate-pulse rounded-lg bg-ink-50" /> : (
              <form onSubmit={save} className="space-y-4">
                <label className="flex items-center gap-2 text-sm font-medium text-ink-900">
                  <input type="checkbox" disabled={!manage} checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} />
                  Refresh Kinguin offers and push changed prices and stock automatically
                </label>
                <div className="flex flex-wrap items-end gap-4">
                  <label className="text-xs font-semibold text-ink-700">
                    Schedule
                    <select disabled={!manage} className="mt-1 block h-9 rounded-md border border-ink-200 bg-white px-2 text-sm font-normal" value={mode} onChange={(e) => setMode(e.target.value as "interval" | "cron")}>
                      <option value="interval">Every…</option>
                      <option value="cron">Custom (cron)</option>
                    </select>
                  </label>
                  {mode === "interval" ? (
                    <label className="text-xs font-semibold text-ink-700">
                      Interval
                      <select disabled={!manage} className="mt-1 block h-9 rounded-md border border-ink-200 bg-white px-2 text-sm font-normal" value={form.interval_minutes} onChange={(e) => setForm({ ...form, interval_minutes: Number(e.target.value) })}>
                        {INTERVALS.map((m) => <option key={m} value={m}>{m < 60 ? `${m} minutes` : m === 60 ? "Hourly" : `${m / 60} hours`}</option>)}
                      </select>
                    </label>
                  ) : (
                    <label className="text-xs font-semibold text-ink-700">
                      Cron (UTC)
                      <input disabled={!manage} className="mt-1 block h-9 w-44 rounded-md border border-ink-200 px-2 font-mono text-sm font-normal" placeholder="*/10 * * * *" value={form.cron ?? ""} onChange={(e) => setForm({ ...form, cron: e.target.value })} />
                    </label>
                  )}
                  <label className="text-xs font-semibold text-ink-700">
                    Daily full check (UTC hour)
                    <input type="number" min={0} max={23} disabled={!manage} className="mt-1 block h-9 w-24 rounded-md border border-ink-200 px-2 text-sm font-normal" value={form.full_check_hour} onChange={(e) => setForm({ ...form, full_check_hour: Number(e.target.value) })} />
                  </label>
                </div>
                {manage && <Button type="submit">Save schedule</Button>}
              </form>
            )}
            {ov && (
              <dl className="mt-5 grid gap-3 border-t border-ink-100 pt-4 text-sm sm:grid-cols-3">
                <div><dt className="text-xs text-ink-500">Last offer refresh</dt><dd>{fmtDate(ov.settings.last_run_at)}</dd></div>
                <div><dt className="text-xs text-ink-500">Last price push</dt><dd>{fmtDate(ov.settings.last_push_at)}</dd></div>
                <div><dt className="text-xs text-ink-500">Changes waiting to push</dt><dd className="font-mono">{ov.pending_changes}</dd></div>
              </dl>
            )}
            <div className="mt-4 flex flex-wrap gap-2">
              {manage && <Button variant="ghost" onClick={() => act(() => api("/sync/run-now", { method: "POST" }), "Offer refresh started.")}>Refresh offers now</Button>}
              {can("publishing:manage") && <Button variant="ghost" onClick={() => act(() => api("/sync/push", { method: "POST" }), "Price push started.")}>Push price changes now</Button>}
              {can("publishing:manage") && <Button variant="ghost" onClick={() => act(() => api("/sync/push?full=true", { method: "POST" }), "Full comparison started.")}>Full price and stock check</Button>}
            </div>
          </Card>

          {manage && <RulesCard />}

          <Card title="Recent price pushes">
            {!ov || ov.recent.length === 0 ? <p className="text-sm text-ink-500">No pushes yet. Prices are only pushed for products already published to a store.</p> : (
              <ul className="divide-y divide-ink-100 text-sm">
                {ov.recent.map((r, i) => (
                  <li key={i} className="flex flex-wrap items-center gap-3 py-2">
                    <span className="w-36 text-xs text-ink-500">{fmtDate(r.created_at)}</span>
                    <Pill tone={r.status === "success" ? "ok" : "bad"}>{r.status}</Pill>
                    <span>{String(r.response.ok ?? 0)} of {String(r.response.items ?? 0)} variants → {r.storefront}</span>
                    {typeof r.response.error === "string" && <span className="w-full text-xs text-rose-700">{r.response.error}</span>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        <div className="space-y-6">
          <Card title="Kinguin webhook" aside={ov && <Pill tone={ov.kinguin_webhook_configured ? "ok" : "muted"}>{ov.kinguin_webhook_configured ? "Set" : "Not set"}</Pill>}>
            <p className="text-sm text-ink-700">Kinguin can notify OMGGamer OS the moment a product’s price or stock changes, so it is refreshed straight away.</p>
            <p className="mt-2 break-all font-mono text-xs text-ink-600">{ov?.kinguin_webhook_url}</p>
            {secret && (
              <div className="mt-3"><Notice tone="ok">Secret (shown once): <code className="select-all break-all font-mono">{secret.secret}</code><br />In Kinguin → My Stores → Webhooks, add product.update with this URL and secret.</Notice></div>
            )}
            {can("credentials:manage") && (
              <Button variant="ghost" className="mt-3" onClick={() => act(async () => setSecret(await api<{ secret: string; url: string }>("/suppliers/kinguin/webhook-secret", { method: "POST" })), "Webhook secret generated.")}>
                {ov?.kinguin_webhook_configured ? "New webhook secret" : "Generate webhook secret"}
              </Button>
            )}
            {ov && Object.keys(ov.webhooks).length > 0 && (
              <p className="mt-3 text-xs text-ink-500">Received: {Object.entries(ov.webhooks).map(([k, v]) => `${v} ${k}`).join(", ")}</p>
            )}
          </Card>
          <Card title="How it works">
            <ul className="list-disc space-y-2 pl-4 text-sm text-ink-700">
              <li>Each run imports only products Kinguin changed since the last run (“updated since”).</li>
              <li>Selling prices are recalculated from the cheapest available offer. If it sells out, the next cheapest offer takes over.</li>
              <li>Only prices and stock that differ from the store are sent.</li>
              <li>At checkout the store asks OMGGamer OS to confirm each item live with Kinguin.</li>
            </ul>
          </Card>
        </div>
      </div>
    </>
  );
}
