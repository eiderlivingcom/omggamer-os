import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from "react";

export function cx(...c: (string | false | null | undefined)[]) {
  return c.filter(Boolean).join(" ");
}

export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div className="min-w-0">
        <h1 className="text-[22px] font-bold tracking-tight text-ink-900">{title}</h1>
        {description && <p className="mt-1 max-w-2xl text-sm text-ink-500">{description}</p>}
      </div>
      {actions && <div className="flex gap-2">{actions}</div>}
    </div>
  );
}

export function Card({ title, aside, children, className }: { title?: string; aside?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={cx("rounded-xl border border-ink-100 bg-white shadow-card", className)}>
      {title && (
        <header className="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-3.5">
          <h2 className="text-sm font-semibold text-ink-900">{title}</h2>
          {aside}
        </header>
      )}
      <div className="p-5">{children}</div>
    </section>
  );
}

export function Stat({ label, value, hint, tone }: { label: string; value: ReactNode; hint?: ReactNode; tone?: "ok" | "warn" | "bad" }) {
  return (
    <div className="min-w-0">
      <div className="text-xs font-medium uppercase tracking-wide text-ink-500">{label}</div>
      <div
        className={cx(
          "mt-1 font-mono text-[26px] font-semibold tabular-nums leading-tight",
          tone === "bad" ? "text-rose-600" : tone === "warn" ? "text-amber-600" : "text-ink-900",
        )}
      >
        {value}
      </div>
      {hint && <div className="mt-0.5 text-xs text-ink-500">{hint}</div>}
    </div>
  );
}

const PILL = {
  ok: "bg-emerald-50 text-emerald-700 ring-emerald-600/20",
  warn: "bg-amber-50 text-amber-700 ring-amber-600/20",
  bad: "bg-rose-50 text-rose-700 ring-rose-600/20",
  info: "bg-brand-50 text-brand-700 ring-brand-600/20",
  muted: "bg-ink-50 text-ink-500 ring-ink-200",
};

export function Pill({ tone = "muted", children }: { tone?: keyof typeof PILL; children: ReactNode }) {
  return (
    <span className={cx("inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset", PILL[tone])}>
      {children}
    </span>
  );
}

export function Button({ variant = "primary", className, ...p }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "ghost" | "danger" }) {
  return (
    <button
      {...p}
      className={cx(
        "inline-flex h-9 items-center justify-center gap-2 rounded-lg px-3.5 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed disabled:opacity-50",
        variant === "primary" && "bg-brand-600 text-white hover:bg-brand-700",
        variant === "ghost" && "border border-ink-200 bg-white text-ink-900 hover:bg-ink-50",
        variant === "danger" && "border border-rose-200 bg-white text-rose-700 hover:bg-rose-50",
        className,
      )}
    />
  );
}

export function Field({ label, hint, error, ...p }: InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: string; error?: string }) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-semibold text-ink-700">{label}</span>
      <input
        {...p}
        className={cx(
          "h-10 w-full rounded-lg border bg-white px-3 text-sm text-ink-900 outline-none transition placeholder:text-ink-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20",
          error ? "border-rose-400" : "border-ink-200",
        )}
      />
      {error ? <span className="mt-1 block text-xs text-rose-600">{error}</span> : hint && <span className="mt-1 block text-xs text-ink-500">{hint}</span>}
    </label>
  );
}

export function Notice({ tone = "info", children }: { tone?: "info" | "bad" | "ok"; children: ReactNode }) {
  return (
    <div
      role={tone === "bad" ? "alert" : "status"}
      className={cx(
        "rounded-lg px-3.5 py-2.5 text-sm",
        tone === "bad" && "bg-rose-50 text-rose-800",
        tone === "ok" && "bg-emerald-50 text-emerald-800",
        tone === "info" && "bg-brand-50 text-brand-700",
      )}
    >
      {children}
    </div>
  );
}

export function Empty({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="rounded-lg border border-dashed border-ink-200 px-6 py-10 text-center">
      <div className="text-sm font-semibold text-ink-900">{title}</div>
      {children && <div className="mx-auto mt-1 max-w-md text-sm text-ink-500">{children}</div>}
    </div>
  );
}

export function fmtDate(iso: string | null | undefined) {
  if (!iso) return "—";
  return new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}

export function relTime(iso: string) {
  const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return "just now";
  if (s < 3600) return `${Math.floor(s / 60)} min ago`;
  if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
  return `${Math.floor(s / 86400)} d ago`;
}
