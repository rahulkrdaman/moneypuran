import { NextRequest } from "next/server";
import { getAllMarketStatuses, getMarketStatus } from "@/lib/market";
import { marketJson } from "../_shared";

export const runtime = "nodejs";

export async function GET(req: NextRequest) {
  const market = req.nextUrl.searchParams.get("market");
  const data = market ? getMarketStatus(market.toUpperCase()) : getAllMarketStatuses();
  return marketJson(data, { sMaxAge: 30, swr: 120 });
}
