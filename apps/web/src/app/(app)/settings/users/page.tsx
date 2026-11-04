"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Button, Card, Field, Notice, Pill, fmtDate } from "@/components/ui";
import { api, ApiError, type Me } from "@/lib/api";
import { useSession } from "@/lib/session";

type Role = { key: string; name: string };
type Created = { user: Me; temporary_password: string };

export default function UsersPage() {
  const { me, can } = useSession();
  const [users, setUsers] = useState<Me[] | null>(null);
  const [roles, setRoles] = useState<Role[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [created, setCreated] = useState<Created | null>(null);
  const [form, setForm] = useState({ email: "", name: "", role: "viewer" });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const [u, r] = await Promise.all([api<Me[]>("/users"), api<{ roles: Role[] }>("/roles")]);
      setUsers(u);
      setRoles(r.roles);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load users.");
    }
  }, []);

  useEffect(() => {
    if (can("users:manage")) void load();
  }, [can, load]);

  if (!can("users:manage")) return <Notice>Your role cannot manage users.</Notice>;

  async function create(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const r = await api<Created>("/users", { method: "POST", json: form });
      setCreated(r);
      setForm({ email: "", name: "", role: "viewer" });
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not create the user.");
    } finally {
      setBusy(false);
    }
  }

  async function update(id: string, body: Record<string, unknown>) {
    setError(null);
    try {
      await api(`/users/${id}`, { method: "PATCH", json: body });
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update the user.");
    }
  }

  async function reset(u: Me) {
    setError(null);
    try {
      setCreated(await api<Created>(`/users/${u.id}/reset-password`, { method: "POST" }));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not reset the password.");
    }
  }

  const assignable = roles.filter((r) => r.key !== "super_admin" || me?.role === "super_admin");

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
      <Card title="Team">
        {error && <div className="mb-4"><Notice tone="bad">{error}</Notice></div>}
        {created && (
          <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <div className="font-semibold">Temporary password for {created.user.email}</div>
            <code className="mt-2 block select-all break-all rounded bg-white px-2 py-1.5 font-mono text-[13px]">{created.temporary_password}</code>
            <p className="mt-2 text-xs">Shown once. Share it privately; they must choose a new password when they first sign in.</p>
            <button className="mt-2 text-xs font-semibold underline" onClick={() => setCreated(null)}>Done</button>
          </div>
        )}
        {!users ? (
          <div className="h-32 animate-pulse rounded-lg bg-ink-50" />
        ) : (
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                  <th className="px-5 py-2">Person</th>
                  <th className="px-3 py-2">Role</th>
                  <th className="px-3 py-2">Security</th>
                  <th className="px-3 py-2">Last sign-in</th>
                  <th className="px-5 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {users.map((u) => (
                  <tr key={u.id} className={u.is_active ? "" : "opacity-60"}>
                    <td className="px-5 py-3">
                      <div className="font-semibold text-ink-900">{u.name}</div>
                      <div className="text-xs text-ink-500">{u.email}</div>
                    </td>
                    <td className="px-3 py-3">
                      <select
                        aria-label={`Role of ${u.email}`}
                        className="h-8 rounded-md border border-ink-200 bg-white px-2 text-sm"
                        value={u.role}
                        disabled={u.id === me?.id || (u.role === "super_admin" && me?.role !== "super_admin")}
                        onChange={(e) => update(u.id, { role: e.target.value })}
                      >
                        {roles.map((r) => (
                          <option key={r.key} value={r.key} disabled={!assignable.some((a) => a.key === r.key)}>
                            {r.name}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="px-3 py-3">
                      <div className="flex flex-wrap gap-1">
                        {!u.is_active && <Pill tone="bad">Deactivated</Pill>}
                        {u.totp_enabled ? <Pill tone="ok">2FA on</Pill> : <Pill>2FA off</Pill>}
                        {u.must_change_password && <Pill tone="warn">Password change due</Pill>}
                      </div>
                    </td>
                    <td className="px-3 py-3 text-xs text-ink-500">{fmtDate(u.last_login_at)}</td>
                    <td className="px-5 py-3 text-right">
                      {u.id !== me?.id && (
                        <div className="flex justify-end gap-2">
                          <Button variant="ghost" className="h-8 px-2.5 text-xs" onClick={() => reset(u)}>Reset password</Button>
                          <Button variant={u.is_active ? "danger" : "ghost"} className="h-8 px-2.5 text-xs" onClick={() => update(u.id, { is_active: !u.is_active })}>
                            {u.is_active ? "Deactivate" : "Reactivate"}
                          </Button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card title="Add a person">
        <form onSubmit={create} className="space-y-3">
          <Field id="new-name" label="Name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <Field id="new-email" label="E-mail" type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <label className="block">
            <span className="mb-1 block text-xs font-semibold text-ink-700">Role</span>
            <select id="new-role" className="h-10 w-full rounded-lg border border-ink-200 bg-white px-3 text-sm" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
              {assignable.map((r) => (
                <option key={r.key} value={r.key}>{r.name}</option>
              ))}
            </select>
          </label>
          <Button type="submit" className="w-full" disabled={busy || !form.email || !form.name}>
            {busy ? "Adding…" : "Add person"}
          </Button>
          <p className="text-xs text-ink-500">They get a temporary password and must change it at first sign-in.</p>
        </form>
      </Card>
    </div>
  );
}
