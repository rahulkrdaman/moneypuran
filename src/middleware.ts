import { NextRequest, NextResponse } from "next/server";

const protectedPaths = ["/admin"];
const publicAdminPaths = ["/admin/login"];

/**
 * Lightweight Edge-compatible middleware.
 * Only checks whether the session cookie exists — full JWT verification
 * is done by each API route (via lib/auth.ts which runs in Node.js runtime)
 * and by the client-side fetch("/api/auth/me") inside AdminLayout.
 */
export function middleware(req: NextRequest) {
  const { pathname } = req.nextUrl;

  const isProtected = protectedPaths.some(p => pathname.startsWith(p));
  const isPublicAdmin = publicAdminPaths.some(p => pathname.startsWith(p));

  if (isProtected && !isPublicAdmin) {
    // Check for either the access or refresh cookie (both HttpOnly)
    const hasAccess  = req.cookies.has("mp_access");
    const hasRefresh = req.cookies.has("mp_refresh");

    if (!hasAccess && !hasRefresh) {
      const loginUrl = new URL("/admin/login", req.url);
      loginUrl.searchParams.set("redirect", pathname);
      return NextResponse.redirect(loginUrl);
    }
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/admin/:path*"],
};
