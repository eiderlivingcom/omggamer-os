"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from "react";
import { AiStatus, ProductStatus, money } from "@/components/status";
import { Button, Card, Empty, Field, Notice, Pill, cx, fmtDate } from "@/components/ui";
import {
  api,
  ApiError,
  type Activity,
  type BulkResult,
  type CategoryNode,
  type Content,
  type ProductDetail,
  type PublishingHistory,
  type SupplierProductDetail,
} from "@/lib/api";
import { useSession } from "@/lib/session";

const TABS = [
  ["overview", "Overview"],
  ["original", "Original supplier data"],
  ["variants", "Variants"],
  ["offers", "Supplier offers"],
  ["ai", "AI content"],
  ["categories", "Categories & attributes"],
  ["media", "Images & media"],
  ["seo", "SEO"],
  ["publishing", "Publishing history"],
  ["activity", "Activity log"],
] as const;
type Tab = (typeof TABS)[number][0];

function Row({ k, v }: { k: string; v: ReactNode }) {
  return (
    <div className="grid grid-cols-[160px_minmax(0,1fr)] gap-3 py-2 text-sm">
      <dt className="text-ink-500">{k}</dt>
      <dd className="min-w-0 break-words text-ink-900">{v ?? <span className="text-ink-300">—</span>}</dd>
    </div>
  );
}

/** Supplier descriptions arrive as HTML. Show them as plain text so supplier markup never runs here. */
function plain(html: string | null | undefined) {
  if (!html) return "";
  const doc = new DOMParser().parseFromString(html, "text/html");
  return (doc.body.textContent ?? "").replace(/\n{3,}/g, "\n\n").trim();
}

function flatten(nodes: CategoryNode[], depth = 0): { id: string; name: string }[] {
  return nodes.flatMap((n) => [{ id: n.id, name: `${"— ".repeat(depth)}${n.name}` }, ...flatten(n.children, depth + 1)]);
}

export default function ProductPage() {
  const { id } = useParams<{ id: string }>();
  const { can } = useSession();
  const [p, setP] = useState<ProductDetail | null>(null);
  const [tab, setTab] = useState<Tab>("overview");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [original, setOriginal] = useState<SupplierProductDetail | null>(null);
  const [originalId, setOriginalId] = useState<string | null>(null);
  const [versions, setVersions] = useState<Content[] | null>(null);
  const [history, setHistory] = useState<PublishingHistory | null>(null);
  const [activity, setActivity] = useState<Activity[] | null>(null);
  const [cats, setCats] = useState<{ id: string; name: string }[]>([]);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const d = await api<ProductDetail>(`/catalogue/products/${id}`);
      setP(d);
      setOriginalId((cur) => cur ?? d.supplier_products[0]?.id ?? null);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load the product.");
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    if (!originalId || !["original", "ai"].includes(tab)) return;
    api<SupplierProductDetail>(`/catalogue/supplier-products/${originalId}`).then(setOriginal).catch(() => setOriginal(null));
  }, [originalId, tab]);

  useEffect(() => {
    if (tab === "ai" || tab === "seo") api<Content[]>(`/catalogue/products/${id}/content`).then(setVersions).catch(() => setVersions([]));
    if (tab === "publishing") api<PublishingHistory>(`/catalogue/products/${id}/publishing`).then(setHistory).catch(() => undefined);
    if (tab === "activity") api<Activity[]>(`/catalogue/products/${id}/activity`).then(setActivity).catch(() => setActivity([]));
    if (tab === "categories") api<CategoryNode[]>("/catalogue/categories").then((t) => setCats(flatten(t))).catch(() => undefined);
  }, [tab, id]);

  async function act(action: string, confirmText?: string) {
    if (confirmText && !window.confirm(confirmText)) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const r = await api<BulkResult>("/catalogue/products/bulk", { method: "POST", json: { action, product_ids: [id] } });
      const why = Object.keys(r.details).join(", ").replace(/_/g, " ");
      setNotice(r.changed ? "Done." : `Nothing changed${why ? ` (${why})` : ""}.`);
      await load();
      if (tab === "ai") setVersions(await api<Content[]>(`/catalogue/products/${id}/content`));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "That did not work.");
    } finally {
      setBusy(false);
    }
  }

  if (error && !p) return <Notice tone="bad">{error}</Notice>;
  if (!p) return <div className="h-64 animate-pulse rounded-xl bg-ink-50" />;

  const cover = p.media.find((m) => m.media_type === "cover") ?? p.media[0];
  const c = p.content;

  return (
    <>
      <div className="mb-1 text-sm"><Link href="/catalogue" className="text-ink-500 hover:text-ink-900">← Catalogue</Link></div>
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start">
        {cover && (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={cover.source_url} alt="" className="h-28 w-20 shrink-0 rounded-lg object-cover ring-1 ring-ink-100" />
        )}
        <div className="min-w-0 flex-1">
          <h1 className="text-xl font-bold text-ink-900">{p.canonical_title}</h1>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <ProductStatus value={p.product_status} />
            <AiStatus value={c?.content_status ?? null} />
            <Pill>{p.product_type.replace("_", " ")}</Pill>
            <span className="font-mono text-xs text-ink-500">
              {p.supplier_products.map((s) => `${s.supplier_code} ${s.external_product_id}`).join(" · ")}
            </span>
          </div>
          {p.master_product_id && (
            <p className="mt-2 text-sm text-amber-700">
              Merged into <Link className="underline" href={`/catalogue/${p.master_product_id}`}>another product</Link>.
            </p>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          {can("content:write") && (
            <Button variant="ghost" disabled={busy} onClick={() => act(c ? "regenerate_ai" : "generate_ai", c ? "Generate a new AI version? The current one is kept in the version history." : undefined)}>
              {c ? "Regenerate AI" : "Generate AI content"}
            </Button>
          )}
          {can("publishing:approve") && <Button disabled={busy} onClick={() => act("approve")}>Approve</Button>}
          {can("publishing:approve") && <Button variant="ghost" disabled={busy} onClick={() => act("reject")}>Reject</Button>}
          {can("publishing:manage") && (
            <Button variant="ghost" disabled={busy || !["approved", "published", "unpublished"].includes(p.product_status)} onClick={() => act("publish", "Queue this product for publishing to WooCommerce?")}>
              Publish
            </Button>
          )}
        </div>
      </div>

      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {notice && <div className="mb-4"><Notice tone="ok">{notice}</Notice></div>}

      <div className="mb-5 flex gap-1 overflow-x-auto border-b border-ink-100" role="tablist">
        {TABS.map(([key, label]) => (
          <button
            key={key}
            role="tab"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={cx(
              "whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition",
              tab === key ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-900",
            )}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === "overview" && <Overview p={p} onSaved={load} canEdit={can("catalogue:write")} />}

      {tab === "original" && (
        <div className="grid gap-6 xl:grid-cols-[280px_minmax(0,1fr)]">
          <Card title="Supplier records">
            <ul className="space-y-1">
              {p.supplier_products.map((s) => (
                <li key={s.id}>
                  <button
                    onClick={() => setOriginalId(s.id)}
                    className={cx("w-full rounded-lg px-3 py-2 text-left text-sm", originalId === s.id ? "bg-brand-50 text-brand-800" : "hover:bg-ink-50")}
                  >
                    <div className="font-mono text-xs uppercase text-ink-500">{s.supplier_code} {s.external_product_id}</div>
                    <div className="truncate font-medium">{s.original_title}</div>
                  </button>
                </li>
              ))}
            </ul>
          </Card>
          <Card title="Original data (never edited by OMGGamer OS)">
            {!original ? (
              <div className="h-40 animate-pulse rounded-lg bg-ink-50" />
            ) : (
              <>
                <dl className="divide-y divide-ink-100">
                  <Row k="Title" v={original.original_title} />
                  <Row k="Last fetched" v={fmtDate(original.last_fetched_at)} />
                  <Row k="Import status" v={original.import_status} />
                </dl>
                <h3 className="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-500">Description</h3>
                <p className="mt-1 whitespace-pre-line text-sm text-ink-800">{plain(original.original_description) || "—"}</p>
                <details className="mt-4">
                  <summary className="cursor-pointer text-sm font-semibold text-ink-700">Raw supplier payload</summary>
                  <pre className="mt-2 max-h-[480px] overflow-auto rounded-lg bg-navy-950 p-3 text-[12px] text-ink-100">
                    {JSON.stringify(original.raw_payload, null, 2)}
                  </pre>
                </details>
              </>
            )}
          </Card>
        </div>
      )}

      {tab === "variants" && (
        <Card title={`${p.variants.length} variants`}>
          {p.variants.length === 0 ? <Empty title="No variants" /> : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead>
                  <tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                    <th className="px-5 py-2">SKU</th><th className="px-3 py-2">Platform</th><th className="px-3 py-2">Edition</th>
                    <th className="px-3 py-2">Region</th><th className="px-3 py-2">Activation</th><th className="px-3 py-2 text-right">Offers</th>
                    <th className="px-5 py-2 text-right">Lowest</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink-100">
                  {p.variants.map((v) => (
                    <tr key={v.id}>
                      <td className="px-5 py-2 font-mono text-xs">{v.sku}</td><td className="px-3 py-2">{v.platform ?? "—"}</td>
                      <td className="px-3 py-2">{v.edition ?? "Standard"}</td><td className="px-3 py-2">{v.region ?? "—"}</td>
                      <td className="px-3 py-2 capitalize">{v.activation_type?.replace("_", " ") ?? "—"}</td>
                      <td className="px-3 py-2 text-right font-mono">{v.offer_count}</td>
                      <td className="px-5 py-2 text-right font-mono">{money(v.lowest_price, v.lowest_price_currency)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {tab === "offers" && (
        <Card title={`${p.offers.length} supplier offers`} aside={<span className="text-xs text-ink-500">Supplier purchase prices, never edited</span>}>
          {p.offers.length === 0 ? <Empty title="No offers" /> : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead>
                  <tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                    <th className="px-5 py-2">Seller</th><th className="px-3 py-2">Variant</th><th className="px-3 py-2">Availability</th>
                    <th className="px-3 py-2 text-right">Qty</th><th className="px-3 py-2 text-right">Price</th><th className="px-5 py-2">Synced</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink-100">
                  {[...p.offers].sort((a, b) => Number(a.supplier_price) - Number(b.supplier_price)).map((o) => {
                    const v = p.variants.find((x) => x.id === o.variant_id);
                    return (
                      <tr key={o.id}>
                        <td className="px-5 py-2">{o.seller_id ?? "—"} <span className="font-mono text-[11px] text-ink-400">{o.external_offer_id}</span></td>
                        <td className="px-3 py-2">{v ? [v.platform, v.edition, v.region].filter(Boolean).join(" · ") : "—"}</td>
                        <td className="px-3 py-2"><Pill tone={o.availability_status === "in_stock" ? "ok" : o.availability_status === "preorder" ? "info" : "muted"}>{o.availability_status.replace("_", " ")}</Pill></td>
                        <td className="px-3 py-2 text-right font-mono">{o.available_quantity}</td>
                        <td className="px-3 py-2 text-right font-mono">{money(o.supplier_price, o.currency)}</td>
                        <td className="px-5 py-2 text-xs text-ink-500">{fmtDate(o.last_synced_at)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {tab === "offers" && can("pricing:read") && <SellingPrices productId={p.id} canRecalc={can("pricing:manage")} />}

      {tab === "ai" && (
        <div className="space-y-6">
          {!c ? (
            <Card><Empty title="No AI content yet">Use “Generate AI content”. The supplier’s original text stays untouched either way.</Empty></Card>
          ) : (
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="Original (supplier)">
                <h3 className="font-semibold text-ink-900">{original?.original_title ?? p.supplier_products[0]?.original_title}</h3>
                <p className="mt-2 whitespace-pre-line text-sm text-ink-700">{plain(original?.original_description) || "—"}</p>
              </Card>
              <Card title={`AI version ${c.version}`} aside={<AiStatus value={c.content_status} />}>
                <h3 className="font-semibold text-ink-900">{c.title ?? "—"}</h3>
                {c.short_description && <p className="mt-2 text-sm font-medium text-ink-800">{c.short_description}</p>}
                <p className="mt-2 whitespace-pre-line text-sm text-ink-700">{plain(c.long_description) || "—"}</p>
                {c.tags.length > 0 && (
                  <div className="mt-3 flex flex-wrap gap-1">{c.tags.map((t) => <Pill key={t}>{t}</Pill>)}</div>
                )}
                <p className="mt-4 text-xs text-ink-500">
                  {c.model ?? "manual"}{c.prompt_version ? ` · prompt ${c.prompt_version}` : ""} · {fmtDate(c.generated_at)}
                </p>
                {Array.isArray(c.review_flags) && (c.review_flags as string[]).length > 0 && (
                  <div className="mt-3">
                    <Notice tone="bad">Needs a check: {(c.review_flags as string[]).join("; ")}</Notice>
                  </div>
                )}
              </Card>
            </div>
          )}
          <Card title="Version history">
            {!versions ? <div className="h-16 animate-pulse rounded-lg bg-ink-50" /> : versions.length === 0 ? <Empty title="No versions" /> : (
              <ul className="divide-y divide-ink-100 text-sm">
                {versions.map((v) => (
                  <li key={v.id} className="flex flex-wrap items-center gap-3 py-2">
                    <span className="font-mono text-xs">v{v.version}</span>
                    <AiStatus value={v.content_status} />
                    {v.is_current && <Pill tone="info">current</Pill>}
                    <span className="truncate text-ink-700">{v.title}</span>
                    <span className="ml-auto text-xs text-ink-500">{v.model ?? v.origin} · {fmtDate(v.created_at)}</span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      {tab === "categories" && <Categories p={p} options={cats} canEdit={can("catalogue:write")} onSaved={load} />}

      {tab === "media" && (
        <Card title={`${p.media.length} images and videos`}>
          {p.media.length === 0 ? <Empty title="No media" /> : (
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
              {p.media.map((m) => (
                <a key={m.id} href={m.source_url} target="_blank" rel="noreferrer" className="group block">
                  {m.media_type === "video" ? (
                    <div className="grid aspect-video place-items-center rounded-lg bg-navy-900 text-xs text-white">Video</div>
                  ) : (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={m.storage_url ?? m.source_url} alt={c?.image_alt_text?.[m.source_url] ?? ""} loading="lazy" className="aspect-video w-full rounded-lg object-cover ring-1 ring-ink-100" />
                  )}
                  <div className="mt-1 flex justify-between text-xs text-ink-500"><span className="capitalize">{m.media_type}</span><span>{m.processing_status}</span></div>
                </a>
              ))}
            </div>
          )}
        </Card>
      )}

      {tab === "seo" && (
        <Card title="Search engine preview" aside={c && <AiStatus value={c.content_status} />}>
          {!c ? <Empty title="No SEO content yet">Generated with the AI content.</Empty> : (
            <>
              <div className="rounded-lg border border-ink-100 p-4">
                <div className="text-xs text-emerald-700">omggamer.com › products › {p.slug}</div>
                <div className="mt-1 text-lg text-blue-800">{c.meta_title ?? c.title}</div>
                <div className="mt-1 text-sm text-ink-700">{c.meta_description}</div>
              </div>
              <dl className="mt-4 divide-y divide-ink-100">
                <Row k="Meta title" v={c.meta_title && <>{c.meta_title} <span className={cx("text-xs", c.meta_title.length > 60 ? "text-rose-600" : "text-ink-400")}>({c.meta_title.length}/60)</span></>} />
                <Row k="Meta description" v={c.meta_description && <>{c.meta_description} <span className={cx("text-xs", c.meta_description.length > 160 ? "text-rose-600" : "text-ink-400")}>({c.meta_description.length}/160)</span></>} />
                <Row k="Slug" v={<span className="font-mono">{p.slug}</span>} />
                <Row k="Tags" v={c.tags.join(", ")} />
              </dl>
            </>
          )}
        </Card>
      )}

      {tab === "publishing" && (
        <div className="space-y-6">
          <Card title="Storefront listings">
            {!history ? <div className="h-16 animate-pulse rounded-lg bg-ink-50" /> : history.mappings.length === 0 ? <Empty title="Not published to any storefront" /> : (
              <dl className="divide-y divide-ink-100">
                {history.mappings.map((m) => (
                  <Row key={m.storefront} k={m.storefront} v={<>{m.sync_status} · WooCommerce #{m.external_product_id ?? "—"} · {fmtDate(m.last_synced_at)}{m.last_error && <span className="block text-xs text-rose-700">{m.last_error}</span>}</>} />
                ))}
              </dl>
            )}
          </Card>
          <Card title="History">
            {!history ? null : history.events.length === 0 ? <Empty title="No publishing activity" /> : (
              <ul className="divide-y divide-ink-100 text-sm">
                {history.events.map((e, i) => (
                  <li key={i} className="flex flex-wrap gap-3 py-2">
                    <span className="w-36 shrink-0 text-xs text-ink-500">{fmtDate(e.created_at)}</span>
                    <Pill tone={e.status === "failed" ? "bad" : e.status === "success" || e.status === "completed" ? "ok" : "info"}>{e.status}</Pill>
                    <span>{e.kind === "queue" ? "Queued" : "Sync"} {e.action} → {e.storefront}</span>
                    {e.detail && <span className="w-full text-xs text-rose-700">{e.detail}</span>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      {tab === "activity" && (
        <Card title="Activity log">
          {!activity ? <div className="h-16 animate-pulse rounded-lg bg-ink-50" /> : activity.length === 0 ? <Empty title="No recorded activity" /> : (
            <ul className="divide-y divide-ink-100 text-sm">
              {activity.map((a) => (
                <li key={a.id} className="flex flex-wrap gap-3 py-2">
                  <span className="w-36 shrink-0 text-xs text-ink-500">{fmtDate(a.created_at)}</span>
                  <span className="font-medium text-ink-900">{a.summary || a.action}</span>
                  <span className="ml-auto text-xs text-ink-500">{a.actor_email ?? "system"}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}
    </>
  );
}

function Overview({ p, onSaved, canEdit }: { p: ProductDetail; onSaved: () => Promise<void>; canEdit: boolean }) {
  const [form, setForm] = useState({
    canonical_title: p.canonical_title,
    developer: p.developer ?? "",
    publisher: p.publisher ?? "",
    brand: p.brand ?? "",
    release_date: p.release_date ?? "",
  });
  const [msg, setMsg] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);

  async function save(e: FormEvent) {
    e.preventDefault();
    setMsg(null);
    try {
      await api(`/catalogue/products/${p.id}`, {
        method: "PATCH",
        json: {
          canonical_title: form.canonical_title,
          developer: form.developer || null,
          publisher: form.publisher || null,
          brand: form.brand || null,
          release_date: form.release_date || null,
        },
      });
      setMsg({ tone: "ok", text: "Saved. Supplier data is unchanged; these edits are kept on future imports." });
      await onSaved();
    } catch (err) {
      setMsg({ tone: "bad", text: err instanceof ApiError ? err.message : "Could not save." });
    }
  }

  const prices = p.offers.filter((o) => ["in_stock", "preorder"].includes(o.availability_status) && o.available_quantity > 0);
  const cheapest = [...prices].sort((a, b) => Number(a.supplier_price) - Number(b.supplier_price))[0];

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
      <Card title="Product facts">
        <form onSubmit={save} className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2"><Field label="Title" value={form.canonical_title} onChange={(e) => setForm({ ...form, canonical_title: e.target.value })} disabled={!canEdit} required /></div>
          <Field label="Developer" value={form.developer} onChange={(e) => setForm({ ...form, developer: e.target.value })} disabled={!canEdit} />
          <Field label="Publisher" value={form.publisher} onChange={(e) => setForm({ ...form, publisher: e.target.value })} disabled={!canEdit} />
          <Field label="Brand" value={form.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} disabled={!canEdit} />
          <Field label="Release date" type="date" value={form.release_date} onChange={(e) => setForm({ ...form, release_date: e.target.value })} disabled={!canEdit} />
          {canEdit && <div className="sm:col-span-2"><Button type="submit">Save</Button></div>}
        </form>
        {msg && <div className="mt-4"><Notice tone={msg.tone}>{msg.text}</Notice></div>}
      </Card>
      <Card title="Summary">
        <dl className="divide-y divide-ink-100">
          <Row k="Variants" v={p.variants.length} />
          <Row k="Offers" v={`${p.offers.length} (${prices.length} available)`} />
          <Row k="Lowest price" v={cheapest ? money(cheapest.supplier_price, cheapest.currency) : "—"} />
          <Row k="Categories" v={p.categories.map((c) => c.name).join(", ")} />
          <Row k="Slug" v={<span className="font-mono text-xs">{p.slug}</span>} />
          <Row k="Created" v={fmtDate(p.created_at)} />
          <Row k="Updated" v={fmtDate(p.updated_at)} />
          {p.merged_from.length > 0 && <Row k="Merged from" v={`${p.merged_from.length} product(s)`} />}
        </dl>
      </Card>
    </div>
  );
}

function Categories({ p, options, canEdit, onSaved }: { p: ProductDetail; options: { id: string; name: string }[]; canEdit: boolean; onSaved: () => Promise<void> }) {
  const [chosen, setChosen] = useState<string[]>(p.categories.map((c) => c.id));
  const [msg, setMsg] = useState<string | null>(null);
  const attrs: [string, string[]][] = [
    ["Platform", [...new Set(p.variants.map((v) => v.platform).filter(Boolean) as string[])]],
    ["Edition", [...new Set(p.variants.map((v) => v.edition ?? "Standard"))]],
    ["Region", [...new Set(p.variants.map((v) => v.region).filter(Boolean) as string[])]],
    ["Activation", [...new Set(p.variants.map((v) => v.activation_type).filter(Boolean) as string[])]],
    ["Language", [...new Set(p.variants.map((v) => v.language).filter(Boolean) as string[])]],
    ["Developer", p.developer ? [p.developer] : []],
    ["Publisher", p.publisher ? [p.publisher] : []],
  ];

  async function save() {
    setMsg(null);
    try {
      await api(`/catalogue/products/${p.id}/categories`, { method: "PUT", json: { category_ids: chosen } });
      setMsg("Categories saved.");
      await onSaved();
    } catch (err) {
      setMsg(err instanceof ApiError ? err.message : "Could not save.");
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Card title="Categories">
        <ul className="mb-4 space-y-1 text-sm">
          {p.categories.length === 0 && <li className="text-ink-500">No categories yet.</li>}
          {p.categories.map((c) => (
            <li key={c.id} className="flex items-center gap-2">{c.name} <Pill>{c.source}</Pill></li>
          ))}
        </ul>
        {canEdit && (
          <>
            <select
              multiple
              aria-label="Categories"
              className="h-48 w-full rounded-lg border border-ink-200 p-2 text-sm"
              value={chosen}
              onChange={(e) => setChosen([...e.target.selectedOptions].map((o) => o.value))}
            >
              {options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </select>
            <p className="mt-1 text-xs text-ink-500">Hold Ctrl/⌘ to choose several. Manage the tree under Categories.</p>
            <Button className="mt-3" onClick={save}>Save categories</Button>
            {msg && <p className="mt-2 text-sm text-ink-700">{msg}</p>}
          </>
        )}
      </Card>
      <Card title="Attributes" aside={<span className="text-xs text-ink-500">from supplier data</span>}>
        <dl className="divide-y divide-ink-100">
          {attrs.map(([k, v]) => <Row key={k} k={k} v={v.length ? v.join(", ") : null} />)}
        </dl>
      </Card>
    </div>
  );
}

type PriceRow = { variant_id: string; label: string; storefront: string; currency: string; cost: string | null; cost_currency: string | null; preview_price: string | null; stored_price: string | null; rule: string | null; note: string | null };

function SellingPrices({ productId, canRecalc }: { productId: string; canRecalc: boolean }) {
  const [rows, setRows] = useState<PriceRow[] | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const load = useCallback(() => api<PriceRow[]>(`/pricing/products/${productId}`).then(setRows).catch(() => setRows([])), [productId]);
  useEffect(() => {
    void load();
  }, [load]);
  async function recalc() {
    const r = await api<{ changed?: number }>("/pricing/recalculate", { method: "POST", json: { product_ids: [productId] } });
    setMsg(`Prices saved (${r.changed ?? 0} changed).`);
    await load();
  }
  return (
    <div className="mt-6">
      <Card title="Selling prices" aside={canRecalc && <Button variant="ghost" className="h-8" onClick={recalc}>Recalculate</Button>}>
        {msg && <p className="mb-2 text-sm text-emerald-700">{msg}</p>}
        {!rows ? <div className="h-16 animate-pulse rounded-lg bg-ink-50" /> : rows.length === 0 ? <Empty title="No variants" /> : (
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead><tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase text-ink-500"><th className="px-5 py-2">Variant</th><th className="px-3 py-2">Storefront</th><th className="px-3 py-2 text-right">Cost</th><th className="px-3 py-2 text-right">Calculated</th><th className="px-3 py-2 text-right">Saved</th><th className="px-5 py-2">Rule</th></tr></thead>
              <tbody className="divide-y divide-ink-100">
                {rows.map((r) => (
                  <tr key={`${r.variant_id}${r.storefront}`}>
                    <td className="px-5 py-2">{r.label}</td>
                    <td className="px-3 py-2">{r.storefront}</td>
                    <td className="px-3 py-2 text-right font-mono">{money(r.cost, r.cost_currency)}</td>
                    <td className="px-3 py-2 text-right font-mono">{r.preview_price ? money(r.preview_price, r.currency) : <span className="text-xs text-ink-500">{r.note}</span>}</td>
                    <td className="px-3 py-2 text-right font-mono">{money(r.stored_price, r.currency)}</td>
                    <td className="px-5 py-2 text-ink-600">{r.rule ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
