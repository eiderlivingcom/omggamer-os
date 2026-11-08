"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Button, Card, Empty, Notice, PageHeader, Pill, cx } from "@/components/ui";
import { api, ApiError, type CategoryNode } from "@/lib/api";
import { useSession } from "@/lib/session";

type Rule = { id: string; attribute: string; value: string; category_id: string; category_name: string | null };
type Attributes = Record<string, { value: string; products: number }[]>;

function flatten(nodes: CategoryNode[], depth = 0): (CategoryNode & { depth: number })[] {
  return nodes.flatMap((n) => [{ ...n, depth }, ...flatten(n.children, depth + 1)]);
}

export default function CategoriesPage() {
  const { can } = useSession();
  const write = can("catalogue:write");
  const [tree, setTree] = useState<CategoryNode[] | null>(null);
  const [rules, setRules] = useState<Rule[]>([]);
  const [attrs, setAttrs] = useState<Attributes | null>(null);
  const [tab, setTab] = useState<"tree" | "rules" | "attributes">("tree");
  const [form, setForm] = useState({ name: "", parent_id: "" });
  const [ruleForm, setRuleForm] = useState({ attribute: "genre", value: "", category_id: "" });
  const [editing, setEditing] = useState<{ id: string; name: string; parent_id: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [t, r] = await Promise.all([api<CategoryNode[]>("/catalogue/categories"), api<Rule[]>("/catalogue/category-rules")]);
      setTree(t);
      setRules(r);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load categories.");
    }
  }, []);
  useEffect(() => {
    void load();
  }, [load]);
  useEffect(() => {
    if (tab === "attributes" && !attrs) api<Attributes>("/catalogue/attributes?limit=200").then(setAttrs).catch(() => undefined);
  }, [tab, attrs]);

  const flat = tree ? flatten(tree) : [];

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

  function create(e: FormEvent) {
    e.preventDefault();
    void act(async () => {
      await api("/catalogue/categories", { method: "POST", json: { name: form.name, parent_id: form.parent_id || null } });
      setForm({ name: "", parent_id: form.parent_id });
    }, "Category created.");
  }

  function saveEdit(e: FormEvent) {
    e.preventDefault();
    if (!editing) return;
    void act(async () => {
      await api(`/catalogue/categories/${editing.id}`, {
        method: "PATCH",
        json: editing.parent_id ? { name: editing.name, parent_id: editing.parent_id } : { name: editing.name, move_to_root: true },
      });
      setEditing(null);
    }, "Category saved.");
  }

  function addRule(e: FormEvent) {
    e.preventDefault();
    void act(async () => {
      await api("/catalogue/category-rules", { method: "POST", json: ruleForm });
      setRuleForm({ ...ruleForm, value: "" });
    }, "Rule added and applied to existing products.");
  }

  return (
    <>
      <PageHeader
        title="Categories"
        description="Configurable category tree, automatic category rules from supplier data, and the attribute values in the catalogue."
      />
      {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
      {notice && <div className="mb-4"><Notice tone="ok">{notice}</Notice></div>}
      <div className="mb-5 flex gap-1 border-b border-ink-100">
        {([["tree", "Category tree"], ["rules", "Category rules"], ["attributes", "Attributes"]] as const).map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} className={cx("border-b-2 px-3 py-2 text-sm font-medium", tab === k ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-900")}>
            {l}
          </button>
        ))}
      </div>

      {tab === "tree" && (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
          <Card title="Category tree">
            {!tree ? <div className="h-40 animate-pulse rounded-lg bg-ink-50" /> : flat.length === 0 ? (
              <Empty title="No categories yet">Create a top-level category such as “Games”, then add subcategories.</Empty>
            ) : (
              <ul className="divide-y divide-ink-100">
                {flat.map((c) => (
                  <li key={c.id} className="flex items-center gap-3 py-2" style={{ paddingLeft: c.depth * 20 }}>
                    {editing?.id === c.id ? (
                      <form onSubmit={saveEdit} className="flex flex-1 flex-wrap items-center gap-2">
                        <input value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} className="h-8 min-w-0 flex-1 rounded-md border border-ink-200 px-2 text-sm" />
                        <select value={editing.parent_id} onChange={(e) => setEditing({ ...editing, parent_id: e.target.value })} className="h-8 rounded-md border border-ink-200 bg-white px-2 text-sm" aria-label="Parent">
                          <option value="">Top level</option>
                          {flat.filter((x) => x.id !== c.id).map((x) => <option key={x.id} value={x.id}>{"— ".repeat(x.depth)}{x.name}</option>)}
                        </select>
                        <Button className="h-8">Save</Button>
                        <Button type="button" variant="ghost" className="h-8" onClick={() => setEditing(null)}>Cancel</Button>
                      </form>
                    ) : (
                      <>
                        <span className="flex-1 text-sm font-medium text-ink-900">{c.name}</span>
                        <Link href={`/catalogue?category_id=${c.id}`} className="text-xs text-ink-500 hover:text-brand-700">{c.product_count.toLocaleString()} products</Link>
                        {write && (
                          <>
                            <button className="text-xs font-semibold text-brand-700" onClick={() => setEditing({ id: c.id, name: c.name, parent_id: c.parent_id ?? "" })}>Edit</button>
                            <button
                              className="text-xs font-semibold text-rose-700"
                              onClick={() => window.confirm(`Delete “${c.name}”? Products keep their other categories.`) && act(() => api(`/catalogue/categories/${c.id}`, { method: "DELETE" }), "Category deleted.")}
                            >
                              Delete
                            </button>
                          </>
                        )}
                      </>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          {write && (
            <Card title="Add a category">
              <form onSubmit={create} className="space-y-3">
                <label className="block text-xs font-semibold text-ink-700">
                  Name
                  <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal" placeholder="e.g. RPG" />
                </label>
                <label className="block text-xs font-semibold text-ink-700">
                  Parent
                  <select value={form.parent_id} onChange={(e) => setForm({ ...form, parent_id: e.target.value })} className="mt-1 h-9 w-full rounded-md border border-ink-200 bg-white px-2 text-sm font-normal">
                    <option value="">Top level</option>
                    {flat.map((x) => <option key={x.id} value={x.id}>{"— ".repeat(x.depth)}{x.name}</option>)}
                  </select>
                </label>
                <Button type="submit">Add category</Button>
              </form>
            </Card>
          )}
        </div>
      )}

      {tab === "rules" && (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
          <Card title="Category rules" aside={write && <Button variant="ghost" className="h-8" onClick={() => act(() => api("/catalogue/category-rules/apply", { method: "POST" }), "Rules applied to all products.")}>Apply to all products</Button>}>
            {rules.length === 0 ? <Empty title="No rules yet">Example: supplier genre “RPG” → Games › PC Games › RPG. Rules run on every import and nightly.</Empty> : (
              <ul className="divide-y divide-ink-100 text-sm">
                {rules.map((r) => (
                  <li key={r.id} className="flex items-center gap-3 py-2">
                    <Pill>{r.attribute.replace("_", " ")}</Pill>
                    <span className="font-medium">{r.value}</span>
                    <span className="text-ink-400">→</span>
                    <span>{r.category_name}</span>
                    {write && <button className="ml-auto text-xs font-semibold text-rose-700" onClick={() => act(() => api(`/catalogue/category-rules/${r.id}`, { method: "DELETE" }), "Rule removed.")}>Remove</button>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          {write && (
            <Card title="Add a rule">
              <form onSubmit={addRule} className="space-y-3">
                <label className="block text-xs font-semibold text-ink-700">
                  When the supplier’s
                  <select value={ruleForm.attribute} onChange={(e) => setRuleForm({ ...ruleForm, attribute: e.target.value })} className="mt-1 h-9 w-full rounded-md border border-ink-200 bg-white px-2 text-sm font-normal">
                    <option value="genre">Genre</option><option value="platform">Platform</option><option value="product_type">Product type</option><option value="tag">Tag</option>
                  </select>
                </label>
                <label className="block text-xs font-semibold text-ink-700">
                  is
                  <input required value={ruleForm.value} onChange={(e) => setRuleForm({ ...ruleForm, value: e.target.value })} className="mt-1 h-9 w-full rounded-md border border-ink-200 px-2 text-sm font-normal" placeholder="RPG" />
                </label>
                <label className="block text-xs font-semibold text-ink-700">
                  put the product in
                  <select required value={ruleForm.category_id} onChange={(e) => setRuleForm({ ...ruleForm, category_id: e.target.value })} className="mt-1 h-9 w-full rounded-md border border-ink-200 bg-white px-2 text-sm font-normal">
                    <option value="">Choose a category</option>
                    {flat.map((x) => <option key={x.id} value={x.id}>{"— ".repeat(x.depth)}{x.name}</option>)}
                  </select>
                </label>
                <Button type="submit">Add rule</Button>
              </form>
            </Card>
          )}
        </div>
      )}

      {tab === "attributes" && (
        <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
          {!attrs ? <div className="h-40 animate-pulse rounded-lg bg-ink-50 md:col-span-2 xl:col-span-4" /> : Object.entries(attrs).map(([name, values]) => (
            <Card key={name} title={name.replace("_", " ").replace(/^./, (c) => c.toUpperCase())}>
              {values.length === 0 ? <p className="text-sm text-ink-500">No values yet.</p> : (
                <ul className="max-h-72 space-y-1 overflow-y-auto text-sm">
                  {values.map((v) => (
                    <li key={v.value} className="flex justify-between gap-3">
                      <span className="truncate text-ink-800">{v.value}</span>
                      <span className="font-mono text-xs text-ink-500">{v.products.toLocaleString()}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          ))}
        </div>
      )}
    </>
  );
}
