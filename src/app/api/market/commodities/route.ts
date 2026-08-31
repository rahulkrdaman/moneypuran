import { COMMODITIES, getQuotes, toClientQuote } from "@/lib/market";
import { marketError, marketJson } from "../_shared";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const quotes = await getQuotes(COMMODITIES.map((c) => c.symbol));
    return marketJson({ items: quotes.map(toClientQuote) }, { sMaxAge: 45, swr: 120 });
  } catch (e) {
    return marketError(e);
  }
}
