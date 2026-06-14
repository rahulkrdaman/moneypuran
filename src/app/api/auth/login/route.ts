import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { verifyPassword, signAccessToken, signRefreshToken, setAuthCookies } from "@/lib/auth";
import { z } from "zod";

const schema = z.object({ email: z.string().email(), password: z.string().min(6) });

export async function POST(req: NextRequest) {
  try {
    const body = await req.json();
    const { email, password } = schema.parse(body);

    const user = await prisma.user.findUnique({ where: { email } });
    if (!user || !(await verifyPassword(password, user.password))) {
      return NextResponse.json({ success: false, error: "Invalid email or password" }, { status: 401 });
    }
    if (!user.isActive) {
      return NextResponse.json({ success: false, error: "Account disabled" }, { status: 403 });
    }

    // Build JWT with userId (matches JwtPayload type)
    const jwtPayload = { userId: user.id, email: user.email, role: user.role };
    const accessToken  = signAccessToken(jwtPayload);
    const refreshToken = signRefreshToken(jwtPayload);

    // Persist session for refresh-token validation
    await prisma.session.create({
      data: {
        userId:    user.id,
        token:     refreshToken,
        expiresAt: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000),
      },
    });
    await prisma.user.update({ where: { id: user.id }, data: { lastLogin: new Date() } });

    const res = NextResponse.json({
      success: true,
      data: {
        accessToken,
        user: { id: user.id, email: user.email, firstName: user.firstName, lastName: user.lastName, role: user.role },
      },
    });

    // Set HttpOnly cookies (mp_access + mp_refresh)
    setAuthCookies(res, accessToken, refreshToken);
    return res;
  } catch (e) {
    if (e instanceof z.ZodError) {
      return NextResponse.json({ success: false, error: e.errors[0].message }, { status: 400 });
    }
    console.error("Login error:", e);
    return NextResponse.json({ success: false, error: "Login failed" }, { status: 500 });
  }
}
