import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { marketHealth } from "@/lib/market";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

/** Spec §35 — one endpoint that reports every dependency. */
export async function GET() {
  const started = Date.now();

  const [db, market] = await Promise.allSettled([
    prisma.$queryRaw`SELECT 1`.then(() => true),
    marketHealth(),
  ]);

  const database = db.status === "fulfilled" && db.value === true;
  const marketData = market.status === "fulfilled" ? market.value : { mode: "error", providers: [], redis: false };

  const anyProviderOk =
    marketData.mode === "mock" ||
    (Array.isArray(marketData.providers) && marketData.providers.some((p: { ok: boolean }) => p.ok));

  const healthy = database && anyProviderOk;

  return NextResponse.json(
    {
      ok: healthy,
      uptimeSec: Math.round(process.uptime()),
      checkedInMs: Date.now() - started,
      services: {
        database,
        redis: marketData.redis ?? false,
        marketData,
      },
      ts: new Date().toISOString(),
    },
    { status: healthy ? 200 : 503, headers: { "Cache-Control": "no-store" } },
  );
}
