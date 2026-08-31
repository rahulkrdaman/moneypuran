import { NextResponse } from "next/server";
import { MarketDataError } from "@/lib/market";

/** Consistent JSON envelope + browser/CDN cache headers for market endpoints. */
export function marketJson(data: unknown, opts: { sMaxAge?: number; swr?: number } = {}) {
  const sMaxAge = opts.sMaxAge ?? 15;
  const swr = opts.swr ?? 60;
  return NextResponse.json(
    { ok: true, data, ts: new Date().toISOString() },
    {
      headers: {
        // API routes get `no-store` from next.config.ts; override for market data,
        // which is safe to cache briefly at the edge (one upstream serves many).
        "Cache-Control": `public, s-maxage=${sMaxAge}, stale-while-revalidate=${swr}`,
        "CDN-Cache-Control": `public, s-maxage=${sMaxAge}`,
      },
    },
  );
}

export function marketError(e: unknown) {
  if (e instanceof MarketDataError) {
    const status =
      e.code === "not_found" ? 404 :
      e.code === "rate_limited" ? 429 :
      e.code === "unsupported" ? 400 :
      e.code === "no_license" ? 451 :
      e.code === "config" ? 500 : 502;
    return NextResponse.json({ ok: false, error: { code: e.code, message: e.message } }, { status });
  }
  console.error("[market api]", e);
  return NextResponse.json({ ok: false, error: { code: "internal", message: "Market data temporarily unavailable" } }, { status: 502 });
}
