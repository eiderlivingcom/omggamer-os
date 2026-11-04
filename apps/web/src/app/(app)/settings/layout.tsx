"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { ReactNode } from "react";
import { PageHeader, cx } from "@/components/ui";
import { SETTINGS_TABS } from "@/lib/nav";
import { useSession } from "@/lib/session";

export default function SettingsLayout({ children }: { children: ReactNode }) {
  const path = usePathname();
  const { can } = useSession();
  return (
    <>
      <PageHeader title="Settings" description="People, roles, credentials and the audit trail." />
      <nav className="-mx-1 mb-6 flex gap-1 overflow-x-auto border-b border-ink-100" aria-label="Settings">
        {SETTINGS_TABS.filter((t) => can(t.perm)).map((t) => (
          <Link
            key={t.href}
            href={t.href}
            aria-current={path === t.href ? "page" : undefined}
            className={cx(
              "whitespace-nowrap border-b-2 px-3 pb-2.5 text-sm font-semibold transition",
              path === t.href ? "border-brand-600 text-ink-900" : "border-transparent text-ink-500 hover:text-ink-900",
            )}
          >
            {t.label}
          </Link>
        ))}
      </nav>
      {children}
    </>
  );
}
