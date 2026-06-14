import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { clearAuthCookies, COOKIE_REFRESH } from "@/lib/auth";

export async function POST(req: NextRequest) {
  try {
    // Invalidate session in DB
    const refreshToken = req.cookies.get(COOKIE_REFRESH)?.value;
    if (refreshToken) {
      await prisma.session.deleteMany({ where: { token: refreshToken } }).catch(() => {});
    }
  } catch { /* best-effort */ }

  const res = NextResponse.json({ success: true });
  clearAuthCookies(res);
  return res;
}
