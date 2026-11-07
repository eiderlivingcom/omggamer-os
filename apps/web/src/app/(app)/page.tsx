"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Card, Empty, Notice, PageHeader, Pill, Stat, fmtDate, relTime } from "@/components/ui";
import { api, type AuditEntry, type Health, type Summary } from "@/lib/api";

const STATUS_ORDER = ["draft", "review", "approved", "published", "archived"] as const;
const n = (v: number) => v.toLocaleString();

export default function DashboardPage() {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [health, setHealth] = useState<Health | null>(null);
  const [activity, setActivity] = useState<AuditEntry[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    const load = async () => {
      try {
        const [s, h, a] = await Promise.all([
          api<Summary>("/dashboard/summary"),
          api<Health>("/system/health"),
          api<AuditEntry[]>("/dashboard/activity"),
        ]);
        if (!alive) return;
        setSummary(s);
        setHealth(h);
        setActivity(a);
        setError(null);
      } catch (e) {
        if (alive) setError(e instanceof Error ? e.message : "Could not load the dashboard.");
      }
    };
    void load();
    const t = setInterval(load, 30000);
    return () => {
      alive = false;
      clearInterval(t);
    };
  }, []);

  if (error && !summary) return <Notice tone="bad">{error}</Notice>;
  if (!summary || !health || !activity) return <div className="h-64 animate-pulse rounded-xl bg-white" />;

  const s = summary;
  const total = s.catalogue.products;

  return (
    <>
      <PageHeader
        title="Dashboard"
        description="Live numbers from the OMGGamer OS database. Everything starts at zero until the first Kinguin import runs."
        actions={<Pill tone={health.ok ? "ok" : "warn"}>{health.ok ? "All systems working" : "Needs attention"}</Pill>}
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card>
          <Stat label="Products" value={n(total)} hint={`${n(s.catalogue.variants)} variants`} />
        </Card>
        <Card>
          <Stat label="Supplier products" value={n(s.suppliers.supplier_products)} hint={`${n(s.suppliers.active_offers)} active offers`} />
        </Card>
        <Card>
          <Stat label="Published listings" value={n(s.publishing.published_listings)} hint={`${n(s.publishing.pending_listings)} waiting to sync`} />
        </Card>
        <Card>
          <Stat
            label="Failed work"
            value={n(s.dead_letters_open + s.imports.failed + s.publishing.sync_failures)}
            tone={s.dead_letters_open + s.imports.failed + s.publishing.sync_failures ? "bad" : undefined}
            hint="Dead letters, failed imports and syncs"
          />
        </Card>
      </div>

      <div className="mt-4 grid gap-4 xl:grid-cols-3">
        <Card title="Catalogue by status" className="xl:col-span-2">
          {total === 0 ? (
            <Empty title="No products yet">Products appear here after the first Kinguin import (Suppliers → Kinguin).</Empty>
          ) : (
            <>
              <div className="flex h-3 overflow-hidden rounded-full bg-ink-100" role="img" aria-label="Products by status">
                {STATUS_ORDER.map((k, i) => {
                  const v = s.catalogue.by_status[k] ?? 0;
                  return v ? <div key={k} style={{ width: `${(v / total) * 100}%` }} className={["bg-ink-300", "bg-amber-400", "bg-sky-500", "bg-emerald-500", "bg-ink-500"][i]} /> : null;
                })}
              </div>
              <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                {STATUS_ORDER.map((k) => (
                  <div key={k}>
                    <dt className="text-xs capitalize text-ink-500">{k}</dt>
                    <dd className="font-mono text-lg font-semibold tabular-nums">{n(s.catalogue.by_status[k] ?? 0)}</dd>
                  </div>
                ))}
              </dl>
            </>
          )}
        </Card>

        <Card title="System health" aside={<span className="font-mono text-[11px] text-ink-500">v{health.version} · schema {health.migration ?? "?"}</span>}>
          <ul className="space-y-2.5">
            {health.checks.map((c) => (
              <li key={c.name} className="flex items-start justify-between gap-3">
                <span className="min-w-0">
                  <span className="block text-sm font-medium text-ink-900">{c.name}</span>
                  <span className="block text-xs text-ink-500">{c.detail}</span>
                </span>
                <Pill tone={c.ok ? "ok" : "bad"}>{c.ok ? "OK" : "Down"}</Pill>
              </li>
            ))}
          </ul>
        </Card>
      </div>

      <div className="mt-4 grid gap-4 md:grid-cols-3">
        <Card title="Suppliers" aside={<Link className="text-xs font-semibold text-brand-600" href="/suppliers">Open</Link>}>
          <div className="grid grid-cols-2 gap-4">
            <Stat label="Suppliers" value={s.suppliers.suppliers} />
            <Stat label="Connected" value={s.suppliers.connected} tone={s.suppliers.connected ? undefined : "warn"} hint={s.suppliers.connected ? undefined : "Kinguin key not added yet"} />
          </div>
        </Card>
        <Card title="Imports">
          <div className="grid grid-cols-3 gap-4">
            <Stat label="Running" value={s.imports.running} />
            <Stat label="Queued" value={s.imports.queued} />
            <Stat label="Failed" value={s.imports.failed} tone={s.imports.failed ? "bad" : undefined} />
          </div>
          <p className="mt-3 text-xs text-ink-500">Last finished: {fmtDate(s.imports.last_finished_at)}</p>
        </Card>
        <Card title="AI processing">
          <div className="grid grid-cols-3 gap-4">
            <Stat label="Queued" value={s.ai.queued} />
            <Stat label="Done" value={s.ai.succeeded} />
            <Stat label="Cost 30d" value={`$${Number(s.ai.cost_usd_30d).toFixed(2)}`} />
          </div>
        </Card>
      </div>

      <Card title="Recent activity" className="mt-4" aside={<Link className="text-xs font-semibold text-brand-600" href="/settings/audit">Audit log</Link>}>
        {activity.length === 0 ? (
          <Empty title="No activity yet" />
        ) : (
          <ul className="divide-y divide-ink-100">
            {activity.map((a) => (
              <li key={a.id} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2.5 first:pt-0 last:pb-0">
                <span className="min-w-0 text-sm text-ink-900">
                  <span className="font-medium">{a.actor_email ?? "System"}</span> <span className="text-ink-500">· {a.summary || a.action}</span>
                </span>
                <time className="text-xs text-ink-500" dateTime={a.created_at} title={fmtDate(a.created_at)}>
                  {relTime(a.created_at)}
                </time>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  );
}
