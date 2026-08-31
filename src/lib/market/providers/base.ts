/**
 * Provider contract. The MarketDataService only ever talks to this interface —
 * it never knows which concrete provider (Twelve Data, CoinGecko, …) answered.
 */
import type {
  AssetClass, CandleInterval, CandleSeries, Movers, MoverKind, Quote, SymbolMeta,
} from "../types";

export interface ProviderCapabilities {
  quote: AssetClass[];
  candles: AssetClass[];
  movers: boolean;
  batchQuote: boolean;
}

export interface MarketDataProvider {
  readonly id: string;
  readonly displayName: string;
  readonly capabilities: ProviderCapabilities;

  /** cheap call used for health checks; resolves or throws. */
  ping(): Promise<void>;

  supports(assetClass: AssetClass): boolean;

  getQuote(meta: SymbolMeta): Promise<Quote>;

  /** optional batch — service falls back to N getQuote() calls if absent. */
  getQuotes?(metas: SymbolMeta[]): Promise<Quote[]>;

  getCandles?(meta: SymbolMeta, interval: CandleInterval, outputSize: number): Promise<CandleSeries>;

  getMovers?(market: string, kind: MoverKind): Promise<Movers>;
}

/** Narrower helper interfaces (spec §5) — same object, typed views. */
export type StockProvider = MarketDataProvider;
export type CryptoProvider = MarketDataProvider;
export type ForexProvider = MarketDataProvider;
export type CommodityProvider = MarketDataProvider;
export type IndexProvider = MarketDataProvider;

export const num = (v: unknown): number | null => {
  const n = typeof v === "string" ? Number(v.replace(/,/g, "")) : typeof v === "number" ? v : NaN;
  return Number.isFinite(n) ? n : null;
};
