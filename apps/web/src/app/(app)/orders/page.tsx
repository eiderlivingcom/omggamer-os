"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Button, Card, Empty, Notice, PageHeader, Pill, Stat, cx, fmtDate } from "@/components/ui";
import { money } from "@/components/status";
import { api, ApiError } from "@/lib/api";
import { useSession } from "@/lib/session";

type OrderRow = {
  id: string;
  external_order_id: string;
  order_number: string | null;
  status: string;
  store_status: string | null;
  currency: string;
  total: string;
  attempts: number;
  last_error: string | null;
  created_at: string;
  fulfilled_at: string | null;
};
type Detail = OrderRow & {
  items: { id: string; external_item_id: string; name: string | null; product_id: string | null; quantity: number; unit_price: string; supplier_cost: string | null; supplier_currency: string | null; status: string; keys: number; last_error: string | null }[];
  supplier_orders: { id: string; reference: string; external_order_id: string | null; status: string; total_price: string | null; payment_price: string | null; keys_returned_at: string | null; last_error: string | null; created_at: string }[];
  events: { event: string; message: string; created_at: string }[];
};
type Overview = { counts: Record<string, number>; revenue_30d: string; supplier_cost_30d: string; settings: { auto_fulfil: boolean; allow_loss: boolean } };

const TONE: Record<string, "ok" | "warn" | "bad" | "info" | "muted"> = {
  received: "info", processing: "info", awaiting_keys: "info", fulfilled: "ok", partially_fulfilled: "warn",
  needs_attention: "bad", cancelled: "muted", refunded: "muted", delivered: "ok", purchased: "info", pending: "muted",
  purchasing: "info", failed: "bad", completed: "ok", unknown: "warn", canceled: "muted",
};
const label = (s: string) => s.replace(/_/g, " ");

export default function OrdersPage() {
  const { can } = useSession();
  const manage = can("orders:manage");
  const [ov, setOv] = useState<Overview | null>(null);
  const [rows, setRows] = useState<OrderRow[] | null>(null);
  const [filter, setFilter] = useState("");
  const [q, setQ] = useState("");
  const [open, setOpen] = useState<Detail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const params = new URLSearchParams({ limit: "200" });
      if (filter) params.set("status", filter);
      if (q.trim()) params.set("q", q.trim());
      const [o, r] = await Promise.all([api<Overview>("/orders/overview"), api<OrderRow[]>(`/orders?${params}`)]);
      setOv(o);
      setRows(r);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load orders.");
    }
  }, [filter, q]);
  useEffect(() => {
    void load();
    const t = setInterval(load, 15000);
    return () => clearInterval(t);
  }, [load]);

  async function show(id: string) {
    try {
      setOpen(await api<Detail>(`/orders/${id}`));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not open the order.");
    }
  }

  async function act(path: string, ok: string, confirmText?: string, body: unknown = {}) {
    if (confirmText && !window.confirm(confirmText)) return;
    setError(null);
    setNotice(null);
    try {
      await api(path, { method: "POST", json: body });
      setNotice(ok);
      await load();
      if (open) await show(open.id);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "That did not work.");
    }
  }

  async function setting(changes: Partial<Overview["settings"]>) {
    try {
      await api("/orders/settings", { method: "PUT", json: changes });
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not save.");
    }
  }

  const attention = (ov?.counts.needs_attention ?? 0) + (ov?.counts.partially_fulfilled ?? 0);

  return (
    <>
      <PageHeader
        title="Orders"
        description="Paid WooCommerce orders are bought at Kinguin once, and the keys are delivered to the customer’s order. Keys are stored encrypted and never shown here."
      />
      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {notice && <div className="mb-4"><Notice tone="ok">{notice}</Notice></div>}

      <div className="mb-6 grid grid-cols-2 gap-x-6 gap-y-5 rounded-xl border border-ink-100 bg-white p-5 shadow-sm sm:grid-cols-3 lg:grid-cols-6">
        <Stat label="Fulfilled" value={ov?.counts.fulfilled ?? 0} />
        <Stat label="In progress" value={(ov?.counts.received ?? 0) + (ov?.counts.processing ?? 0) + (ov?.counts.awaiting_keys ?? 0)} />
        <Stat label="Needs attention" value={attention} tone={attention ? "bad" : undefined} />
        <Stat label="Refunded" value={ov?.counts.refunded ?? 0} />
        <Stat label="Revenue (30 days)" value={ov ? money(ov.revenue_30d) : "—"} hint="fulfilled orders" />
        <Stat label="Supplier cost (30 days)" value={ov ? money(ov.supplier_cost_30d) : "—"} />
      </div>

      {manage && ov && (
        <Card title="Fulfilment settings" className="mb-6">
          <div className="flex flex-wrap gap-6 text-sm">
            <label className="flex items-center gap-2"><input type="checkbox" checked={ov.settings.auto_fulfil} onChange={(e) => setting({ auto_fulfil: e.target.checked })} /> Buy and deliver automatically when an order is paid</label>
            <label className="flex items-center gap-2"><input type="checkbox" checked={ov.settings.allow_loss} onChange={(e) => setting({ allow_loss: e.target.checked })} /> Also buy when the supplier cost is above the sale price</label>
          </div>
        </Card>
      )}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <Card
          title="Orders"
          aside={
            <div className="flex gap-2">
              <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Order number" aria-label="Search orders" className="h-8 w-32 rounded-md border border-ink-200 px-2 text-sm" />
              <select value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter" className="h-8 rounded-md border border-ink-200 bg-white px-2 text-sm">
                <option value="">All</option>
                {["needs_attention", "partially_fulfilled", "processing", "awaiting_keys", "fulfilled", "refunded", "cancelled"].map((s) => <option key={s} value={s}>{label(s)}</option>)}
              </select>
            </div>
          }
        >
          {!rows ? <div className="h-32 animate-pulse rounded-lg bg-ink-50" /> : rows.length === 0 ? (
            <Empty title="No orders yet">Orders arrive when a customer pays for an OMGGamer product on a connected store.</Empty>
          ) : (
            <ul className="divide-y divide-ink-100">
              {rows.map((o) => (
                <li key={o.id}>
                  <button onClick={() => show(o.id)} className={cx("flex w-full items-center gap-3 py-2.5 text-left text-sm hover:bg-ink-50", open?.id === o.id && "bg-brand-50/50")}>
                    <span className="w-20 font-mono font-semibold">#{o.order_number ?? o.external_order_id}</span>
                    <Pill tone={TONE[o.status] ?? "muted"}>{label(o.status)}</Pill>
                    <span className="flex-1 truncate text-xs text-rose-700">{o.status !== "fulfilled" ? o.last_error : ""}</span>
                    <span className="font-mono">{money(o.total, o.currency)}</span>
                    <span className="w-28 text-right text-xs text-ink-500">{fmtDate(o.created_at)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {open ? (
          <div className="space-y-6">
            <Card title={`Order #${open.order_number ?? open.external_order_id}`} aside={<Pill tone={TONE[open.status] ?? "muted"}>{label(open.status)}</Pill>}>
              {open.last_error && open.status !== "fulfilled" && <div className="mb-3"><Notice tone="bad">{open.last_error}</Notice></div>}
              <table className="w-full text-sm">
                <thead><tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase text-ink-500"><th className="py-2">Item</th><th className="py-2 text-right">Qty</th><th className="py-2 text-right">Sold</th><th className="py-2 text-right">Cost</th><th className="py-2">Status</th><th className="py-2 text-right">Keys</th></tr></thead>
                <tbody className="divide-y divide-ink-100">
                  {open.items.map((i) => (
                    <tr key={i.id}>
                      <td className="py-2">{i.product_id ? <Link className="hover:text-brand-700" href={`/catalogue/${i.product_id}`}>{i.name}</Link> : i.name}{i.last_error && <div className="text-xs text-rose-700">{i.last_error}</div>}</td>
                      <td className="py-2 text-right font-mono">{i.quantity}</td>
                      <td className="py-2 text-right font-mono">{money(i.unit_price, open.currency)}</td>
                      <td className="py-2 text-right font-mono">{money(i.supplier_cost, i.supplier_currency)}</td>
                      <td className="py-2"><Pill tone={TONE[i.status] ?? "muted"}>{label(i.status)}</Pill></td>
                      <td className="py-2 text-right font-mono">{i.keys}/{i.quantity}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {manage && (
                <div className="mt-4 flex flex-wrap gap-2 border-t border-ink-100 pt-4">
                  {!["fulfilled", "cancelled", "refunded"].includes(open.status) && <Button onClick={() => act(`/orders/${open.id}/retry`, "Retry queued.")}>Retry</Button>}
                  <Button variant="ghost" onClick={() => act(`/orders/${open.id}/resend-keys`, "Keys will be sent to the store again.")}>Resend keys to store</Button>
                  {!["fulfilled", "refunded"].includes(open.status) && <Button variant="ghost" onClick={() => act(`/orders/${open.id}/resolve`, "Marked as handled.", "Mark this order as handled by hand?")}>Mark handled</Button>}
                  {!["cancelled", "refunded"].includes(open.status) && <Button variant="ghost" onClick={() => act(`/orders/${open.id}/cancel`, "Order cancelled.", "Cancel this order? Only possible before anything was bought.")}>Cancel</Button>}
                  {open.status !== "refunded" && <Button variant="danger" onClick={() => act(`/orders/${open.id}/refund`, "Refund recorded. Refund the payment in WooCommerce.", "Return unused keys to Kinguin (allowed within 24 hours, unclaimed keys only, once per order) and mark this order refunded?")}>Refund</Button>}
                </div>
              )}
            </Card>
            <Card title="Supplier purchases">
              {open.supplier_orders.length === 0 ? <p className="text-sm text-ink-500">Nothing bought yet.</p> : (
                <ul className="divide-y divide-ink-100 text-sm">
                  {open.supplier_orders.map((s) => (
                    <li key={s.id} className="py-2">
                      <div className="flex flex-wrap items-center gap-2"><span className="font-mono text-xs">{s.reference}</span><Pill tone={TONE[s.status] ?? "muted"}>{s.status}</Pill>{s.external_order_id && <span className="text-xs text-ink-500">Kinguin {s.external_order_id}</span>}<span className="ml-auto font-mono text-xs">{s.payment_price ? `${s.payment_price} paid` : ""}</span></div>
                      {s.last_error && <div className="text-xs text-rose-700">{s.last_error}</div>}
                      {s.keys_returned_at && <div className="text-xs text-ink-500">Keys returned {fmtDate(s.keys_returned_at)}</div>}
                    </li>
                  ))}
                </ul>
              )}
            </Card>
            <Card title="Order log">
              <ul className="max-h-80 divide-y divide-ink-100 overflow-y-auto text-sm">
                {open.events.map((e, i) => (
                  <li key={i} className="flex gap-3 py-1.5"><span className="w-32 shrink-0 text-xs text-ink-500">{fmtDate(e.created_at)}</span><span>{e.message}</span></li>
                ))}
              </ul>
            </Card>
          </div>
        ) : (
          <Card><Empty title="Select an order">Its items, supplier purchases and log appear here.</Empty></Card>
        )}
      </div>
    </>
  );
}
