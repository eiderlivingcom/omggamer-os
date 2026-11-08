"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Button, Card, Empty, Notice, PageHeader, Pill, cx, fmtDate } from "@/components/ui";
import { api, ApiError, type CategoryNode } from "@/lib/api";
import { useSession } from "@/lib/session";

type Rule = {
  id: string;
  name: string;
  scope: "global" | "supplier" | "platform" | "category" | "product";
  scope_value: string | null;
  storefront_id: string | null;
  priority: number;
  markup_percent: string;
  markup_fixed: string;
  min_margin_percent: string | null;
  min_margin_fixed: string | null;
  min_price: string | null;
  rounding: string;
  is_active: boolean;
};
type Rate = { base: string; quote: string; rate: string; source: string; updated_at: string };
type History = { variant_id: string; label: string; storefront: string; old_price: string | null; new_price: string | null; cost: string | null; cost_currency: string | null; currency: string; reason: string; created_at: string };
type Storefront = { id: string; code: string; name: string; configuration?: Record<string, unknown> };

const ROUNDING: Record<string, string> = { none: "No rounding", cents_99: "Up to .99", cents_49_99: "Up to .49 / .99", whole: "Up to whole number", nearest_05: "Up to 0.05" };
const SCOPES: Record<Rule["scope"], string> = { global: "All products", supplier: "Supplier", platform: "Platform", category: "Category", product: "Product" };

const EMPTY = {
  name: "", scope: "global" as Rule["scope"], scope_value: "", storefront: "", priority: 0, markup_percent: "15", markup_fixed: "0",
  min_margin_percent: "", min_margin_fixed: "", min_price: "", rounding: "cents_99", is_active: true,
};

function flatten(nodes: CategoryNode[], depth = 0): { id: string; name: string }[] {
  return nodes.flatMap((n) => [{ id: n.id, name: `${"— ".repeat(depth)}${n.name}` }, ...flatten(n.children, depth + 1)]);
}

export default function PricingPage() {
  const { can } = useSession();
  const manage = can("pricing:manage");
  const [tab, setTab] = useState<"rules" | "currencies" | "history">("rules");
  const [rules, setRules] = useState<Rule[] | null>(null);
  const [rates, setRates] = useState<Rate[] | null>(null);
  const [history, setHistory] = useState<History[] | null>(null);
  const [storefronts, setStorefronts] = useState<Storefront[]>([]);
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [form, setForm] = useState(EMPTY);
  const [editing, setEditing] = useState<string | null>(null);
  const [rateForm, setRateForm] = useState({ base: "EUR", quote: "USD", rate: "" });
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [r, rt, sf, cats] = await Promise.all([
        api<Rule[]>("/pricing/rules"),
        api<Rate[]>("/pricing/currencies"),
        api<Storefront[]>("/storefronts"),
        api<CategoryNode[]>("/catalogue/categories").catch(() => []),
      ]);
      setRules(r);
      setRates(rt);
      setStorefronts(sf);
      setCategories(flatten(cats));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load pricing.");
    }
  }, []);
  useEffect(() => {
    void load();
  }, [load]);
  useEffect(() => {
    if (tab === "history") api<History[]>("/pricing/history?limit=200").then(setHistory).catch(() => setHistory([]));
  }, [tab]);

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
    const body = {
      ...form,
      scope_value: form.scope === "global" ? null : form.scope_value,
      storefront: form.storefront || null,
      min_margin_percent: form.min_margin_percent || null,
      min_margin_fixed: form.min_margin_fixed || null,
      min_price: form.min_price || null,
    };
    void act(async () => {
      await api(editing ? `/pricing/rules/${editing}` : "/pricing/rules", { method: editing ? "PUT" : "POST", json: body });
      setForm(EMPTY);
      setEditing(null);
    }, editing ? "Rule saved. Recalculate to apply it to stored prices." : "Rule created. Recalculate to apply it to stored prices.");
  }

  function edit(r: Rule) {
    setEditing(r.id);
    setForm({
      name: r.name, scope: r.scope, scope_value: r.scope_value ?? "", storefront: storefronts.find((s) => s.id === r.storefront_id)?.code ?? "",
      priority: r.priority, markup_percent: r.markup_percent, markup_fixed: r.markup_fixed, min_margin_percent: r.min_margin_percent ?? "",
      min_margin_fixed: r.min_margin_fixed ?? "", min_price: r.min_price ?? "", rounding: r.rounding, is_active: r.is_active,
    });
  }

  const describe = (r: Rule) => {
    const parts = [];
    if (Number(r.markup_percent)) parts.push(`+${Number(r.markup_percent)}%`);
    if (Number(r.markup_fixed)) parts.push(`+${Number(r.markup_fixed).toFixed(2)}`);
    if (r.min_margin_percent) parts.push(`min margin ${Number(r.min_margin_percent)}%`);
    if (r.min_margin_fixed) parts.push(`min margin ${Number(r.min_margin_fixed).toFixed(2)}`);
    if (r.min_price) parts.push(`min price ${Number(r.min_price).toFixed(2)}`);
    parts.push(ROUNDING[r.rounding] ?? r.rounding);
    return parts.join(" · ");
  };
  const scopeLabel = (r: Rule) => {
    if (r.scope === "global") return "All products";
    if (r.scope === "category") return `Category: ${categories.find((c) => c.id === r.scope_value)?.name.replace(/^(— )+/, "") ?? r.scope_value}`;
    return `${SCOPES[r.scope]}: ${r.scope_value}`;
  };
  const input = "mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal";

  return (
    <>
      <PageHeader
        title="Pricing"
        description="Selling price = supplier cost (converted) + markup, raised to the minimum margin and price, then rounded up. Supplier purchase prices are never changed."
        actions={manage && (
          <Button variant="ghost" onClick={() => window.confirm("Recalculate the selling price of every variant for every storefront? It runs in the background.") && act(() => api("/pricing/recalculate", { method: "POST", json: {} }), "Recalculation started in the background.")}>
            Recalculate all prices
          </Button>
        )}
      />
      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {notice && <div className="mb-4"><Notice tone="ok">{notice}</Notice></div>}
      <div className="mb-5 flex gap-1 border-b border-ink-100">
        {([["rules", "Pricing rules"], ["currencies", "Currencies"], ["history", "Price history"]] as const).map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} className={cx("border-b-2 px-3 py-2 text-sm font-medium", tab === k ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-900")}>{l}</button>
        ))}
      </div>

      {tab === "rules" && (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
          <Card title="Rules" aside={<span className="text-xs text-ink-500">Most specific wins: product › category › platform › supplier › all</span>}>
            {!rules ? <div className="h-32 animate-pulse rounded-lg bg-ink-50" /> : rules.length === 0 ? (
              <Empty title="No pricing rules">Without a rule nothing gets a selling price. Start with one rule for all products, e.g. +15% rounded up to .99.</Empty>
            ) : (
              <ul className="divide-y divide-ink-100">
                {rules.map((r) => (
                  <li key={r.id} className={cx("flex flex-wrap items-center gap-3 py-3", !r.is_active && "opacity-50")}>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2 text-sm font-semibold text-ink-900">
                        {r.name}
                        {r.storefront_id && <Pill tone="info">{storefronts.find((s) => s.id === r.storefront_id)?.code}</Pill>}
                        {!r.is_active && <Pill>off</Pill>}
                      </div>
                      <div className="text-xs text-ink-500">{scopeLabel(r)} · priority {r.priority}</div>
                      <div className="mt-0.5 text-sm text-ink-700">{describe(r)}</div>
                    </div>
                    {manage && (
                      <>
                        <button className="text-xs font-semibold text-brand-700" onClick={() => edit(r)}>Edit</button>
                        <button className="text-xs font-semibold text-rose-700" onClick={() => window.confirm(`Delete “${r.name}”?`) && act(() => api(`/pricing/rules/${r.id}`, { method: "DELETE" }), "Rule deleted.")}>Delete</button>
                      </>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          {manage && (
            <Card title={editing ? "Edit rule" : "New rule"}>
              <form onSubmit={save} className="grid grid-cols-2 gap-3">
                <label className="col-span-2 block text-xs font-semibold text-ink-700">Name<input required className={input} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
                <label className="block text-xs font-semibold text-ink-700">
                  Applies to
                  <select className={input} value={form.scope} onChange={(e) => setForm({ ...form, scope: e.target.value as Rule["scope"], scope_value: "" })}>
                    {Object.entries(SCOPES).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                  </select>
                </label>
                <label className="block text-xs font-semibold text-ink-700">
                  {form.scope === "global" ? " " : SCOPES[form.scope]}
                  {form.scope === "category" ? (
                    <select required className={input} value={form.scope_value} onChange={(e) => setForm({ ...form, scope_value: e.target.value })}>
                      <option value="">Choose</option>
                      {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                  ) : (
                    <input
                      className={input}
                      disabled={form.scope === "global"}
                      required={form.scope !== "global"}
                      value={form.scope_value}
                      onChange={(e) => setForm({ ...form, scope_value: e.target.value })}
                      placeholder={{ global: "", supplier: "kinguin", platform: "Steam", category: "", product: "Product ID" }[form.scope]}
                    />
                  )}
                </label>
                <label className="block text-xs font-semibold text-ink-700">
                  Storefront
                  <select className={input} value={form.storefront} onChange={(e) => setForm({ ...form, storefront: e.target.value })}>
                    <option value="">All storefronts</option>
                    {storefronts.map((s) => <option key={s.code} value={s.code}>{s.name}</option>)}
                  </select>
                </label>
                <label className="block text-xs font-semibold text-ink-700">Priority<input type="number" className={input} value={form.priority} onChange={(e) => setForm({ ...form, priority: Number(e.target.value) })} /></label>
                <label className="block text-xs font-semibold text-ink-700">Markup %<input type="number" step="0.1" className={input} value={form.markup_percent} onChange={(e) => setForm({ ...form, markup_percent: e.target.value })} /></label>
                <label className="block text-xs font-semibold text-ink-700">Fixed markup<input type="number" step="0.01" className={input} value={form.markup_fixed} onChange={(e) => setForm({ ...form, markup_fixed: e.target.value })} /></label>
                <label className="block text-xs font-semibold text-ink-700">Min margin %<input type="number" step="0.1" min={0} className={input} value={form.min_margin_percent} onChange={(e) => setForm({ ...form, min_margin_percent: e.target.value })} /></label>
                <label className="block text-xs font-semibold text-ink-700">Min margin (amount)<input type="number" step="0.01" min={0} className={input} value={form.min_margin_fixed} onChange={(e) => setForm({ ...form, min_margin_fixed: e.target.value })} /></label>
                <label className="block text-xs font-semibold text-ink-700">Min selling price<input type="number" step="0.01" min={0} className={input} value={form.min_price} onChange={(e) => setForm({ ...form, min_price: e.target.value })} /></label>
                <label className="block text-xs font-semibold text-ink-700">
                  Rounding
                  <select className={input} value={form.rounding} onChange={(e) => setForm({ ...form, rounding: e.target.value })}>
                    {Object.entries(ROUNDING).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                  </select>
                </label>
                <label className="col-span-2 flex items-center gap-2 text-sm text-ink-700"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Active</label>
                <div className="col-span-2 flex gap-2">
                  <Button type="submit">{editing ? "Save rule" : "Create rule"}</Button>
                  {editing && <Button type="button" variant="ghost" onClick={() => { setEditing(null); setForm(EMPTY); }}>Cancel</Button>}
                </div>
              </form>
            </Card>
          )}
        </div>
      )}

      {tab === "currencies" && (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
          <Card title="Exchange rates" aside={manage && <Button variant="ghost" className="h-8" onClick={() => act(async () => { const r = await api<{ updated: number }>("/pricing/currencies/refresh", { method: "POST" }); setNotice(`${r.updated} ECB rates updated.`); }, "ECB rates updated.")}>Fetch ECB rates</Button>}>
            {!rates ? <div className="h-24 animate-pulse rounded-lg bg-ink-50" /> : rates.length === 0 ? <Empty title="No rates yet">Fetch the European Central Bank rates or add one by hand. Rates update daily at 15:30 UTC.</Empty> : (
              <table className="w-full text-sm">
                <thead><tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase text-ink-500"><th className="py-2">Pair</th><th className="py-2 text-right">Rate</th><th className="py-2">Source</th><th className="py-2">Updated</th><th /></tr></thead>
                <tbody className="divide-y divide-ink-100">
                  {rates.map((r) => (
                    <tr key={`${r.base}${r.quote}`}>
                      <td className="py-2 font-mono">{r.base} → {r.quote}</td>
                      <td className="py-2 text-right font-mono">{Number(r.rate).toFixed(4)}</td>
                      <td className="py-2"><Pill tone={r.source === "manual" ? "info" : "muted"}>{r.source}</Pill></td>
                      <td className="py-2 text-xs text-ink-500">{fmtDate(r.updated_at)}</td>
                      <td className="py-2 text-right">{manage && r.source === "manual" && <button className="text-xs font-semibold text-rose-700" onClick={() => act(() => api(`/pricing/currencies/${r.base}/${r.quote}`, { method: "DELETE" }), "Rate removed.")}>Remove</button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>
          {manage && (
            <div className="space-y-6">
              <Card title="Set a rate by hand">
                <form onSubmit={(e) => { e.preventDefault(); void act(() => api("/pricing/currencies", { method: "PUT", json: rateForm }), "Rate saved. Manual rates are not replaced by ECB updates."); }} className="grid grid-cols-3 gap-2">
                  <input aria-label="From" maxLength={3} required className={input} value={rateForm.base} onChange={(e) => setRateForm({ ...rateForm, base: e.target.value.toUpperCase() })} />
                  <input aria-label="To" maxLength={3} required className={input} value={rateForm.quote} onChange={(e) => setRateForm({ ...rateForm, quote: e.target.value.toUpperCase() })} />
                  <input aria-label="Rate" type="number" step="0.00000001" min={0} required className={input} value={rateForm.rate} onChange={(e) => setRateForm({ ...rateForm, rate: e.target.value })} placeholder="1.08" />
                  <div className="col-span-3"><Button type="submit">Save rate</Button></div>
                </form>
              </Card>
              <Card title="Storefront currencies">
                <ul className="space-y-2 text-sm">
                  {storefronts.map((s) => (
                    <li key={s.code} className="flex items-center justify-between gap-2">
                      <span>{s.name}</span>
                      <select
                        aria-label={`Currency for ${s.name}`}
                        className="h-8 rounded-md border border-ink-200 bg-white px-2 text-sm"
                        defaultValue={String(s.configuration?.currency ?? "EUR")}
                        onChange={(e) => act(() => api(`/pricing/storefronts/${s.code}`, { method: "PUT", json: { currency: e.target.value } }), `${s.name} now prices in ${e.target.value}.`)}
                      >
                        {["EUR", "USD", "GBP", "PLN", "SEK", "CHF", "INR", "AUD", "CAD"].map((c) => <option key={c}>{c}</option>)}
                      </select>
                    </li>
                  ))}
                </ul>
              </Card>
            </div>
          )}
        </div>
      )}

      {tab === "history" && (
        <Card title="Recent price changes">
          {!history ? <div className="h-24 animate-pulse rounded-lg bg-ink-50" /> : history.length === 0 ? <Empty title="No price changes yet">Changes appear after prices are recalculated.</Empty> : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead><tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase text-ink-500"><th className="px-5 py-2">When</th><th className="px-3 py-2">Variant</th><th className="px-3 py-2">Storefront</th><th className="px-3 py-2 text-right">Cost</th><th className="px-3 py-2 text-right">Old</th><th className="px-3 py-2 text-right">New</th><th className="px-5 py-2">Reason</th></tr></thead>
                <tbody className="divide-y divide-ink-100">
                  {history.map((h, i) => (
                    <tr key={i}>
                      <td className="px-5 py-2 text-xs text-ink-500">{fmtDate(h.created_at)}</td>
                      <td className="px-3 py-2">{h.label}</td>
                      <td className="px-3 py-2">{h.storefront}</td>
                      <td className="px-3 py-2 text-right font-mono">{h.cost ? `${Number(h.cost).toFixed(2)} ${h.cost_currency}` : "—"}</td>
                      <td className="px-3 py-2 text-right font-mono">{h.old_price ?? "—"}</td>
                      <td className="px-3 py-2 text-right font-mono">{h.new_price ? `${h.new_price} ${h.currency}` : "—"}</td>
                      <td className="px-5 py-2 text-ink-600">{h.reason}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}
    </>
  );
}
