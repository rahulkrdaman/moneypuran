import { NextRequest, NextResponse } from "next/server";
import { verifyAccessToken, COOKIE_ACCESS } from "@/lib/auth";
import { Role } from "@/types";

type AuthCtx = { userId: string; email: string; role: Role };

export function withAuth(
  handler: (req: NextRequest, ctx: { user: AuthCtx }) => Promise<NextResponse>,
  requiredRoles?: Role[]
) {
  return async (req: NextRequest): Promise<NextResponse> => {
    try {
      const authHeader = req.headers.get("authorization");
      let token: string | undefined;
      if (authHeader?.startsWith("Bearer ")) {
        token = authHeader.substring(7);
      } else {
        token = req.cookies.get(COOKIE_ACCESS)?.value;
      }
      if (!token) {
        return NextResponse.json({ success: false, error: "Unauthorized" }, { status: 401 });
      }
      const payload = verifyAccessToken(token);
      if (requiredRoles && !requiredRoles.includes(payload.role as Role)) {
        return NextResponse.json({ success: false, error: "Forbidden" }, { status: 403 });
      }
      return handler(req, { user: payload as AuthCtx });
    } catch {
      return NextResponse.json({ success: false, error: "Invalid token" }, { status: 401 });
    }
  };
}

export function withOptionalAuth(
  handler: (req: NextRequest, ctx: { user?: AuthCtx }) => Promise<NextResponse>
) {
  return async (req: NextRequest): Promise<NextResponse> => {
    try {
      const authHeader = req.headers.get("authorization");
      let token: string | undefined;
      if (authHeader?.startsWith("Bearer ")) token = authHeader.substring(7);
      else token = req.cookies.get(COOKIE_ACCESS)?.value;
      if (!token) return handler(req, {});
      const payload = verifyAccessToken(token);
      return handler(req, { user: payload as AuthCtx });
    } catch {
      return handler(req, {});
    }
  };
}
