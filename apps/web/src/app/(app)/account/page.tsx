"use client";

import QRCode from "qrcode";
import { Suspense, useState, type FormEvent } from "react";
import { useSearchParams } from "next/navigation";
import { Button, Card, Field, Notice, PageHeader, Pill } from "@/components/ui";
import { api, ApiError, type Me } from "@/lib/api";
import { useSession } from "@/lib/session";

function PasswordCard() {
  const { me, reload } = useSession();
  const force = useSearchParams().get("force") === "1" || me?.must_change_password;
  const [cur, setCur] = useState("");
  const [next, setNext] = useState("");
  const [again, setAgain] = useState("");
  const [msg, setMsg] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (next !== again) {
      setMsg({ tone: "bad", text: "The new passwords do not match." });
      return;
    }
    setBusy(true);
    setMsg(null);
    try {
      await api<Me>("/auth/password", { method: "POST", json: { current_password: cur, new_password: next } });
      setCur("");
      setNext("");
      setAgain("");
      await reload();
      setMsg({ tone: "ok", text: "Password changed. Other sessions were signed out." });
      if (force) window.location.href = "/";
    } catch (err) {
      setMsg({ tone: "bad", text: err instanceof ApiError ? err.message : "Could not change the password." });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card title="Password">
      {force && (
        <div className="mb-4">
          <Notice>Choose your own password before you continue. The temporary one stops working after this.</Notice>
        </div>
      )}
      {msg && <div className="mb-4"><Notice tone={msg.tone}>{msg.text}</Notice></div>}
      <form onSubmit={submit} className="space-y-3">
        <Field id="cur" label="Current password" type="password" autoComplete="current-password" value={cur} onChange={(e) => setCur(e.target.value)} />
        <Field id="next" label="New password" type="password" autoComplete="new-password" hint="12+ characters with at least three of: lowercase, uppercase, numbers, symbols." value={next} onChange={(e) => setNext(e.target.value)} />
        <Field id="again" label="Repeat new password" type="password" autoComplete="new-password" value={again} onChange={(e) => setAgain(e.target.value)} />
        <Button type="submit" disabled={busy || !cur || next.length < 12}>{busy ? "Saving…" : "Change password"}</Button>
      </form>
    </Card>
  );
}

function TwoFactorCard() {
  const { me, reload } = useSession();
  const [setup, setSetup] = useState<{ secret: string; qr: string } | null>(null);
  const [code, setCode] = useState("");
  const [msg, setMsg] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);

  async function start() {
    setMsg(null);
    try {
      const r = await api<{ secret: string; otpauth_uri: string }>("/auth/2fa/setup", { method: "POST" });
      setSetup({ secret: r.secret, qr: await QRCode.toDataURL(r.otpauth_uri, { margin: 1, width: 200 }) });
    } catch (err) {
      setMsg({ tone: "bad", text: err instanceof ApiError ? err.message : "Could not start the setup." });
    }
  }

  async function confirm(path: "enable" | "disable") {
    setMsg(null);
    try {
      await api(`/auth/2fa/${path}`, { method: "POST", json: { code } });
      setCode("");
      setSetup(null);
      await reload();
      setMsg({ tone: "ok", text: path === "enable" ? "Two-factor authentication is on." : "Two-factor authentication is off." });
    } catch (err) {
      setMsg({ tone: "bad", text: err instanceof ApiError ? err.message : "That did not work." });
    }
  }

  return (
    <Card title="Two-factor authentication" aside={me?.totp_enabled ? <Pill tone="ok">On</Pill> : <Pill tone="warn">Off</Pill>}>
      {msg && <div className="mb-4"><Notice tone={msg.tone}>{msg.text}</Notice></div>}
      {me?.totp_enabled ? (
        <div className="space-y-3">
          <p className="text-sm text-ink-500">Sign-in asks for a code from your authenticator app. Enter a current code to turn it off.</p>
          <Field id="off-code" label="Current code" inputMode="numeric" maxLength={8} value={code} onChange={(e) => setCode(e.target.value)} />
          <Button variant="danger" disabled={code.length < 6} onClick={() => confirm("disable")}>Turn off</Button>
        </div>
      ) : setup ? (
        <div className="space-y-3">
          <p className="text-sm text-ink-500">Scan this with Google Authenticator, 1Password or a similar app, then enter the code it shows.</p>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={setup.qr} alt="QR code for your authenticator app" width={200} height={200} className="rounded-lg border border-ink-100" />
          <p className="text-xs text-ink-500">
            Or enter this key: <code className="select-all break-all font-mono text-ink-900">{setup.secret}</code>
          </p>
          <Field id="on-code" label="Code from the app" inputMode="numeric" maxLength={8} value={code} onChange={(e) => setCode(e.target.value)} />
          <Button disabled={code.length < 6} onClick={() => confirm("enable")}>Turn on</Button>
        </div>
      ) : (
        <div className="space-y-3">
          <p className="text-sm text-ink-500">Adds a 6-digit code from your phone to every sign-in. Strongly recommended for admin roles.</p>
          <Button onClick={start}>Set up two-factor authentication</Button>
        </div>
      )}
    </Card>
  );
}

export default function AccountPage() {
  const { me } = useSession();
  return (
    <>
      <PageHeader title="Your account" description={me ? `${me.email} · ${me.role_name}` : undefined} />
      <div className="grid gap-6 lg:grid-cols-2">
        <Suspense>
          <PasswordCard />
        </Suspense>
        <TwoFactorCard />
      </div>
    </>
  );
}
