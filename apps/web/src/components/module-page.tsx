"use client";

import { NAV } from "@/lib/nav";
import type { ReactNode } from "react";
import { Card, Empty, PageHeader, Pill } from "./ui";

const PHASE_TEXT: Record<number, string> = {
  2: "Phase 2 · Kinguin import",
  3: "Phase 3 · Catalogue and pricing",
  4: "Phase 4 · AI Studio",
  5: "Phase 5 · WordPress sync",
  6: "Phase 6 · Orders",
  7: "Phase 7 · Analytics and automation",
};

/** A module that is planned but not built yet: lists its sections and which phase delivers each. */
export function ModulePage({ href, description, children }: { href: string; description: string; children?: ReactNode }) {
  const item = NAV.find((n) => n.href === href);
  if (!item) return null;
  return (
    <>
      <PageHeader title={item.label} description={description} />
      {children}
      <Card title="Sections">
        <ul className="divide-y divide-ink-100">
          {item.sections.map((s) => (
            <li key={s.label} className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
              <span className="text-sm font-medium text-ink-900">{s.label}</span>
              <Pill tone={s.phase <= 1 ? "ok" : "muted"}>{s.phase <= 1 ? "Live" : PHASE_TEXT[s.phase]}</Pill>
            </li>
          ))}
        </ul>
      </Card>
      <div className="mt-6">
        <Empty title="Nothing here yet">
          This module is built in {PHASE_TEXT[Math.min(...item.sections.map((s) => s.phase))]}. It will show real data from the
          catalogue database as soon as that phase ships.
        </Empty>
      </div>
    </>
  );
}
