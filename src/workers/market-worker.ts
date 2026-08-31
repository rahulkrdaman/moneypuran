/**
 * Market-data worker (spec §28) — keeps "hot" symbols warm in the cache and
 * persists the latest snapshot to the DB, so page/API requests are almost always
 * a cache hit and one upstream call serves everyone.
 *
 * Run modes:
 *   - `tsx src/workers/market-worker.ts`            → long-running loop
 *   - `tsx src/workers/market-worker.ts --once`     → single pass (for cron)
 *   - imported: `runMarketRefresh()`                → single pass, returns a summary
 *
 * In mock mode it still runs (warms the mock cache) so the loop is testable
 * without any key, licence, DB or Redis.
 */
import {
  getQuotes, getMarketConfig, TICKER_SYMBOLS, ALL_SYMBOLS, type Quote,
} from "@/lib/market";

const HOT_SYMBOLS = [
  ...new Set([
    ...TICKER_SYMBOLS,
    ...ALL_SYMBOLS.filter((s) => s.assetClass === "index" || s.assetClass === "crypto").map((s) => s.symbol),
    ...ALL_SYMBOLS.filter((s) => s.assetClass === "stock" && s.market !== "IN").slice(0, 20).map((s) => s.symbol),
  ]),
];

export interface RefreshSummary {
  ok: number;
  failed: number;
  stale: number;
  durationMs: number;
  mode: "mock" | "live";
  persisted: number;
}

export async function runMarketRefresh(opts: { persist?: boolean } = {}): Promise<RefreshSummary> {
  const start = Date.now();
  const cfg = getMarketConfig();

  // getQuotes populates the shared cache (Redis if configured, else in-process).
  const quotes = await batched(HOT_SYMBOLS, 25, (chunk) => getQuotes(chunk));

  const ok = quotes.filter((q) => Number.isFinite(q.price) && !q.stale).length;
  const stale = quotes.filter((q) => q.stale).length;
  const failed = quotes.filter((q) => !Number.isFinite(q.price)).length;

  let persisted = 0;
  if (opts.persist) persisted = await persistSnapshots(quotes).catch((e) => {
    console.warn("[market-worker] persist skipped:", e instanceof Error ? e.message : e);
    return 0;
  });

  return {
    ok, failed, stale, persisted,
    durationMs: Date.now() - start,
    mode: cfg.mock ? "mock" : "live",
  };
}

async function batched<T, R>(items: T[], size: number, fn: (chunk: T[]) => Promise<R[]>): Promise<R[]> {
  const out: R[] = [];
  for (let i = 0; i < items.length; i += size) {
    out.push(...(await fn(items.slice(i, i + size))));
  }
  return out;
}

/**
 * Upsert MarketSymbol + MarketQuote. Import prisma lazily so the worker (and its
 * tests) run with no DB when persist is off.
 */
async function persistSnapshots(quotes: Quote[]): Promise<number> {
  const { prisma } = await import("@/lib/prisma");
  const { ALL_SYMBOLS: universe } = await import("@/lib/market");
  const bySym = new Map(universe.map((m) => [m.symbol, m]));
  let n = 0;
  for (const q of quotes) {
    if (!Number.isFinite(q.price)) continue;
    const meta = bySym.get(q.symbol);
    if (!meta) continue;
    const symbol = await prisma.marketSymbol.upsert({
      where: { symbol: meta.symbol },
      create: {
        symbol: meta.symbol, slug: meta.slug, name: meta.name,
        assetClass: meta.assetClass.toUpperCase() as never,
        currency: meta.currency, exchange: meta.exchange ?? null, market: meta.market ?? null,
        aliases: meta.aliases ? JSON.stringify(meta.aliases) : null,
        providerMap: meta.providerSymbols ? JSON.stringify(meta.providerSymbols) : null,
        description: meta.description ?? null,
        isTicker: TICKER_SYMBOLS.includes(meta.symbol),
      },
      update: {},
      select: { id: true },
    });
    await prisma.marketQuote.upsert({
      where: { symbolId: symbol.id },
      create: {
        symbolId: symbol.id, price: q.price, currency: q.currency,
        changeAbs: q.changeAbs, changePct: q.changePct, previousClose: q.previousClose,
        dayHigh: q.dayHigh, dayLow: q.dayLow, yearHigh: q.yearHigh, yearLow: q.yearLow,
        volume: q.volume, marketCap: q.marketCap,
        entitlement: q.entitlement, source: q.source, asOf: new Date(q.asOf),
      },
      update: {
        price: q.price, changeAbs: q.changeAbs, changePct: q.changePct,
        previousClose: q.previousClose, dayHigh: q.dayHigh, dayLow: q.dayLow,
        yearHigh: q.yearHigh, yearLow: q.yearLow, volume: q.volume, marketCap: q.marketCap,
        entitlement: q.entitlement, source: q.source, asOf: new Date(q.asOf),
      },
    });
    n++;
  }
  return n;
}

/* ─────────────────────────── loop (no side effects on import) ─────────────────────────── */

export async function marketWorkerLoop(opts: { once?: boolean; persist?: boolean } = {}) {
  const once = opts.once ?? process.argv.includes("--once");
  const persist = opts.persist ?? process.argv.includes("--persist");
  const intervalMs = Number(process.env.MARKET_WORKER_INTERVAL_MS || 20_000);

  do {
    try {
      const s = await runMarketRefresh({ persist });
      console.log(
        `[market-worker] ${new Date().toISOString()} mode=${s.mode} ok=${s.ok} stale=${s.stale} failed=${s.failed} persisted=${s.persisted} ${s.durationMs}ms`,
      );
    } catch (e) {
      console.error("[market-worker] pass failed:", e);
    }
    if (!once) await new Promise((r) => setTimeout(r, intervalMs));
  } while (!once);
}
// The CLI bootstrap lives in market-worker.cli.ts so importing this module is
// side-effect free (safe for tests, cron handlers, API routes).
