import jwt from "jsonwebtoken";
import bcrypt from "bcryptjs";
import { JwtPayload } from "@/types";
import { cookies } from "next/headers";
import { NextRequest } from "next/server";

// ── Cookie name constants (single source of truth) ────────────────────────────
export const COOKIE_ACCESS  = "mp_access";
export const COOKIE_REFRESH = "mp_refresh";

const JWT_SECRET          = process.env.JWT_SECRET          || "fallback-secret-change-in-production-min32";
const JWT_REFRESH_SECRET  = process.env.JWT_REFRESH_SECRET  || "fallback-refresh-change-in-production-min32";
const JWT_EXPIRES_IN      = process.env.JWT_EXPIRES_IN      || "15m";
const JWT_REFRESH_EXPIRES = process.env.JWT_REFRESH_EXPIRES_IN || "7d";

const COOKIE_OPTS = (maxAge: number) => ({
  httpOnly: true,
  secure:   process.env.NODE_ENV === "production",
  sameSite: "lax" as const,
  path:     "/",
  maxAge,
});

// ── Password ──────────────────────────────────────────────────────────────────
export async function hashPassword(password: string): Promise<string> {
  return bcrypt.hash(password, 12);
}
export async function verifyPassword(password: string, hash: string): Promise<boolean> {
  return bcrypt.compare(password, hash);
}

// ── JWT ───────────────────────────────────────────────────────────────────────
export function signAccessToken(payload: Omit<JwtPayload, "iat" | "exp">): string {
  return jwt.sign(payload, JWT_SECRET, { expiresIn: JWT_EXPIRES_IN } as jwt.SignOptions);
}
export function signRefreshToken(payload: Omit<JwtPayload, "iat" | "exp">): string {
  return jwt.sign(payload, JWT_REFRESH_SECRET, { expiresIn: JWT_REFRESH_EXPIRES } as jwt.SignOptions);
}
export function verifyAccessToken(token: string): JwtPayload {
  return jwt.verify(token, JWT_SECRET) as JwtPayload;
}
export function verifyRefreshToken(token: string): JwtPayload {
  return jwt.verify(token, JWT_REFRESH_SECRET) as JwtPayload;
}

// ── Get auth user from request (reads mp_access cookie or Bearer header) ─────
export async function getAuthUser(req: NextRequest): Promise<JwtPayload | null> {
  try {
    const authHeader = req.headers.get("authorization");
    let token: string | undefined;

    if (authHeader?.startsWith("Bearer ")) {
      token = authHeader.substring(7);
    } else {
      // Try request cookie first (faster, no async)
      token = req.cookies.get(COOKIE_ACCESS)?.value;
      if (!token) {
        // Fall back to next/headers cookies (works in Server Components / Route Handlers)
        const store = await cookies();
        token = store.get(COOKIE_ACCESS)?.value;
      }
    }
    if (!token) return null;
    return verifyAccessToken(token);
  } catch {
    return null;
  }
}

// ── Helpers for setting / clearing cookies ────────────────────────────────────
export function setAuthCookies(
  res: { cookies: { set: (name: string, value: string, opts: object) => void } },
  accessToken: string,
  refreshToken: string,
) {
  res.cookies.set(COOKIE_ACCESS,  accessToken,  COOKIE_OPTS(15 * 60));
  res.cookies.set(COOKIE_REFRESH, refreshToken, COOKIE_OPTS(7 * 24 * 60 * 60));
}
export function clearAuthCookies(
  res: { cookies: { delete: (name: string) => void } },
) {
  res.cookies.delete(COOKIE_ACCESS);
  res.cookies.delete(COOKIE_REFRESH);
}

export function generateSecureToken(): string {
  return [...Array(32)].map(() => Math.random().toString(36)[2]).join("");
}
