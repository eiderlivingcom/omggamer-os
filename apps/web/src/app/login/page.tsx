"use client";

import { Suspense, useState, type FormEvent } from "react";
import { useSearchParams } from "next/navigation";
import { Button, Field, Notice } from "@/components/ui";
import { api, ApiError, type Me } from "@/lib/api";

function LoginForm() {
  const params = useSearchParams();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [needCode, setNeedCode] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ status: string; user: Me | null }>("/auth/login", {
        method: "POST",
        json: { email, password, totp_code: needCode ? code : undefined },
      });
      if (r.status === "2fa_required") {
        setNeedCode(true);
        return;
      }
      const next = params.get("next");
      window.location.href = r.user?.must_change_password ? "/account?force=1" : next && next.startsWith("/") ? next : "/";
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not reach the server. Try again.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      {error && <Notice tone="bad">{error}</Notice>}
      {!needCode ? (
        <>
          <Field id="email" label="E-mail" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />
          <Field id="password" label="Password" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </>
      ) : (
        <Field
          id="totp"
          label="Two-factor code"
          hint="Enter the 6-digit code from your authenticator app."
          inputMode="numeric"
          autoComplete="one-time-code"
          autoFocus
          maxLength={8}
          value={code}
          onChange={(e) => setCode(e.target.value)}
        />
      )}
      <Button type="submit" className="h-10 w-full" disabled={busy || !email || !password || (needCode && code.length < 6)}>
        {busy ? "Signing in…" : needCode ? "Verify and sign in" : "Sign in"}
      </Button>
      {needCode && (
        <button type="button" className="w-full text-center text-xs font-medium text-ink-500 hover:text-ink-900" onClick={() => { setNeedCode(false); setCode(""); }}>
          Use a different account
        </button>
      )}
    </form>
  );
}

export default function LoginPage() {
  return (
    <div className="grid min-h-dvh lg:grid-cols-[1.05fr_1fr]">
      <div className="relative hidden overflow-hidden bg-navy-950 p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div className="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-brand-600/30 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-32 left-10 h-80 w-80 rounded-full bg-fuchsia-600/20 blur-3xl" />
        <div className="relative flex items-center gap-3">
          <span className="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-brand-500 to-fuchsia-600 text-xs font-extrabold">OMG</span>
          <span className="text-lg font-bold">OMGGamer OS</span>
        </div>
        <div className="relative max-w-md">
          <h1 className="text-4xl font-extrabold leading-tight tracking-tight">One catalogue for every supplier and every storefront.</h1>
          <p className="mt-4 text-ink-300">Import from Kinguin and future suppliers, write and approve product content, set prices, and publish to omggamer.com.</p>
        </div>
        <div className="relative font-mono text-xs text-ink-500">os.omggamer.com · staff access only</div>
      </div>
      <div className="flex items-center justify-center bg-white px-4 py-12 sm:px-8">
        <div className="w-full max-w-sm">
          <div className="mb-8 lg:hidden">
            <span className="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-brand-500 to-fuchsia-600 text-xs font-extrabold text-white">OMG</span>
          </div>
          <h2 className="text-2xl font-bold tracking-tight text-ink-900">Sign in</h2>
          <p className="mb-6 mt-1 text-sm text-ink-500">Use the account your administrator created for you.</p>
          <Suspense>
            <LoginForm />
          </Suspense>
        </div>
      </div>
    </div>
  );
}
