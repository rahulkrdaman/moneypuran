/**
 * Deterministic mock provider — no API key, no licence, no network.
 *
 * Every value is stamped `entitlement: "mock"` and `source: "mock"` so the UI
 * labels it clearly and it can NEVER be mistaken for a real quote (spec §33, §38).
 * Prices are a smooth pseudo-random walk seeded by the symbol + the current
 * minute, so the ticker "moves" believably in a demo without being random noise
 * on every request.
 */
import type {
  CandleInterval, CandleSeries, Movers, MoverKind, Quote, SymbolMeta,
} from "../types";
import { marketForSymbol, marketStatus } from "../market-status";
import type { MarketDataProvider } from "./base";

function hash(str: string): number {
  let h = 2166136261;
  for (let i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 16777619); }
  return (h >>> 0) / 2 ** 32;
}

/** rough plausible base price per symbol so the demo doesn't look absurd */
function basePrice(meta: SymbolMeta): number {
  const seed = hash(meta.symbol);
  switch (meta.assetClass) {
    case "index":
      return { "^GSPC": 5600, "^IXIC": 18200, "^DJI": 41500, "^N225": 39000, "^HSI": 17800, "^FTSE": 8300, "^GDAXI": 18500, "^NSEI": 24200, "^BSESN": 79500 }[meta.symbol] ?? 3000 + seed * 9000;
    case "crypto":
      return { BTC: 68000, ETH: 3500, SOL: 150, BNB: 580, XRP: 0.55, USDT: 1, USDC: 1, DOGE: 0.13 }[meta.symbol] ?? 1 + seed * 200;
    case "forex":
      return { EURUSD: 1.08, GBPUSD: 1.27, USDJPY: 152, USDINR: 83.4, DXY: 104.5, AUDUSD: 0.66, USDCAD: 1.36 }[meta.symbol] ?? 0.5 + seed;
    case "commodity":
      return { XAU: 2350, XAG: 28, WTI: 78, BRENT: 82, NG: 2.4, HG: 4.1 }[meta.symbol] ?? 20 + seed * 200;
    default:
      return { AAPL: 225, MSFT: 430, NVDA: 120, GOOGL: 175, AMZN: 185, META: 500, TSLA: 240 }[meta.symbol] ?? 30 + seed * 400;
  }
}

function walk(meta: SymbolMeta, tSlot: number): { price: number; prevClose: number } {
  const base = basePrice(meta);
  const vol = meta.assetClass === "crypto" ? 0.05 : meta.assetClass === "forex" ? 0.004 : 0.015;
  const drift = (hash(meta.symbol + ":" + Math.floor(tSlot / 390)) - 0.5) * vol; // per-day drift
  const wiggle = (hash(meta.symbol + ":" + tSlot) - 0.5) * vol * 0.4;
  const prevClose = round(base * (1 + drift), meta);
  const price = round(prevClose * (1 + wiggle), meta);
  return { price, prevClose };
}

function round(n: number, meta: SymbolMeta): number {
  const dp = meta.assetClass === "forex" ? 4 : n < 5 ? 4 : n < 1000 ? 2 : 2;
  return Number(n.toFixed(dp));
}

export class MockProvider implements MarketDataProvider {
  readonly id = "mock";
  readonly displayName = "Mock (demo data)";
  readonly capabilities = {
    quote: ["stock", "etf", "index", "crypto", "forex", "commodity"] as const as unknown as import("../types").AssetClass[],
    candles: ["stock", "etf", "index", "crypto", "forex", "commodity"] as const as unknown as import("../types").AssetClass[],
    movers: true,
    batchQuote: true,
  };

  async ping() { /* always healthy */ }

  supports() { return true; }

  async getQuote(meta: SymbolMeta): Promise<Quote> {
    const tSlot = Math.floor(Date.now() / 60_000);
    const { price, prevClose } = walk(meta, tSlot);
    const changeAbs = round(price - prevClose, meta);
    const changePct = Number(((changeAbs / prevClose) * 100).toFixed(2));
    const market = marketForSymbol(meta.assetClass, meta.market);
    return {
      symbol: meta.symbol,
      name: meta.name,
      assetClass: meta.assetClass,
      price,
      currency: meta.currency,
      changeAbs,
      changePct,
      previousClose: prevClose,
      open: prevClose,
      dayHigh: round(Math.max(price, prevClose) * 1.006, meta),
      dayLow: round(Math.min(price, prevClose) * 0.994, meta),
      yearHigh: round(prevClose * 1.35, meta),
      yearLow: round(prevClose * 0.72, meta),
      volume: meta.assetClass === "forex" ? null : Math.round(hash(meta.symbol + tSlot) * 5_000_000 + 100_000),
      marketCap: meta.assetClass === "crypto" ? Math.round(price * (hash(meta.symbol) * 4e8 + 1e7)) : null,
      asOf: new Date().toISOString(),
      stale: false,
      entitlement: "mock",
      source: "mock",
      exchange: meta.exchange,
      timezone: marketStatus(market).timezone,
      marketState: marketStatus(market).state,
    };
  }

  async getQuotes(metas: SymbolMeta[]): Promise<Quote[]> {
    return Promise.all(metas.map((m) => this.getQuote(m)));
  }

  async getCandles(meta: SymbolMeta, interval: CandleInterval, outputSize: number): Promise<CandleSeries> {
    const stepMs = intervalMs(interval);
    const nowMs = Date.now();
    const candles = [];
    let c = walk(meta, Math.floor((nowMs - outputSize * stepMs) / 60_000)).price;
    for (let i = outputSize - 1; i >= 0; i--) {
      const t = nowMs - i * stepMs;
      const drift = (hash(meta.symbol + ":c:" + Math.floor(t / stepMs)) - 0.5) * (meta.assetClass === "crypto" ? 0.03 : 0.012);
      const o = c;
      c = round(o * (1 + drift), meta);
      const h = round(Math.max(o, c) * (1 + Math.abs(drift) * 0.4), meta);
      const l = round(Math.min(o, c) * (1 - Math.abs(drift) * 0.4), meta);
      candles.push({ t: t - (t % stepMs), o, h, l, c, v: meta.assetClass === "forex" ? null : Math.round(hash(meta.symbol + t) * 2_000_000) });
    }
    return { symbol: meta.symbol, assetClass: meta.assetClass, interval, candles, asOf: new Date().toISOString(), entitlement: "mock", source: "mock" };
  }

  async getMovers(market: string, kind: MoverKind): Promise<Movers> {
    // fabricate from the mock quotes of the stock universe
    const { STOCKS } = await import("../symbols");
    const pool = STOCKS.filter((s) => (market === "IN" ? s.market === "IN" : s.market !== "IN"));
    const quotes = await this.getQuotes(pool);
    const sorted = [...quotes].sort((a, b) => {
      if (kind === "losers") return (a.changePct ?? 0) - (b.changePct ?? 0);
      if (kind === "active" || kind === "volume") return (b.volume ?? 0) - (a.volume ?? 0);
      return (b.changePct ?? 0) - (a.changePct ?? 0);
    });
    return {
      market, kind,
      entries: sorted.slice(0, 10).map((q) => ({
        symbol: q.symbol, name: q.name, price: q.price,
        changeAbs: q.changeAbs ?? 0, changePct: q.changePct ?? 0, volume: q.volume,
      })),
      asOf: new Date().toISOString(), entitlement: "mock", source: "mock",
    };
  }
}

function intervalMs(i: CandleInterval): number {
  return { "1min": 6e4, "5min": 3e5, "15min": 9e5, "30min": 18e5, "1h": 36e5, "4h": 144e5, "1day": 864e5, "1week": 6048e5, "1month": 2592e6 }[i];
}
