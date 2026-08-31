import { NextRequest } from "next/server";
import { getCandles, type CandleRange } from "@/lib/market";
import { marketError, marketJson } from "../_shared";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

const RANGES: CandleRange[] = ["1D", "5D", "1M", "6M", "YTD", "1Y", "5Y", "MAX"];

export async function GET(req: NextRequest) {
  try {
    const sp = req.nextUrl.searchParams;
    const symbol = sp.get("symbol") || sp.get("s");
    const range = (sp.get("range") || "1M").toUpperCase() as CandleRange;
    if (!symbol) return marketError(new (await import("@/lib/market")).MarketDataError("symbol is required", "unsupported"));
    if (!RANGES.includes(range)) return marketError(new (await import("@/lib/market")).MarketDataError("bad range", "unsupported"));
    const series = await getCandles(symbol, range);
    const intraday = series.interval.includes("min") || series.interval === "1h" || series.interval === "4h";
    return marketJson(series, { sMaxAge: intraday ? 60 : 900, swr: 3600 });
  } catch (e) {
    return marketError(e);
  }
}
