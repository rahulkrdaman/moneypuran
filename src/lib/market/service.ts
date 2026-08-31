/**
 * MarketDataService — the ONLY thing API routes, pages, components and workers
 * touch. It never leaks a provider name or key beyond the safe `source` field.
 *
 * Flow (spec §5, §26, §27):
 *   request → L1/L2 cache → coalesce → rate-limit → circuit-breaker → provider
 *                                                   ↘ fallback provider on failure
 *   Stale-while-revalidate: past soft-TTL, serve cached value with stale:true
 *   and kick a background refresh. Never block the user on the upstream.
 */
import { getMarketConfig } from "./config";
import { marketForSymbol, marketStatus } from "./market-status";
import { providerChain, getRegistry } from "./providers";
import { cachePing, cacheRead, cacheWrite } from "./cache";
import { coalesce } from "./resilience";
import { resolveSymbol, symbolBySlug, TICKER_SYMBOLS } from "./symbols";
import type {
  AssetClass, CandleInterval, CandleRange, CandleSeries, MarketStatus, Movers,
  MoverKind, ProviderHealth, Quote, SymbolMeta,
} from "./types";
import { MarketDataError } from "./types";

function ttlFor(a: AssetClass): number {
  const t = getMarketConfig().ttl;
  return t[a] ?? t.stock;
}

async function withProviders<T>(
  assetClass: string,
  op: string,
  fn: (p: import("./providers").RegisteredProvider["provider"]) => Promise<T>,
): Promise<T> {
  const chain = providerChain(assetClass);
  if (!chain.length) throw new MarketDataError(`no provider for ${assetClass}`, "unsupported");
  let lastErr: unknown;
  for (const r of chain) {
    if (!r.breaker.canRequest()) { lastErr = new Error(`circuit open: ${r.provider.id}`); continue; }
    const ok = await r.bucket.acquire(1, 6000);
    if (!ok) { lastErr = new MarketDataError("local rate limit", "rate_limited", r.provider.id); continue; }
    try {
      return await r.breaker.run(() => fn(r.provider));
    } catch (e) {
      lastErr = e;
      // try the next provider in the chain
    }
  }
  throw lastErr instanceof Error ? lastErr : new MarketDataError(`all providers failed for ${assetClass}.${op}`, "provider_error");
}

/* ─────────────────────────────── Quotes ─────────────────────────────── */

async function fetchQuote(meta: SymbolMeta): Promise<Quote> {
  return withProviders(meta.assetClass, "quote", (p) => p.getQuote(meta));
}

export async function getQuote(input: string, assetClass?: AssetClass): Promise<Quote> {
  const meta = resolveSymbol(input, assetClass);
  if (!meta) throw new MarketDataError(`unknown symbol: ${input}`, "not_found");
  const key = `mkt:q:${meta.symbol}`;
  const soft = ttlFor(meta.assetClass);

  const cached = await cacheRead<Quote>(key);
  if (cached && !cached.stale) return cached.value;
  if (cached && cached.stale) {
    // stale-while-revalidate
    void coalesce(key, () => fetchQuote(meta).then((q) => cacheWrite(key, q, soft)).catch(() => {}));
    return { ...cached.value, stale: true };
  }

  const fresh = await coalesce(key, () => fetchQuote(meta));
  await cacheWrite(key, fresh, soft);
  return fresh;
}

export async function getQuotes(inputs: string[]): Promise<Quote[]> {
  const metas = inputs
    .map((i) => resolveSymbol(i))
    .filter((m): m is SymbolMeta => !!m);

  // fast path: everything cached & fresh
  const out: (Quote | null)[] = await Promise.all(
    metas.map(async (m) => {
      const c = await cacheRead<Quote>(`mkt:q:${m.symbol}`);
      return c && !c.stale ? c.value : null;
    }),
  );
  const missingIdx = out.map((v, i) => (v ? -1 : i)).filter((i) => i >= 0);
  if (!missingIdx.length) return out as Quote[];

  // group missing by asset class and batch where the provider allows it
  const missing = missingIdx.map((i) => metas[i]);
  const byClass = groupBy(missing, (m) => m.assetClass);
  const fetched = new Map<string, Quote>();

  await Promise.all(
    Object.entries(byClass).map(async ([assetClass, group]) => {
      try {
        const quotes = await withProviders(assetClass, "quotes", async (p) => {
          if (p.getQuotes && p.capabilities.batchQuote) return p.getQuotes(group);
          return Promise.all(group.map((m) => p.getQuote(m)));
        });
        for (const q of quotes) {
          fetched.set(q.symbol, q);
          await cacheWrite(`mkt:q:${q.symbol}`, q, ttlFor(q.assetClass));
        }
      } catch {
        // fall back to whatever stale cache we have for this group
        for (const m of group) {
          const c = await cacheRead<Quote>(`mkt:q:${m.symbol}`);
          if (c) fetched.set(m.symbol, { ...c.value, stale: true });
        }
      }
    }),
  );

  return metas.map((m) => (out[metas.indexOf(m)] ?? fetched.get(m.symbol) ?? placeholderQuote(m)));
}

function placeholderQuote(m: SymbolMeta): Quote {
  const st = marketStatus(marketForSymbol(m.assetClass, m.market));
  return {
    symbol: m.symbol, name: m.name, assetClass: m.assetClass, price: NaN, currency: m.currency,
    changeAbs: null, changePct: null, previousClose: null, open: null, dayHigh: null, dayLow: null,
    yearHigh: null, yearLow: null, volume: null, marketCap: null, asOf: new Date().toISOString(),
    stale: true, entitlement: "delayed", source: "none", marketState: st.state, timezone: st.timezone,
  };
}

/* ─────────────────────────────── Ticker ─────────────────────────────── */

export async function getTicker(): Promise<{ items: Quote[]; asOf: string }> {
  const items = await getQuotes(TICKER_SYMBOLS);
  return { items, asOf: new Date().toISOString() };
}

/* ─────────────────────────────── Candles ─────────────────────────────── */

const RANGE_MAP: Record<CandleRange, { interval: CandleInterval; size: number }> = {
  "1D": { interval: "5min", size: 96 },
  "5D": { interval: "30min", size: 130 },
  "1M": { interval: "1day", size: 30 },
  "6M": { interval: "1day", size: 130 },
  "YTD": { interval: "1day", size: 260 },
  "1Y": { interval: "1day", size: 260 },
  "5Y": { interval: "1week", size: 260 },
  "MAX": { interval: "1month", size: 240 },
};

export async function getCandles(input: string, range: CandleRange): Promise<CandleSeries> {
  const meta = resolveSymbol(input);
  if (!meta) throw new MarketDataError(`unknown symbol: ${input}`, "not_found");
  const { interval, size } = RANGE_MAP[range] ?? RANGE_MAP["1M"];
  const key = `mkt:c:${meta.symbol}:${range}`;
  const soft = interval === "1day" || interval === "1week" || interval === "1month"
    ? getMarketConfig().ttl.historicalDaily
    : getMarketConfig().ttl.historicalIntraday;

  const cached = await cacheRead<CandleSeries>(key);
  if (cached && !cached.stale) return cached.value;

  try {
    const series = await coalesce(key, () =>
      withProviders(meta.assetClass, "candles", (p) => {
        if (!p.getCandles) throw new MarketDataError("no candles", "unsupported", p.id);
        return p.getCandles(meta, interval, size);
      }),
    );
    await cacheWrite(key, series, soft);
    return series;
  } catch (e) {
    if (cached) return { ...cached.value };
    throw e;
  }
}

/* ─────────────────────────────── Movers ─────────────────────────────── */

export async function getMovers(market: string, kind: MoverKind): Promise<Movers> {
  const key = `mkt:mv:${market}:${kind}`;
  const cached = await cacheRead<Movers>(key);
  if (cached && !cached.stale) return cached.value;
  try {
    const m = await coalesce(key, () =>
      withProviders("stock", "movers", (p) => {
        if (!p.getMovers) throw new MarketDataError("no movers", "unsupported", p.id);
        return p.getMovers(market, kind);
      }),
    );
    await cacheWrite(key, m, 45);
    return m;
  } catch (e) {
    if (cached) return cached.value;
    throw e;
  }
}

/* ─────────────────────────────── Status / health ─────────────────────────────── */

export function getMarketStatus(market: string): MarketStatus {
  return marketStatus(market);
}

export function getAllMarketStatuses(): MarketStatus[] {
  return ["US", "IN", "UK", "EU", "JP", "HK", "CN", "CRYPTO", "FX"].map(marketStatus);
}

export async function providerHealth(): Promise<ProviderHealth[]> {
  const reg = getRegistry();
  return Promise.all(
    [...reg.entries()].map(async ([id, r]) => {
      const start = Date.now();
      let ok = false;
      let lastError: string | null = r.breaker.lastError;
      try { await r.provider.ping(); ok = true; lastError = null; }
      catch (e) { lastError = e instanceof Error ? e.message : String(e); }
      return {
        id,
        ok,
        circuit: r.breaker.status,
        lastError,
        lastCheck: new Date().toISOString(),
        latencyMs: ok ? Date.now() - start : null,
        rateLimit: { remaining: r.bucket.remaining, resetAt: null },
      };
    }),
  );
}

export async function marketHealth() {
  const cfg = getMarketConfig();
  const [providers, cache] = await Promise.all([providerHealth(), cachePing()]);
  return {
    mode: cfg.mock ? "mock" : "live",
    primary: cfg.primary,
    redis: cache.redis,
    publicDisplayLicensed:
      cfg.mock ||
      (cfg.primary === "twelvedata" && cfg.twelveData.externalDisplayLicense) ||
      (cfg.primary === "coingecko" && !!cfg.coinGecko.apiKey),
    providers,
  };
}

/* ─────────────────────────────── helpers ─────────────────────────────── */

function groupBy<T>(arr: T[], key: (t: T) => string): Record<string, T[]> {
  return arr.reduce<Record<string, T[]>>((acc, item) => {
    (acc[key(item)] ??= []).push(item);
    return acc;
  }, {});
}

export { resolveSymbol, symbolBySlug };
