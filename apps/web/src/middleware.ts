import { NextResponse, type NextRequest } from "next/server";

// Sends visitors without a session cookie to the sign-in page. The API still checks every request itself.
export function middleware(req: NextRequest) {
  const hasSession = req.cookies.has("omg_at") || req.cookies.has("omg_rt");
  if (!hasSession) {
    const url = req.nextUrl.clone();
    url.pathname = "/login";
    url.search = req.nextUrl.pathname === "/" ? "" : `?next=${encodeURIComponent(req.nextUrl.pathname)}`;
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!login|api|_next/static|_next/image|favicon.ico|icon.svg).*)"],
};
