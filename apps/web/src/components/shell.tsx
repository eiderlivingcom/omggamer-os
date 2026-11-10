"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import { api } from "@/lib/api";
import { CURRENT_PHASE, NAV } from "@/lib/nav";
import { useSession } from "@/lib/session";
import { Icon } from "./icon";
import { cx } from "./ui";

function isActive(path: string, href: string) {
  if (href === "/") return path === "/";
  if (href.startsWith("/settings")) return path.startsWith("/settings");
  return path === href || path.startsWith(`${href}/`);
}

export function Shell({ children }: { children: ReactNode }) {
  const path = usePathname();
  const router = useRouter();
  const { me, loading, can } = useSession();
  const [open, setOpen] = useState(false);

  useEffect(() => setOpen(false), [path]);
  useEffect(() => {
    if (!loading && me?.must_change_password && path !== "/account") router.replace("/account?force=1");
  }, [loading, me, path, router]);

  async function signOut() {
    await api("/auth/logout", { method: "POST" }).catch(() => undefined);
    window.location.href = "/login";
  }

  const nav = NAV.filter((n) => can(n.perm));

  return (
    <div className="min-h-dvh bg-ink-50 lg:grid lg:grid-cols-[248px_minmax(0,1fr)] lg:bg-[linear-gradient(to_right,#0c0b1a_248px,#f6f6fa_248px)]">
      <aside
        className={cx(
          "fixed inset-y-0 left-0 z-40 flex w-[248px] flex-col bg-navy-950 text-ink-200 transition-transform lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0",
          open ? "translate-x-0" : "-translate-x-full",
        )}
      >
        <Link href="/" className="flex items-center gap-2.5 px-5 pb-5 pt-6">
          <span className="leading-tight">
            {/* The logo is black artwork; on the dark sidebar it is shown in white. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/omggamer-logo.svg" alt="OMGGamer" width={142} height={40} className="h-10 w-auto brightness-0 invert" />
            <span className="mt-1 block text-[11px] font-semibold uppercase tracking-[0.18em] text-ink-300">OS · Catalogue &amp; commerce</span>
          </span>
        </Link>
        <nav className="flex-1 overflow-y-auto px-3 pb-4" aria-label="Main">
          <ul className="space-y-0.5">
            {nav.map((n) => {
              const active = isActive(path, n.href);
              const live = n.sections.some((s) => s.phase <= CURRENT_PHASE);
              return (
                <li key={n.href}>
                  <Link
                    href={n.href}
                    aria-current={active ? "page" : undefined}
                    className={cx(
                      "flex items-center gap-3 rounded-lg px-3 py-2 text-[13.5px] font-medium transition",
                      active ? "bg-navy-700 text-white" : "text-ink-300 hover:bg-navy-800 hover:text-white",
                    )}
                  >
                    <Icon name={n.icon} />
                    <span className="flex-1">{n.label}</span>
                    {!live && <span className="font-mono text-[10px] text-ink-500">P{Math.min(...n.sections.map((s) => s.phase))}</span>}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>
        <div className="border-t border-navy-800 p-3">
          <Link href="/account" className="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-navy-800">
            <span className="grid h-8 w-8 place-items-center rounded-full bg-navy-700 text-xs font-bold text-white">
              {(me?.name ?? "?").slice(0, 1).toUpperCase()}
            </span>
            <span className="min-w-0 flex-1 leading-tight">
              <span className="block truncate text-[13px] font-semibold text-white">{me?.name ?? "…"}</span>
              <span className="block truncate text-[11px] text-ink-300">{me?.role_name}</span>
            </span>
          </Link>
          <button onClick={signOut} className="mt-1 flex w-full items-center gap-3 rounded-lg px-3 py-2 text-[13px] text-ink-300 hover:bg-navy-800 hover:text-white">
            <Icon name="logout" /> Sign out
          </button>
        </div>
      </aside>
      {open && <button aria-label="Close menu" className="fixed inset-0 z-30 bg-navy-950/40 lg:hidden" onClick={() => setOpen(false)} />}
      <div className="min-w-0">
        <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-ink-100 bg-white/90 px-4 backdrop-blur lg:hidden">
          <button aria-label="Open menu" onClick={() => setOpen(true)} className="rounded-lg p-2 text-ink-700 hover:bg-ink-50">
            <Icon name="menu" />
          </button>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/omggamer-logo.svg" alt="OMGGamer" width={100} height={28} className="h-7 w-auto" />
          <span className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-500">OS</span>
        </header>
        <main className="mx-auto max-w-[1280px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">{loading ? <div className="h-40 animate-pulse rounded-xl bg-white" /> : children}</main>
      </div>
    </div>
  );
}
