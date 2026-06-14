import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { verifyRefreshToken, signAccessToken, signRefreshToken, setAuthCookies, COOKIE_REFRESH } from "@/lib/auth";

export async function POST(req: NextRequest) {
  try {
    const refreshToken = req.cookies.get(COOKIE_REFRESH)?.value;
    if (!refreshToken) return NextResponse.json({ success:false, error:"No refresh token" }, { status:401 });

    let payload;
    try { payload = verifyRefreshToken(refreshToken); }
    catch { return NextResponse.json({ success:false, error:"Invalid refresh token" }, { status:401 }); }

    const session = await prisma.session.findFirst({
      where: { token: refreshToken, isActive: true, expiresAt: { gt: new Date() } },
    });
    if (!session) return NextResponse.json({ success:false, error:"Session expired" }, { status:401 });

    const jwtPayload   = { userId: payload.userId, email: payload.email, role: payload.role };
    const newAccess    = signAccessToken(jwtPayload);
    const newRefresh   = signRefreshToken(jwtPayload);

    await prisma.session.update({
      where: { id: session.id },
      data:  { token: newRefresh, expiresAt: new Date(Date.now() + 7*24*60*60*1000) },
    });

    const res = NextResponse.json({ success:true, data:{ accessToken: newAccess } });
    setAuthCookies(res, newAccess, newRefresh);
    return res;
  } catch {
    return NextResponse.json({ success:false, error:"Internal error" }, { status:500 });
  }
}
