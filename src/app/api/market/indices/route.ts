import { INDICES, getQuotes, toClientQuote } from "@/lib/market";
import { marketError, marketJson } from "../_shared";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const quotes = await getQuotes(INDICES.map((c) => c.symbol));
    return marketJson({ items: quotes.map(toClientQuote) }, { sMaxAge: 30, swr: 90 });
  } catch (e) {
    return marketError(e);
  }
}
