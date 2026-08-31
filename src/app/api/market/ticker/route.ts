import { getTicker, toClientQuote } from "@/lib/market";
import { marketError, marketJson } from "../_shared";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const { items, asOf } = await getTicker();
    return marketJson({ items: items.map(toClientQuote), asOf }, { sMaxAge: 15, swr: 60 });
  } catch (e) {
    return marketError(e);
  }
}
