/**
 * Normalized market-data shapes. Every provider maps into these; the rest of the
 * app (API routes, components, workers) only ever sees these — never a raw
 * provider payload, never a provider name, never an API key.
 */

export type AssetClass = "stock" | "etf" | "index" | "crypto" | "forex" | "commodity";

/** How fresh the data behind a value is — shown to the user, never guessed. */
export type Entitlement = "realtime" | "delayed" | "eod" | "mock";

export type MarketState = "open" | "pre" | "post" | "closed" | "holiday" | "unknown";

/** A single normalized quote for any asset class. */
export interface Quote {
  symbol: string;            // canonical MoneyPuran symbol, e.g. "AAPL", "BTC", "EURUSD", "^GSPC", "XAU"
  name: string;
  assetClass: AssetClass;
  price: number;
  currency: string;          // "USD", "INR", …
  changeAbs: number | null;  // vs previous close
  changePct: number | null;
  previousClose: number | null;
  open: number | null;
  dayHigh: number | null;
  dayLow: number | null;
  yearHigh: number | null;
  yearLow: number | null;
  volume: number | null;
  marketCap: number | null;  // crypto / equities where available
  asOf: string;              // ISO timestamp of the underlying data point
  stale: boolean;            // true if served from cache past its soft-TTL
  entitlement: Entitlement;
  source: string;            // provider id, for logging/telemetry only (safe to expose)
  exchange?: string;
  timezone?: string;
  marketState?: MarketState;
}

/** One OHLCV bar. */
export interface Candle {
  t: number;   // epoch ms (bar open)
  o: number;
  h: number;
  l: number;
  c: number;
  v: number | null;
}

export interface CandleSeries {
  symbol: string;
  assetClass: AssetClass;
  interval: CandleInterval;
  candles: Candle[];
  asOf: string;
  entitlement: Entitlement;
  source: string;
}

export type CandleInterval =
  | "1min" | "5min" | "15min" | "30min" | "1h" | "4h"
  | "1day" | "1week" | "1month";

/** Range presets used by the chart UI, mapped to interval + lookback. */
export type CandleRange = "1D" | "5D" | "1M" | "6M" | "YTD" | "1Y" | "5Y" | "MAX";

export interface MarketStatus {
  market: string;            // "US", "IN", "UK", "EU", "JP", "HK", "CN", "CRYPTO", "FX"
  state: MarketState;
  label: string;             // human string, e.g. "Open", "Closed — reopens Mon 09:30 ET"
  nextOpen: string | null;   // ISO
  nextClose: string | null;  // ISO
  timezone: string;
  asOf: string;
}

export interface MoverEntry {
  symbol: string;
  name: string;
  price: number;
  changeAbs: number;
  changePct: number;
  volume: number | null;
}

export type MoverKind = "gainers" | "losers" | "active" | "volume" | "high52" | "low52";

export interface Movers {
  market: string;            // "US" | "IN" | …
  kind: MoverKind;
  entries: MoverEntry[];
  asOf: string;
  entitlement: Entitlement;
  source: string;
}

export interface ProviderHealth {
  id: string;
  ok: boolean;
  circuit: "closed" | "open" | "half-open";
  lastError: string | null;
  lastCheck: string;
  latencyMs: number | null;
  rateLimit: { remaining: number | null; resetAt: string | null };
}

/** Metadata for a symbol in the curated universe — drives SEO pages + routing. */
export interface SymbolMeta {
  symbol: string;            // canonical MoneyPuran symbol
  name: string;
  assetClass: AssetClass;
  slug: string;              // URL slug, e.g. "apple", "bitcoin", "sp-500", "eur-usd", "gold"
  aliases?: string[];        // extra tickers people search, e.g. ["NVDA", "NVIDIA"]
  currency: string;
  exchange?: string;
  market?: string;           // "US" | "IN" | "UK" | …
  /** per-provider symbol overrides; falls back to `symbol` */
  providerSymbols?: Partial<Record<string, string>>;
  description?: string;      // 1-2 sentence factual blurb for the page (NOT price commentary)
}

export class MarketDataError extends Error {
  constructor(
    message: string,
    public code:
      | "not_found"
      | "rate_limited"
      | "provider_error"
      | "unsupported"
      | "no_license"
      | "config"
      | "timeout",
    public providerId?: string,
    public status?: number,
  ) {
    super(message);
    this.name = "MarketDataError";
  }
}
