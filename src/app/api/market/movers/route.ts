import { NextRequest } from "next/server";
import { getMovers } from "@/lib/market";
import type { MoverKind } from "@/lib/market";
import { marketError, marketJson } from "../_shared";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

const KINDS: MoverKind[] = ["gainers", "losers", "active", "volume", "high52", "low52"];

export async function GET(req: NextRequest) {
  try {
    const sp = req.nextUrl.searchParams;
    const market = (sp.get("market") || "US").toUpperCase();
    const kind = (sp.get("kind") || "gainers") as MoverKind;
    if (!KINDS.includes(kind)) return marketError(new (await import("@/lib/market")).MarketDataError("bad kind", "unsupported"));
    const movers = await getMovers(market, kind);
    return marketJson(movers, { sMaxAge: 30, swr: 90 });
  } catch (e) {
    return marketError(e);
  }
}
