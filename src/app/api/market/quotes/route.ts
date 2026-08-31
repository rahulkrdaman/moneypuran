import { NextRequest } from "next/server";
import { getQuotes, toClientQuote } from "@/lib/market";
import { marketError, marketJson } from "../_shared";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

export async function GET(req: NextRequest) {
  try {
    const raw = req.nextUrl.searchParams.get("symbols") || "";
    const symbols = raw.split(",").map((s) => s.trim()).filter(Boolean).slice(0, 50);
    if (!symbols.length) return marketJson({ items: [] });
    const quotes = await getQuotes(symbols);
    return marketJson({ items: quotes.map(toClientQuote) }, { sMaxAge: 15, swr: 45 });
  } catch (e) {
    return marketError(e);
  }
}
