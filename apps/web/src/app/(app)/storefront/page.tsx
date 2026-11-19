"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Button, Card, Empty, Notice, PageHeader, Pill, cx, fmtDate } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useSession } from "@/lib/session";

const STORE = "omggamer-staging";
const BASE = `/storefronts/${STORE}/home-content`;

type Item = { product_id: string; listing: string; title: string; sku: string | null; wc_id: string | null; live?: boolean };
type Section = { name: string; items: Item[] };
type Banner = {
  title: string;
  highlight: string;
  subtitle: string;
  tags: string[];
  button: string;
  url: string;
  image: string;
  image_mobile: string | null;
  active: boolean;
  starts_at: string | null;
  ends_at: string | null;
};
type Content = { sections: Record<string, Section>; banners: Banner[]; pushed_at: string | null };

const EMPTY_BANNER: Banner = { title: "", highlight: "", subtitle: "", tags: [], button: "Shop now", url: "", image: "", image_mobile: null, active: true, starts_at: null, ends_at: null };
const toLocal = (iso: string | null) => (iso ? new Date(iso).toISOString().slice(0, 16) : "");
const fromLocal = (v: string) => (v ? new Date(v).toISOString() : null);

function move<T>(list: T[], i: number, d: number): T[] {
  const j = i + d;
  if (j < 0 || j >= list.length) return list;
  const out = list.slice();
  const [a] = out.splice(i, 1);
  out.splice(j, 0, a as T);
  return out;
}

function Picker({ onPick, taken }: { onPick: (i: Item) => void; taken: Set<string> }) {
  const [q, setQ] = useState("");
  const [res, setRes] = useState<Item[] | null>(null);
  const t = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => {
    if (t.current) clearTimeout(t.current);
    if (q.trim().length < 2) {
      setRes(null);
      return;
    }
    t.current = setTimeout(() => {
      api<Item[]>(`${BASE}/search?q=${encodeURIComponent(q.trim())}`).then(setRes).catch(() => setRes([]));
    }, 250);
  }, [q]);
  return (
    <div className="relative">
      <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Add a live product: search name or SKU" aria-label="Search live products" className="h-9 w-full rounded-lg border border-ink-200 px-3 text-sm" />
      {res && (
        <ul className="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-lg border border-ink-100 bg-white py-1 shadow-lg">
          {res.length === 0 ? (
            <li className="px-3 py-2 text-sm text-ink-500">No live product matches. Only products published on the store can be chosen.</li>
          ) : (
            res.map((r) => {
              const k = `${r.product_id}|${r.listing}`;
              const has = taken.has(k);
              return (
                <li key={k}>
                  <button type="button" disabled={has} onClick={() => { onPick(r); setQ(""); setRes(null); }} className={cx("flex w-full items-center gap-2 px-3 py-2 text-left text-sm", has ? "cursor-default text-ink-300" : "hover:bg-ink-50")}>
                    <span className="min-w-0 flex-1 truncate">{r.title}</span>
                    <span className="font-mono text-xs text-ink-500">{r.sku}</span>
                    {has && <span className="text-xs">added</span>}
                  </button>
                </li>
              );
            })
          )}
        </ul>
      )}
    </div>
  );
}

export default function StorefrontPage() {
  const { can } = useSession();
  const edit = can("publishing:manage");
  const [tab, setTab] = useState<"sections" | "banners">("sections");
  const [data, setData] = useState<Content | null>(null);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);

  const load = useCallback(() => api<Content>(BASE).then((d) => { setData(d); setDirty(false); }).catch((e) => setNotice({ tone: "bad", text: e instanceof ApiError ? e.message : "Could not load." })), []);
  useEffect(() => {
    void load();
  }, [load]);

  const change = (fn: (d: Content) => Content) => {
    setData((d) => (d ? fn(d) : d));
    setDirty(true);
    setNotice(null);
  };
  const setItems = (key: string, items: Item[]) => change((d) => ({ ...d, sections: { ...d.sections, [key]: { name: d.sections[key]?.name ?? key, items } } }));
  const setBanner = (i: number, b: Partial<Banner>) => change((d) => ({ ...d, banners: d.banners.map((x, k) => (k === i ? { ...x, ...b } : x)) }));

  async function save() {
    if (!data) return;
    setBusy(true);
    setNotice(null);
    try {
      const sections = Object.fromEntries(Object.entries(data.sections).map(([k, s]) => [k, s.items.filter((i) => i.live !== false).map((i) => ({ product_id: i.product_id, listing: i.listing }))]));
      const banners = data.banners.map((b) => ({ ...b, image_mobile: b.image_mobile || null, tags: b.tags.filter(Boolean) }));
      await api(BASE, { method: "PUT", json: { sections, banners } });
      setNotice({ tone: "ok", text: "Saved and sent to omggamer.com. The home page shows it now." });
      await load();
    } catch (e) {
      setNotice({ tone: "bad", text: e instanceof ApiError ? e.message : "Could not save." });
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader
        title="Storefront"
        description="Choose what the omggamer.com home page shows: the products in each section and the hero banners. Only products live on the store can be chosen. A section left empty stays automatic."
        actions={edit ? <Button disabled={busy || !dirty || !data} onClick={() => void save()}>{busy ? "Saving…" : dirty ? "Save and publish" : "Saved"}</Button> : undefined}
      />
      {notice && <div className="mb-4"><Notice tone={notice.tone}>{notice.text}</Notice></div>}
      {data?.pushed_at && <p className="-mt-2 mb-4 text-xs text-ink-500">Last sent to the store {fmtDate(data.pushed_at)}</p>}

      <div className="mb-5 flex gap-1 border-b border-ink-100">
        {([["sections", "Home page sections"], ["banners", "Banners"]] as const).map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} className={cx("border-b-2 px-3 py-2 text-sm font-medium", tab === k ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-900")}>{l}</button>
        ))}
      </div>

      {!data ? (
        <div className="h-40 animate-pulse rounded-xl bg-ink-50" />
      ) : tab === "sections" ? (
        <div className="grid gap-6 lg:grid-cols-2">
          {Object.entries(data.sections).map(([key, s]) => {
            const taken = new Set(s.items.map((i) => `${i.product_id}|${i.listing}`));
            return (
              <Card key={key} title={s.name} aside={<span className="text-xs text-ink-500">{s.items.length ? `${s.items.length} chosen` : "Automatic"}</span>}>
                {s.items.length === 0 ? (
                  <p className="mb-3 text-sm text-ink-500">Nothing chosen: the store picks these products automatically.</p>
                ) : (
                  <ol className="mb-3 divide-y divide-ink-100">
                    {s.items.map((it, i) => (
                      <li key={`${it.product_id}|${it.listing}`} className="flex items-center gap-2 py-2 text-sm">
                        <span className="w-5 text-right text-xs text-ink-500">{i + 1}</span>
                        <span className={cx("min-w-0 flex-1 truncate", it.live === false && "text-rose-700 line-through")}>{it.title}</span>
                        {it.live === false && <Pill tone="bad">Not live</Pill>}
                        {edit && (
                          <span className="flex shrink-0 gap-1">
                            <button type="button" aria-label="Move up" disabled={i === 0} onClick={() => setItems(key, move(s.items, i, -1))} className="h-7 w-7 rounded border border-ink-200 text-ink-700 disabled:opacity-30">↑</button>
                            <button type="button" aria-label="Move down" disabled={i === s.items.length - 1} onClick={() => setItems(key, move(s.items, i, 1))} className="h-7 w-7 rounded border border-ink-200 text-ink-700 disabled:opacity-30">↓</button>
                            <button type="button" aria-label="Remove" onClick={() => setItems(key, s.items.filter((_, k) => k !== i))} className="h-7 w-7 rounded border border-ink-200 text-rose-700">×</button>
                          </span>
                        )}
                      </li>
                    ))}
                  </ol>
                )}
                {edit && s.items.length < 24 && <Picker taken={taken} onPick={(r) => setItems(key, [...s.items, { ...r, live: true }])} />}
                {s.items.length > 0 && edit && (
                  <button type="button" onClick={() => setItems(key, [])} className="mt-2 text-xs font-semibold text-ink-500 hover:text-ink-900">Clear (back to automatic)</button>
                )}
              </Card>
            );
          })}
        </div>
      ) : (
        <div className="space-y-4">
          {data.banners.length === 0 && <Empty title="No banners chosen">The home page keeps its current slides until you add a banner.</Empty>}
          {data.banners.map((b, i) => (
            <Card
              key={i}
              title={`Banner ${i + 1}${b.title ? `: ${b.title}` : ""}`}
              aside={
                edit ? (
                  <span className="flex items-center gap-1">
                    <label className="mr-2 flex items-center gap-1 text-xs text-ink-700"><input type="checkbox" checked={b.active} onChange={(e) => setBanner(i, { active: e.target.checked })} /> Show</label>
                    <button type="button" aria-label="Move up" disabled={i === 0} onClick={() => change((d) => ({ ...d, banners: move(d.banners, i, -1) }))} className="h-7 w-7 rounded border border-ink-200 disabled:opacity-30">↑</button>
                    <button type="button" aria-label="Move down" disabled={i === data.banners.length - 1} onClick={() => change((d) => ({ ...d, banners: move(d.banners, i, 1) }))} className="h-7 w-7 rounded border border-ink-200 disabled:opacity-30">↓</button>
                    <button type="button" aria-label="Delete banner" onClick={() => change((d) => ({ ...d, banners: d.banners.filter((_, k) => k !== i) }))} className="h-7 w-7 rounded border border-ink-200 text-rose-700">×</button>
                  </span>
                ) : undefined
              }
            >
              <div className="grid gap-4 md:grid-cols-[240px_1fr]">
                <div className="aspect-[16/9] overflow-hidden rounded-lg bg-ink-900">
                  {b.image ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={b.image} alt="" className="h-full w-full object-cover" />
                  ) : (
                    <div className="grid h-full place-items-center text-xs text-ink-300">Image preview</div>
                  )}
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  {(
                    [
                      ["title", "Title", "Grand Theft Auto VI"],
                      ["highlight", "Highlighted words (after the title)", "is here"],
                      ["subtitle", "Text", "One or two short sentences"],
                      ["button", "Button text", "Shop now"],
                      ["url", "Link", "https://omggamer.com/products/…"],
                      ["image", "Image URL (wide, 1600×900)", "https://omggamer.com/wp-content/uploads/…"],
                      ["image_mobile", "Mobile image URL (optional)", "https://…"],
                    ] as const
                  ).map(([k, label, ph]) => (
                    <label key={k} className={cx("block text-xs font-medium text-ink-700", (k === "subtitle" || k === "image") && "sm:col-span-2")}>
                      {label}
                      <input disabled={!edit} value={(b[k] as string | null) ?? ""} placeholder={ph} onChange={(e) => setBanner(i, { [k]: e.target.value } as Partial<Banner>)} className="mt-1 h-9 w-full rounded-lg border border-ink-200 px-3 text-sm font-normal" />
                    </label>
                  ))}
                  <label className="block text-xs font-medium text-ink-700">
                    Tags (comma separated, up to 3)
                    <input disabled={!edit} value={b.tags.join(", ")} placeholder="Hot deal, New in" onChange={(e) => setBanner(i, { tags: e.target.value.split(",").map((x) => x.trim()).slice(0, 3) })} className="mt-1 h-9 w-full rounded-lg border border-ink-200 px-3 text-sm font-normal" />
                  </label>
                  <div className="grid grid-cols-2 gap-2">
                    <label className="block text-xs font-medium text-ink-700">
                      Show from
                      <input disabled={!edit} type="datetime-local" value={toLocal(b.starts_at)} onChange={(e) => setBanner(i, { starts_at: fromLocal(e.target.value) })} className="mt-1 h-9 w-full rounded-lg border border-ink-200 px-2 text-sm font-normal" />
                    </label>
                    <label className="block text-xs font-medium text-ink-700">
                      Until
                      <input disabled={!edit} type="datetime-local" value={toLocal(b.ends_at)} onChange={(e) => setBanner(i, { ends_at: fromLocal(e.target.value) })} className="mt-1 h-9 w-full rounded-lg border border-ink-200 px-2 text-sm font-normal" />
                    </label>
                  </div>
                </div>
              </div>
            </Card>
          ))}
          {edit && data.banners.length < 8 && (
            <Button variant="ghost" onClick={() => change((d) => ({ ...d, banners: [...d.banners, { ...EMPTY_BANNER }] }))}>Add banner</Button>
          )}
        </div>
      )}
    </>
  );
}
