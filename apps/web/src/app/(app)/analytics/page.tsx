"use client";

import { useEffect, useState } from "react";
import { money } from "@/components/status";
import { Card, Empty, Notice, PageHeader, Stat } from "@/components/ui";
import { api } from "@/lib/api";

type Summary = {
  days: number;
  catalogue: { products: number; variants: number; offers: number; offers_available: number; by_status: Record<string, number>; by_supplier: { supplier: string; products: number; records: number }[]; by_category: { category: string; products: number }[] };
  ai: { products_with_content: number; completion_pct: number; approved_pct: number; by_status: Record<string, number>; jobs: Record<string, number>; cost_usd: string };
  publishing: { sync_success: number; sync_failed: number; success_rate_pct: number; price_push_failures: number };
  stock: { variants_priced: number; variants_unavailable: number; availability_pct: number; offers_available_pct: number };
  suppliers: { imports: { supplier: string; jobs: number; completed: number; failed: number; records_failed: number; api_requests: number; last_import: string | null }[]; purchases: Record<string, number>; purchase_success_pct: number };
  sales: { orders: number; by_status: Record<string, number>; fulfilled_pct: number; units_delivered: number; revenue: string; supplier_cost: string; gross_margin: string; avg_fulfilment_seconds: number | null; daily: { date: string; orders: number; revenue: string }[] };
};

function Bars({ rows }: { rows: { label: string; value: number }[] }) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  if (!rows.length) return <p className="text-sm text-ink-500">No data yet.</p>;
  return (
    <ul className="space-y-2">
      {rows.map((r) => (
        <li key={r.label} className="text-sm">
          <div className="mb-0.5 flex justify-between gap-3"><span className="truncate text-ink-800">{r.label}</span><span className="font-mono tabular-nums text-ink-600">{r.value.toLocaleString()}</span></div>
          <div className="h-1.5 rounded-full bg-ink-100"><div className="h-full rounded-full bg-brand-600" style={{ width: `${(100 * r.value) / max}%` }} /></div>
        </li>
      ))}
    </ul>
  );
}

const dur = (s: number | null) => (s === null ? "—" : s < 120 ? `${s} s` : s < 7200 ? `${Math.round(s / 60)} min` : `${(s / 3600).toFixed(1)} h`);

export default function AnalyticsPage() {
  const [days, setDays] = useState(30);
  const [a, setA] = useState<Summary | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    setA(null);
    api<Summary>(`/analytics/summary?days=${days}`).then(setA).catch((e) => setError(e instanceof Error ? e.message : "Could not load analytics."));
  }, [days]);

  return (
    <>
      <PageHeader
        title="Analytics"
        description="Live figures from the OMGGamer OS database. Empty sections mean there is no activity yet."
        actions={
          <select value={days} onChange={(e) => setDays(Number(e.target.value))} aria-label="Period" className="h-9 rounded-md border border-ink-200 bg-white px-2 text-sm">
            {[7, 30, 90, 365].map((d) => <option key={d} value={d}>Last {d} days</option>)}
          </select>
        }
      />
      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {!a ? <div className="h-64 animate-pulse rounded-xl bg-ink-50" /> : (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-x-6 gap-y-5 rounded-xl border border-ink-100 bg-white p-5 shadow-sm sm:grid-cols-3 lg:grid-cols-6">
            <Stat label="Master products" value={a.catalogue.products.toLocaleString()} />
            <Stat label="Variants" value={a.catalogue.variants.toLocaleString()} />
            <Stat label="Supplier offers" value={a.catalogue.offers.toLocaleString()} hint={`${a.stock.offers_available_pct}% available`} />
            <Stat label="AI completion" value={`${a.ai.completion_pct}%`} hint={`${a.ai.approved_pct}% approved`} />
            <Stat label="Publishing success" value={`${a.publishing.success_rate_pct}%`} hint={`${a.publishing.sync_failed} failed`} tone={a.publishing.sync_failed ? "warn" : undefined} />
            <Stat label="Variants in stock" value={`${a.stock.availability_pct}%`} hint={`${a.stock.variants_priced.toLocaleString()} priced`} />
          </div>

          <div className="grid gap-6 lg:grid-cols-3">
            <Card title="Products by supplier"><Bars rows={a.catalogue.by_supplier.map((s) => ({ label: s.supplier, value: s.products }))} /></Card>
            <Card title="Products by category"><Bars rows={a.catalogue.by_category.map((c) => ({ label: c.category, value: c.products }))} /></Card>
            <Card title="Products by status"><Bars rows={Object.entries(a.catalogue.by_status).map(([k, v]) => ({ label: k, value: v }))} /></Card>
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card title="AI content">
              <dl className="mb-4 grid grid-cols-3 gap-4 text-sm">
                <div><dt className="text-xs text-ink-500">With content</dt><dd className="font-mono text-lg">{a.ai.products_with_content.toLocaleString()}</dd></div>
                <div><dt className="text-xs text-ink-500">AI cost</dt><dd className="font-mono text-lg">${Number(a.ai.cost_usd).toFixed(2)}</dd></div>
                <div><dt className="text-xs text-ink-500">Failed jobs</dt><dd className="font-mono text-lg">{a.ai.jobs.failed ?? 0}</dd></div>
              </dl>
              <Bars rows={Object.entries(a.ai.by_status).map(([k, v]) => ({ label: k.replace("_", " "), value: v }))} />
            </Card>
            <Card title="Sales and fulfilment">
              <dl className="mb-4 grid grid-cols-3 gap-4 text-sm">
                <div><dt className="text-xs text-ink-500">Orders</dt><dd className="font-mono text-lg">{a.sales.orders}</dd></div>
                <div><dt className="text-xs text-ink-500">Revenue</dt><dd className="font-mono text-lg">{money(a.sales.revenue)}</dd></div>
                <div><dt className="text-xs text-ink-500">Supplier cost</dt><dd className="font-mono text-lg">{money(a.sales.supplier_cost)}</dd></div>
                <div><dt className="text-xs text-ink-500">Gross margin</dt><dd className="font-mono text-lg">{money(a.sales.gross_margin)}</dd></div>
                <div><dt className="text-xs text-ink-500">Fulfilled</dt><dd className="font-mono text-lg">{a.sales.fulfilled_pct}%</dd></div>
                <div><dt className="text-xs text-ink-500">Avg. time to deliver</dt><dd className="font-mono text-lg">{dur(a.sales.avg_fulfilment_seconds)}</dd></div>
              </dl>
              {a.sales.daily.length === 0 ? <Empty title="No orders in this period" /> : (
                <div className="flex h-28 items-end gap-1" aria-label="Orders per day">
                  {a.sales.daily.map((d) => {
                    const max = Math.max(...a.sales.daily.map((x) => x.orders), 1);
                    return <div key={d.date} title={`${d.date}: ${d.orders} orders, ${money(d.revenue)}`} className="flex-1 rounded-t bg-brand-600" style={{ height: `${Math.max(4, (100 * d.orders) / max)}%` }} />;
                  })}
                </div>
              )}
            </Card>
          </div>

          <Card title="Supplier performance">
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full min-w-[640px] text-sm">
                <thead><tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase text-ink-500"><th className="px-5 py-2">Supplier</th><th className="px-3 py-2 text-right">Imports</th><th className="px-3 py-2 text-right">Completed</th><th className="px-3 py-2 text-right">Failed</th><th className="px-3 py-2 text-right">Failed records</th><th className="px-3 py-2 text-right">API requests</th><th className="px-5 py-2">Last import</th></tr></thead>
                <tbody className="divide-y divide-ink-100">
                  {a.suppliers.imports.map((s) => (
                    <tr key={s.supplier}><td className="px-5 py-2 font-medium">{s.supplier}</td><td className="px-3 py-2 text-right font-mono">{s.jobs}</td><td className="px-3 py-2 text-right font-mono">{s.completed}</td><td className="px-3 py-2 text-right font-mono">{s.failed}</td><td className="px-3 py-2 text-right font-mono">{s.records_failed}</td><td className="px-3 py-2 text-right font-mono">{s.api_requests.toLocaleString()}</td><td className="px-5 py-2 text-xs text-ink-500">{s.last_import ? new Date(s.last_import).toLocaleString() : "—"}</td></tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="mt-3 text-sm text-ink-600">Purchase success: {a.suppliers.purchase_success_pct}% · Price push failures: {a.publishing.price_push_failures}</p>
          </Card>
        </div>
      )}
    </>
  );
}
