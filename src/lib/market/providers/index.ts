import { getMarketConfig, type ProviderId } from "../config";
import { CircuitBreaker, TokenBucket } from "../resilience";
import type { MarketDataProvider } from "./base";
import { AlphaVantageProvider } from "./alphavantage";
import { CoinGeckoProvider } from "./coingecko";
import { MockProvider } from "./mock";
import { TwelveDataProvider } from "./twelvedata";

export interface RegisteredProvider {
  provider: MarketDataProvider;
  breaker: CircuitBreaker;
  bucket: TokenBucket;
}

/** rough free-tier-safe rate limits; tune per purchased plan */
const RATE: Record<ProviderId, { capacity: number; perSec: number }> = {
  twelvedata: { capacity: 8, perSec: 8 / 60 },      // ~8 req/min on free
  coingecko: { capacity: 10, perSec: 10 / 60 },     // ~10-30 req/min public
  alphavantage: { capacity: 5, perSec: 5 / 60 },    // 25/day → keep it tiny
  mock: { capacity: 10000, perSec: 10000 },
};

let registry: Map<ProviderId, RegisteredProvider> | null = null;

export function getRegistry(): Map<ProviderId, RegisteredProvider> {
  if (registry) return registry;
  const cfg = getMarketConfig();
  registry = new Map();

  const add = (id: ProviderId, p: MarketDataProvider) => {
    registry!.set(id, {
      provider: p,
      breaker: new CircuitBreaker(id, 5, 30_000),
      bucket: new TokenBucket(RATE[id].capacity, RATE[id].perSec),
    });
  };

  if (cfg.mock) {
    add("mock", new MockProvider());
    return registry;
  }
  add("twelvedata", new TwelveDataProvider());
  add("coingecko", new CoinGeckoProvider());
  add("alphavantage", new AlphaVantageProvider());
  add("mock", new MockProvider()); // last-resort so the UI never blanks in dev
  return registry;
}

/**
 * Ordered list of providers to try for a given asset class, honouring config
 * preference, capability, and circuit state.
 */
export function providerChain(assetClass: string): RegisteredProvider[] {
  const cfg = getMarketConfig();
  const reg = getRegistry();
  if (cfg.mock) return [reg.get("mock")!];

  // asset-class preference overlay
  const preferred: ProviderId[] =
    assetClass === "crypto"
      ? ["coingecko", "twelvedata", "alphavantage"]
      : [cfg.primary, ...cfg.fallbacks];

  const order = [...new Set([...preferred, "twelvedata", "coingecko", "alphavantage"])] as ProviderId[];
  const chain: RegisteredProvider[] = [];
  for (const id of order) {
    const r = reg.get(id);
    if (r && r.provider.supports(assetClass as never)) chain.push(r);
  }
  // mock as absolute last resort (dev only — never selected when a real provider works)
  if (process.env.NODE_ENV !== "production") chain.push(reg.get("mock")!);
  return chain;
}

export function __resetRegistry() {
  registry = null;
}
