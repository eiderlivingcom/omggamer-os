"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Button, Card, Field, Notice, Pill, fmtDate } from "@/components/ui";
import { api, ApiError, type Connection, type Environment, type SupplierConnections } from "@/lib/api";
import { useSession } from "@/lib/session";

type Status = { tone: "ok" | "warn" | "bad" | "muted"; label: string };
const NOT_SET: Status = { tone: "muted", label: "Not set" };
const STATUS: Record<string, Status> = {
  connected: { tone: "ok", label: "Connected" },
  untested: { tone: "warn", label: "Saved, not tested" },
  failed: { tone: "bad", label: "Connection failed" },
  not_configured: { tone: "muted", label: "Not set" },
};

function ConnectionRow({ c, active, onSaved }: { c: Connection; active: boolean; onSaved: () => Promise<void> }) {
  const [key, setKey] = useState("");
  const [busy, setBusy] = useState<"save" | "test" | null>(null);
  const [msg, setMsg] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);
  const s = STATUS[c.status] ?? NOT_SET;

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy("save");
    setMsg(null);
    try {
      await api("/suppliers/kinguin/connection", { method: "PUT", json: { environment: c.environment, api_key: key } });
      setKey("");
      const t = await api<{ ok: boolean; message: string }>("/suppliers/kinguin/connection/test", { method: "POST", json: { environment: c.environment } });
      setMsg({ tone: t.ok ? "ok" : "bad", text: t.ok ? `Saved. ${t.message}` : `Saved, but the test failed: ${t.message}` });
      await onSaved();
    } catch (err) {
      setMsg({ tone: "bad", text: err instanceof ApiError ? err.message : "Could not save the key." });
    } finally {
      setBusy(null);
    }
  }

  async function test() {
    setBusy("test");
    setMsg(null);
    try {
      const t = await api<{ ok: boolean; message: string }>("/suppliers/kinguin/connection/test", { method: "POST", json: { environment: c.environment } });
      setMsg({ tone: t.ok ? "ok" : "bad", text: t.message });
      await onSaved();
    } catch (err) {
      setMsg({ tone: "bad", text: err instanceof ApiError ? err.message : "Test failed." });
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="py-4 first:pt-0 last:pb-0">
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <span className="text-sm font-semibold capitalize text-ink-900">{c.environment === "live" ? "Production" : "Sandbox"}</span>
        <Pill tone={s.tone}>{s.label}</Pill>
        {active && <Pill tone="ok">Used by imports</Pill>}
        {c.last_checked_at && <span className="text-xs text-ink-500">Checked {fmtDate(c.last_checked_at)}</span>}
      </div>
      {c.last_error && c.status === "failed" && <p className="mb-3 text-xs text-rose-700">{c.last_error}</p>}
      <form onSubmit={save} className="flex flex-col gap-2 sm:flex-row sm:items-end">
        <div className="flex-1">
          <Field
            label={c.configured ? "Replace API key" : "API key"}
            type="password"
            autoComplete="off"
            spellCheck={false}
            placeholder={c.configured ? "•••••••• stored — enter a new key to replace it" : "Paste the key from Kinguin → My Stores"}
            value={key}
            onChange={(e) => setKey(e.target.value)}
            minLength={8}
            required
          />
        </div>
        <div className="flex gap-2">
          <Button type="submit" disabled={busy !== null || key.length < 8}>{busy === "save" ? "Saving…" : "Save and test"}</Button>
          {c.configured && (
            <Button type="button" variant="ghost" onClick={test} disabled={busy !== null}>
              {busy === "test" ? "Testing…" : "Test"}
            </Button>
          )}
        </div>
      </form>
      {msg && <div className="mt-3"><Notice tone={msg.tone}>{msg.text}</Notice></div>}
    </div>
  );
}

export default function CredentialsPage() {
  const { can } = useSession();
  const [data, setData] = useState<SupplierConnections | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setData(await api<SupplierConnections>("/suppliers/kinguin/connection"));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load connections.");
    }
  }, []);

  useEffect(() => {
    if (can("credentials:manage")) void load();
  }, [can, load]);

  if (!can("credentials:manage")) return <Notice>Only a Super Admin can manage API credentials.</Notice>;

  async function switchEnv(environment: Environment) {
    setError(null);
    try {
      setData(await api<SupplierConnections>("/suppliers/kinguin/environment", { method: "PUT", json: { environment } }));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not switch environment.");
    }
  }

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
      <Card
        title="Kinguin eCommerce API"
        aside={
          data && (
            <label className="flex items-center gap-2 text-xs font-semibold text-ink-700">
              Imports use
              <select
                className="h-8 rounded-md border border-ink-200 bg-white px-2 text-sm"
                value={data.active_environment}
                onChange={(e) => switchEnv(e.target.value as Environment)}
              >
                <option value="live">Production</option>
                <option value="sandbox">Sandbox</option>
              </select>
            </label>
          )
        }
      >
        {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
        {!data ? (
          <div className="h-32 animate-pulse rounded-lg bg-ink-50" />
        ) : (
          <div className="divide-y divide-ink-100">
            {data.connections.map((c) => (
              <ConnectionRow key={c.environment} c={c} active={c.environment === data.active_environment} onSaved={load} />
            ))}
          </div>
        )}
      </Card>
      <Card title="How keys are stored">
        <ul className="list-disc space-y-2 pl-4 text-sm text-ink-700">
          <li>Keys are encrypted in the database and only decrypted inside the server when an import runs.</li>
          <li>A saved key is never shown again. To change it, paste a new one.</li>
          <li>Production and sandbox keys are different; get them from Kinguin → Dashboard → My Stores.</li>
          <li>Every change is written to the audit log (without the key).</li>
        </ul>
        <p className="mt-4 text-xs text-ink-500">OpenAI (Phase 5) and the WooCommerce connector (Phase 7) are added here in their phases.</p>
      </Card>
    </div>
  );
}
