import { NextRequest } from "next/server";
import { getQuote, toClientQuote, type AssetClass } from "@/lib/market";
import { marketError, marketJson } from "../_shared";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

export async function GET(req: NextRequest) {
  try {
    const sp = req.nextUrl.searchParams;
    const symbol = sp.get("symbol") || sp.get("s");
    if (!symbol) return marketError(new (await import("@/lib/market")).MarketDataError("symbol is required", "unsupported"));
    const assetClass = (sp.get("class") as AssetClass) || undefined;
    const q = await getQuote(symbol, assetClass);
    return marketJson(toClientQuote(q), { sMaxAge: 15, swr: 45 });
  } catch (e) {
    return marketError(e);
  }
}
