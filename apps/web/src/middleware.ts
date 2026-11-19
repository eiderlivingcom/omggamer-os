import { NextResponse, type NextRequest } from "next/server";

/*
 * Staff door. When OS_DOOR_KEY is set (server .env), os.omggamer.com answers a bare 404 to anyone who has
 * not opened the door link (https://os.omggamer.com/?door=<key>) on this browser, or who is not on an
 * address listed in OS_ALLOWED_IPS. Outsiders cannot see the sign-in page or call the API at all.
 * Machine routes stay open: supplier and storefront webhooks (signed), the health check and the store's image
 * fetch of renamed images while a game is published.
 * Signed-out staff are sent to the sign-in page as before; the API still checks every request itself.
 */
export const config = {
  runtime: "nodejs",
  matcher: ["/((?!_next/static|_next/image).*)"],
};

// The store fetches a game's SEO-renamed images while it is being published (they are deleted right after).
const OPEN = [
  /^\/api\/v1\/webhooks\//,
  /^\/api\/v1\/health$/,
  /^\/api\/v1\/files\/media\/[0-9a-f-]{36}\/[a-z0-9][a-z0-9-]{0,140}\.(jpg|jpeg|png|webp|gif)$/,
];
const PUBLIC_ASSETS = /^\/(favicon\.ico|icon\.png|apple-icon\.png|omggamer-logo\.svg)$/;
const COOKIE = "omg_door";

async function sha256(text: string): Promise<string> {
  const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
  return Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, "0")).join("");
}

function same(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let d = 0;
  for (let i = 0; i < a.length; i++) d |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return d === 0;
}

function clientIp(req: NextRequest): string {
  return (req.headers.get("x-real-ip") || req.headers.get("x-forwarded-for")?.split(",")[0] || "").trim();
}

function notFound(): NextResponse {
  return new NextResponse("Not Found", {
    status: 404,
    headers: { "content-type": "text/plain; charset=utf-8", "x-robots-tag": "noindex, nofollow", "cache-control": "no-store" },
  });
}

export async function middleware(req: NextRequest) {
  const path = req.nextUrl.pathname;
  if (OPEN.some((r) => r.test(path))) return NextResponse.next();

  const key = (process.env["OS_DOOR_KEY"] || "").trim();
  if (key.length >= 16) {
    const token = await sha256(`omg-door:${key}`);
    const given = req.nextUrl.searchParams.get("door");
    if (given !== null) {
      if (!same(await sha256(`omg-door:${given}`), token)) return notFound();
      const url = req.nextUrl.clone();
      url.pathname = "/login";
      url.search = "";
      const res = NextResponse.redirect(url);
      res.cookies.set(COOKIE, token, { httpOnly: true, secure: true, sameSite: "lax", path: "/", maxAge: 60 * 60 * 24 * 365 });
      return res;
    }
    const allowed = (process.env["OS_ALLOWED_IPS"] || "").split(/[\s,]+/).filter(Boolean);
    const ipOk = allowed.length > 0 && allowed.includes(clientIp(req));
    if (!ipOk && !same(req.cookies.get(COOKIE)?.value || "", token)) return notFound();
  }

  if (path.startsWith("/api/") || path === "/login" || PUBLIC_ASSETS.test(path)) return NextResponse.next();
  const hasSession = req.cookies.has("omg_at") || req.cookies.has("omg_rt");
  if (!hasSession) {
    const url = req.nextUrl.clone();
    url.pathname = "/login";
    url.search = path === "/" ? "" : `?next=${encodeURIComponent(path)}`;
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}
