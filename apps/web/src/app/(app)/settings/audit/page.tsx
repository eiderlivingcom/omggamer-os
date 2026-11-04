"use client";

import { useCallback, useEffect, useState } from "react";
import { Button, Card, Empty, Notice, fmtDate } from "@/components/ui";
import { api, type AuditEntry } from "@/lib/api";

type Page = { items: AuditEntry[]; next_before: string | null };

export default function AuditPage() {
  const [items, setItems] = useState<AuditEntry[]>([]);
  const [next, setNext] = useState<string | null>(null);
  const [action, setAction] = useState("");
  const [actor, setActor] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(
    async (before?: string) => {
      setLoading(true);
      try {
        const q = new URLSearchParams({ limit: "50" });
        if (action) q.set("action", action);
        if (actor) q.set("actor", actor);
        if (before) q.set("before", before);
        const page = await api<Page>(`/audit-logs?${q}`);
        setItems((prev) => (before ? [...prev, ...page.items] : page.items));
        setNext(page.next_before);
        setError(null);
      } catch (e) {
        setError(e instanceof Error ? e.message : "Could not load the audit log.");
      } finally {
        setLoading(false);
      }
    },
    [action, actor],
  );

  useEffect(() => {
    const t = setTimeout(() => void load(), 250);
    return () => clearTimeout(t);
  }, [load]);

  return (
    <Card title="Audit log">
      <div className="mb-4 flex flex-wrap gap-2">
        <select aria-label="Filter by action" className="h-9 rounded-lg border border-ink-200 bg-white px-2 text-sm" value={action} onChange={(e) => setAction(e.target.value)}>
          <option value="">All actions</option>
          <option value="auth.">Sign-ins and security</option>
          <option value="user.">User changes</option>
        </select>
        <input
          aria-label="Filter by person"
          placeholder="Filter by e-mail"
          className="h-9 min-w-0 flex-1 rounded-lg border border-ink-200 px-3 text-sm sm:max-w-xs"
          value={actor}
          onChange={(e) => setActor(e.target.value)}
        />
      </div>
      {error && <Notice tone="bad">{error}</Notice>}
      {!loading && items.length === 0 ? (
        <Empty title="No entries match" />
      ) : (
        <div className="-mx-5 overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                <th className="px-5 py-2">When</th>
                <th className="px-3 py-2">Who</th>
                <th className="px-3 py-2">What</th>
                <th className="px-5 py-2">IP</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {items.map((a) => (
                <tr key={a.id}>
                  <td className="whitespace-nowrap px-5 py-2.5 text-xs text-ink-500">{fmtDate(a.created_at)}</td>
                  <td className="px-3 py-2.5">{a.actor_email ?? "System"}</td>
                  <td className="px-3 py-2.5">
                    <div className="text-ink-900">{a.summary || a.action}</div>
                    <div className="font-mono text-[11px] text-ink-500">{a.action}</div>
                  </td>
                  <td className="px-5 py-2.5 font-mono text-xs text-ink-500">{a.ip ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {next && (
        <div className="mt-4 text-center">
          <Button variant="ghost" disabled={loading} onClick={() => load(next)}>
            {loading ? "Loading…" : "Load older entries"}
          </Button>
        </div>
      )}
    </Card>
  );
}
