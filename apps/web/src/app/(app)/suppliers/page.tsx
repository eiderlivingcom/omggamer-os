"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Button, Card, Empty, Notice, PageHeader, Pill, Stat, cx, fmtDate, relTime } from "@/components/ui";
import { api, ApiError, type ImportFailure, type ImportJob, type ImportLog, type ImportOverview } from "@/lib/api";
import { useSession } from "@/lib/session";

const JOB_STATUS: Record<ImportJob["status"], "ok" | "warn" | "bad" | "info" | "muted"> = {
  queued: "info",
  running: "info",
  paused: "warn",
  completed: "ok",
  failed: "bad",
  cancelled: "muted",
};
const JOB_TYPE: Record<ImportJob["job_type"], string> = {
  full: "Full import",
  incremental: "Update import",
  single: "Single product",
  retry_failed: "Retry failed records",
};
type Conn = { tone: "ok" | "warn" | "bad" | "muted"; label: string };
const NO_KEY: Conn = { tone: "muted", label: "No API key" };
const CONN: Record<string, Conn> = {
  connected: { tone: "ok", label: "API connected" },
  untested: { tone: "warn", label: "Key saved, not tested" },
  failed: { tone: "bad", label: "API connection failed" },
  not_configured: { tone: "muted", label: "No API key" },
};

const n = (v: number | null | undefined) => (v === null || v === undefined ? "—" : v.toLocaleString());

function duration(s: number | null) {
  if (s === null) return "—";
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  return h ? `${h} h ${m} min` : m ? `${m} min ${s % 60} s` : `${s} s`;
}

function label(job: ImportJob) {
  const max = job.params.max_records as number | undefined;
  return max ? `Test import (${max.toLocaleString()} records)` : JOB_TYPE[job.job_type];
}

export default function SuppliersPage() {
  const { can } = useSession();
  const manage = can("suppliers:manage");
  const [ov, setOv] = useState<ImportOverview | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [logs, setLogs] = useState<ImportLog[] | null>(null);
  const [failures, setFailures] = useState<ImportFailure[] | null>(null);
  const [panel, setPanel] = useState<"logs" | "failures" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [singleId, setSingleId] = useState("");

  const load = useCallback(async () => {
    try {
      setOv(await api<ImportOverview>("/imports/overview?supplier=kinguin"));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load imports.");
    }
  }, []);

  const job = useMemo(() => {
    if (!ov) return null;
    return ov.recent_jobs.find((j) => j.id === selected) ?? ov.active_job ?? ov.recent_jobs[0] ?? null;
  }, [ov, selected]);
  const live = !!ov?.active_job;

  useEffect(() => {
    void load();
    const t = setInterval(load, live ? 3000 : 15000);
    return () => clearInterval(t);
  }, [load, live]);

  useEffect(() => {
    if (!job || !panel) return;
    const path = panel === "logs" ? `/imports/${job.id}/logs?limit=200` : `/imports/${job.id}/failures?limit=200`;
    let stop = false;
    const fetchPanel = async () => {
      try {
        const data = await api<ImportLog[] | ImportFailure[]>(path);
        if (stop) return;
        if (panel === "logs") setLogs(data as ImportLog[]);
        else setFailures(data as ImportFailure[]);
      } catch {
        /* keep the last good data */
      }
    };
    void fetchPanel();
    const t = setInterval(fetchPanel, ["running", "queued"].includes(job.status) ? 4000 : 30000);
    return () => {
      stop = true;
      clearInterval(t);
    };
  }, [job, panel]);

  async function act(fn: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "That did not work.");
    } finally {
      setBusy(false);
    }
  }

  const start = (body: Record<string, unknown>, confirmText?: string) => {
    if (confirmText && !window.confirm(confirmText)) return;
    void act(async () => {
      const j = await api<ImportJob>("/imports", { method: "POST", json: { supplier: "kinguin", ...body } });
      setSelected(j.id);
    });
  };
  const transition = (verb: "pause" | "resume" | "cancel") =>
    act(() => api(`/imports/${job!.id}/${verb}`, { method: "POST" }));

  const conn = CONN[ov?.connection_status ?? "not_configured"] ?? NO_KEY;
  const ready = ov?.connection_status === "connected" || ov?.connection_status === "untested";
  const pct = job && job.total_records ? Math.min(100, Math.round((job.processed_records / job.total_records) * 100)) : null;

  return (
    <>
      <PageHeader
        title="Suppliers"
        description="Kinguin catalogue import. Imports run on the server in the background: you can close this page and they keep going."
        actions={ov && <Pill tone={conn.tone}>{conn.label} · {ov.active_environment === "live" ? "production" : "sandbox"}</Pill>}
      />
      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {ov && !ready && (
        <div className="mb-6">
          <Notice>
            Add the Kinguin API key under{" "}
            <Link className="font-semibold underline" href="/settings/credentials">Settings → API credentials</Link> to start importing.
          </Notice>
        </div>
      )}

      <div className="mb-6 grid grid-cols-2 gap-x-6 gap-y-5 rounded-xl border border-ink-100 bg-white p-5 shadow-sm sm:grid-cols-3 lg:grid-cols-5">
        <Stat label="Kinguin records" value={n(ov?.totals.supplier_products)} hint="stored in OMGGamer OS" />
        <Stat label="Product variants" value={n(ov?.totals.variants)} />
        <Stat label="Offers" value={n(ov?.totals.offers)} hint={ov ? `${ov.totals.offers_in_stock.toLocaleString()} in stock` : undefined} />
        <Stat label="Failed records" value={n(ov?.totals.unresolved_failures)} tone={ov?.totals.unresolved_failures ? "warn" : undefined} hint="waiting for retry" />
        <Stat label="Last import" value={ov?.recent_jobs[0] ? relTime(ov.recent_jobs[0].created_at) : "—"} />
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div className="min-w-0 space-y-6">
          <Card
            title={job ? label(job) : "Import"}
            aside={job && <Pill tone={JOB_STATUS[job.status]}>{job.status}</Pill>}
          >
            {!ov ? (
              <div className="h-40 animate-pulse rounded-lg bg-ink-50" />
            ) : !job ? (
              <Empty title="No imports yet">Start with a 100-record test import, then 1,000, then a larger batch before the full catalogue.</Empty>
            ) : (
              <>
                <div className="mb-5">
                  <div className="mb-1.5 flex justify-between text-xs text-ink-500">
                    <span>
                      {n(job.processed_records)} of {n(job.total_records)} records
                    </span>
                    <span>{pct === null ? "" : `${pct}%`}</span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-ink-100">
                    <div
                      className={cx("h-full rounded-full transition-all", job.status === "failed" ? "bg-rose-500" : "bg-brand-600")}
                      style={{ width: `${pct ?? 0}%` }}
                    />
                  </div>
                </div>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-4 text-sm sm:grid-cols-3 lg:grid-cols-4">
                  {[
                    ["Discovered", n(job.total_records)],
                    ["Saved", n(job.saved_records)],
                    ["New", n(job.created_records)],
                    ["Updated", n(job.updated_records)],
                    ["Unchanged / skipped", n(job.skipped_records)],
                    ["Failed", n(job.failed_records)],
                    ["Current page", job.current_page === null ? "—" : n(job.current_page)],
                    ["API requests", n(job.api_requests)],
                    ["Duration", duration(job.duration_seconds)],
                    ["Last checkpoint", fmtDate(job.last_checkpoint_at)],
                    ["Last API response", fmtDate(job.last_response_at)],
                    ["Started", fmtDate(job.started_at)],
                  ].map(([k, v]) => (
                    <div key={k}>
                      <dt className="text-xs text-ink-500">{k}</dt>
                      <dd className="mt-0.5 font-mono font-semibold tabular-nums text-ink-900">{v}</dd>
                    </div>
                  ))}
                </dl>
                {job.last_error && (
                  <div className="mt-4">
                    <Notice tone={job.status === "failed" ? "bad" : "info"}>{job.last_error}</Notice>
                  </div>
                )}
                <div className="mt-5 flex flex-wrap gap-2 border-t border-ink-100 pt-4">
                  {manage && ["queued", "running"].includes(job.status) && (
                    <Button variant="ghost" disabled={busy} onClick={() => transition("pause")}>Pause</Button>
                  )}
                  {manage && ["paused", "failed"].includes(job.status) && (
                    <Button disabled={busy} onClick={() => transition("resume")}>Resume</Button>
                  )}
                  {manage && ["queued", "running", "paused"].includes(job.status) && (
                    <Button
                      variant="danger"
                      disabled={busy}
                      onClick={() => window.confirm("Cancel this import? Saved records stay; a new import starts from the beginning.") && transition("cancel")}
                    >
                      Cancel
                    </Button>
                  )}
                  {manage && (ov.totals.unresolved_failures > 0) && (
                    <Button variant="ghost" disabled={busy} onClick={() => start({ job_type: "retry_failed" })}>
                      Retry failed records ({ov.totals.unresolved_failures.toLocaleString()})
                    </Button>
                  )}
                  <Button variant="ghost" onClick={() => setPanel(panel === "logs" ? null : "logs")}>
                    {panel === "logs" ? "Hide logs" : "View logs"}
                  </Button>
                  {job.failed_records > 0 && (
                    <Button variant="ghost" onClick={() => setPanel(panel === "failures" ? null : "failures")}>
                      {panel === "failures" ? "Hide failed records" : "Failed records"}
                    </Button>
                  )}
                </div>
              </>
            )}
          </Card>

          {job && panel === "logs" && (
            <Card title="Import log" aside={<span className="text-xs text-ink-500">newest first</span>}>
              {!logs ? (
                <div className="h-24 animate-pulse rounded-lg bg-ink-50" />
              ) : logs.length === 0 ? (
                <Empty title="No log entries yet" />
              ) : (
                <ol className="max-h-[420px] divide-y divide-ink-100 overflow-y-auto font-mono text-[12.5px]">
                  {logs.map((l) => (
                    <li key={l.id} className="flex gap-3 py-1.5">
                      <span className="shrink-0 text-ink-400">{new Date(l.created_at).toLocaleTimeString()}</span>
                      <span className={cx(l.level === "error" && "text-rose-700", l.level === "warning" && "text-amber-700", l.level === "info" && "text-ink-800")}>
                        {l.message}
                      </span>
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          )}

          {job && panel === "failures" && (
            <Card title="Failed records" aside={<span className="text-xs text-ink-500">Kinguin product ID · error</span>}>
              {!failures ? (
                <div className="h-24 animate-pulse rounded-lg bg-ink-50" />
              ) : failures.length === 0 ? (
                <Empty title="No unresolved failures for this import" />
              ) : (
                <ul className="max-h-[420px] divide-y divide-ink-100 overflow-y-auto text-sm">
                  {failures.map((f) => (
                    <li key={f.id} className="py-2">
                      <div className="font-mono font-semibold text-ink-900">
                        {f.external_product_id} <span className="font-sans text-xs font-normal text-ink-500">· {f.attempts} attempt{f.attempts === 1 ? "" : "s"}</span>
                      </div>
                      <div className="mt-0.5 break-words text-xs text-rose-700">{f.error}</div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}

          <Card title="Import history">
            {!ov ? (
              <div className="h-24 animate-pulse rounded-lg bg-ink-50" />
            ) : ov.recent_jobs.length === 0 ? (
              <Empty title="Nothing imported yet" />
            ) : (
              <div className="-mx-5 overflow-x-auto">
                <table className="w-full min-w-[620px] text-sm">
                  <thead>
                    <tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                      <th className="px-5 py-2">Import</th>
                      <th className="px-3 py-2">Status</th>
                      <th className="px-3 py-2 text-right">Records</th>
                      <th className="px-3 py-2 text-right">Failed</th>
                      <th className="px-3 py-2">Started</th>
                      <th className="px-5 py-2 text-right">Duration</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ink-100">
                    {ov.recent_jobs.map((j) => (
                      <tr
                        key={j.id}
                        onClick={() => setSelected(j.id)}
                        className={cx("cursor-pointer hover:bg-ink-50", job?.id === j.id && "bg-brand-50/50")}
                      >
                        <td className="px-5 py-2.5 font-medium text-ink-900">{label(j)}</td>
                        <td className="px-3 py-2.5"><Pill tone={JOB_STATUS[j.status]}>{j.status}</Pill></td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums">{n(j.processed_records)}</td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums">{n(j.failed_records)}</td>
                        <td className="px-3 py-2.5 text-ink-500">{fmtDate(j.started_at ?? j.created_at)}</td>
                        <td className="px-5 py-2.5 text-right text-ink-500">{duration(j.duration_seconds)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </div>

        <div className="space-y-6">
          {manage && (
            <Card title="Start an import">
              <p className="mb-4 text-sm text-ink-500">
                Run the test sizes first. Imports save original Kinguin data only: nothing is published and no AI content is generated.
              </p>
              <div className="space-y-2">
                {[100, 1000, 10000].map((m) => (
                  <Button key={m} variant="ghost" className="w-full justify-between" disabled={!ready || busy || live} onClick={() => start({ job_type: "full", max_records: m })}>
                    <span>Test import</span>
                    <span className="font-mono text-ink-500">{m.toLocaleString()} records</span>
                  </Button>
                ))}
                <Button
                  className="w-full"
                  disabled={!ready || busy || live}
                  onClick={() => start({ job_type: "full" }, "Start the full Kinguin catalogue import? It can take several hours and runs in the background.")}
                >
                  Start full import
                </Button>
                <Button variant="ghost" className="w-full" disabled={!ready || busy || live} onClick={() => start({ job_type: "incremental" })}>
                  Import updates since last run
                </Button>
              </div>
              <form
                className="mt-5 border-t border-ink-100 pt-4"
                onSubmit={(e) => {
                  e.preventDefault();
                  start({ job_type: "single", external_id: singleId });
                  setSingleId("");
                }}
              >
                <label className="mb-1 block text-xs font-semibold text-ink-700" htmlFor="kid">Import one product</label>
                <div className="flex gap-2">
                  <input
                    id="kid"
                    inputMode="numeric"
                    pattern="[0-9]+"
                    placeholder="Kinguin ID, e.g. 230507"
                    value={singleId}
                    onChange={(e) => setSingleId(e.target.value)}
                    className="h-9 min-w-0 flex-1 rounded-lg border border-ink-200 px-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                  />
                  <Button type="submit" variant="ghost" disabled={!ready || busy || !singleId}>Import</Button>
                </div>
              </form>
              {live && <p className="mt-3 text-xs text-ink-500">An import is running. Pause or cancel it to start another.</p>}
            </Card>
          )}
          <Card title="How the import works">
            <ul className="list-disc space-y-2 pl-4 text-sm text-ink-700">
              <li>Kinguin is read 100 products per page (the API maximum). Each page is saved with a checkpoint.</li>
              <li>If the server restarts, the import continues from the last checkpoint on its own.</li>
              <li>Rate limits and server errors are retried with increasing waits.</li>
              <li>Records that cannot be saved are kept with the original data for “Retry failed records”.</li>
              <li>After a full import, an update import picks up products changed while it ran.</li>
            </ul>
          </Card>
        </div>
      </div>
    </>
  );
}
