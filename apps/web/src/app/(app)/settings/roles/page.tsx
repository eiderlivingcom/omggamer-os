"use client";

import { useEffect, useState } from "react";
import { Card, Notice } from "@/components/ui";
import { api } from "@/lib/api";

type Data = {
  roles: { key: string; name: string; permissions: string[]; users: number }[];
  permissions: { key: string; description: string }[];
};

export default function RolesPage() {
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<Data>("/roles").then(setData).catch((e: Error) => setError(e.message));
  }, []);

  if (error) return <Notice tone="bad">{error}</Notice>;
  if (!data) return <div className="h-64 animate-pulse rounded-xl bg-white" />;

  return (
    <Card title="Roles and permissions" aside={<span className="text-xs text-ink-500">Fixed roles · assign them under Users</span>}>
      <div className="-mx-5 overflow-x-auto">
        <table className="w-full min-w-[860px] text-sm">
          <thead>
            <tr className="border-b border-ink-100">
              <th className="sticky left-0 bg-white px-5 py-2 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">Permission</th>
              {data.roles.map((r) => (
                <th key={r.key} className="px-2 py-2 text-center text-xs font-semibold text-ink-700">
                  {r.name}
                  <div className="font-mono text-[10px] font-normal text-ink-500">{r.users} {r.users === 1 ? "user" : "users"}</div>
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-ink-100">
            {data.permissions.map((p) => (
              <tr key={p.key}>
                <td className="sticky left-0 bg-white px-5 py-2">
                  <div className="font-medium text-ink-900">{p.description}</div>
                  <div className="font-mono text-[11px] text-ink-500">{p.key}</div>
                </td>
                {data.roles.map((r) => {
                  const has = r.permissions.includes(p.key);
                  return (
                    <td key={r.key} className="px-2 py-2 text-center">
                      <span className={has ? "text-emerald-600" : "text-ink-200"} aria-label={has ? "Allowed" : "Not allowed"}>
                        {has ? "●" : "○"}
                      </span>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}
