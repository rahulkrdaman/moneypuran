import { NextRequest, NextResponse } from "next/server";
import { getRedis } from "@/lib/redis";

export async function rateLimit(
  req: NextRequest,
  limit = 100,
  windowSeconds = 60
): Promise<{ success: boolean; remaining: number }> {
  const ip = req.headers.get("x-forwarded-for")?.split(",")[0]?.trim() || "anonymous";
  const key = `ratelimit:${ip}:${Math.floor(Date.now() / (windowSeconds * 1000))}`;
  try {
    const redis = getRedis();
    const count = await redis.incr(key);
    if (count === 1) await redis.expire(key, windowSeconds);
    return { success: count <= limit, remaining: Math.max(0, limit - count) };
  } catch {
    return { success: true, remaining: limit }; // Fail open
  }
}
