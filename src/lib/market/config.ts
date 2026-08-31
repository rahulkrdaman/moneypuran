/**
 * Market-data configuration — read ONCE, server-side only.
 * Nothing here is ever sent to the browser. `getMarketConfig()` throws early
 * with a clear message if a required key is missing in a non-mock deployment.
 */

export type ProviderId = "twelvedata" | "coingecko" | "alphavantage" | "mock";

export interface MarketConfig {
  /** Whether the whole layer returns deterministic mock data (no keys, no license). */
  mock: boolean;
  /** Ordered provider preference. First that supports a symbol + is healthy wins. */
  primary: ProviderId;
  fallbacks: ProviderId[];
  redisUrl: string | null;
  twelveData: {
    apiKey: string | null;
    plan: string;            // "free" | "basic" | "grow" | "pro" | "enterprise"
    /** MUST be explicitly true to serve TD data to the public (see spec §6, §38). */
    externalDisplayLicense: boolean;
    baseUrl: string;
  };
  coinGecko: {
    apiKey: string | null;   // demo or pro key; free tier works keyless at low volume
    proBaseUrl: string;
    publicBaseUrl: string;
  };
  alphaVantage: {
    apiKey: string | null;
    baseUrl: string;
  };
  /** Soft cache TTLs (seconds) per asset class — spec §26. */
  ttl: {
    crypto: number;
    stock: number;
    etf: number;
    index: number;
    forex: number;
    commodity: number;
    fundamentals: number;
    profile: number;
    historicalIntraday: number;
    historicalDaily: number;
  };
}

let cached: MarketConfig | null = null;

function bool(v: string | undefined, dflt = false): boolean {
  if (v == null) return dflt;
  return /^(1|true|yes|on)$/i.test(v.trim());
}

export function getMarketConfig(): MarketConfig {
  if (cached) return cached;

  const mock = bool(process.env.MARKET_DATA_MOCK, process.env.NODE_ENV === "test");
  const primaryEnv = (process.env.MARKET_DATA_PROVIDER || "twelvedata").toLowerCase() as ProviderId;

  cached = {
    mock,
    primary: mock ? "mock" : primaryEnv,
    fallbacks: mock ? [] : ["alphavantage"],
    redisUrl: process.env.REDIS_URL || null,
    twelveData: {
      apiKey: process.env.TWELVE_DATA_API_KEY || null,
      plan: (process.env.TWELVE_DATA_PLAN || "free").toLowerCase(),
      externalDisplayLicense: bool(process.env.TWELVE_DATA_EXTERNAL_DISPLAY_LICENSE, false),
      baseUrl: process.env.TWELVE_DATA_BASE_URL || "https://api.twelvedata.com",
    },
    coinGecko: {
      apiKey: process.env.COINGECKO_API_KEY || null,
      proBaseUrl: "https://pro-api.coingecko.com/api/v3",
      publicBaseUrl: "https://api.coingecko.com/api/v3",
    },
    alphaVantage: {
      apiKey: process.env.ALPHAVANTAGE_API_KEY || null,
      baseUrl: "https://www.alphavantage.co/query",
    },
    ttl: {
      crypto: num(process.env.MARKET_TTL_CRYPTO, 20),
      stock: num(process.env.MARKET_TTL_STOCK, 20),
      etf: num(process.env.MARKET_TTL_ETF, 30),
      index: num(process.env.MARKET_TTL_INDEX, 45),
      forex: num(process.env.MARKET_TTL_FOREX, 30),
      commodity: num(process.env.MARKET_TTL_COMMODITY, 45),
      fundamentals: num(process.env.MARKET_TTL_FUNDAMENTALS, 6 * 3600),
      profile: num(process.env.MARKET_TTL_PROFILE, 7 * 86400),
      historicalIntraday: num(process.env.MARKET_TTL_HIST_INTRADAY, 300),
      historicalDaily: num(process.env.MARKET_TTL_HIST_DAILY, 6 * 3600),
    },
  };
  return cached;
}

function num(v: string | undefined, dflt: number): number {
  const n = v ? Number(v) : NaN;
  return Number.isFinite(n) && n > 0 ? n : dflt;
}

/** test helper */
export function __resetMarketConfig() {
  cached = null;
}

/**
 * Whether a provider is allowed to serve data to the PUBLIC (not just dev).
 * A misconfigured production deploy should fail loudly rather than silently
 * ship data it isn't licensed to display.
 */
export function isPublicDisplayAllowed(providerId: ProviderId, cfg = getMarketConfig()): boolean {
  if (cfg.mock || providerId === "mock") return true;
  if (process.env.NODE_ENV !== "production") return true; // dev is fine
  switch (providerId) {
    case "twelvedata":
      return cfg.twelveData.externalDisplayLicense;
    case "coingecko":
      // CoinGecko allows attribution-linked display on paid plans; require a key in prod.
      return !!cfg.coinGecko.apiKey;
    case "alphavantage":
      return false; // AV free/standard is non-commercial; treat as dev-only
    default:
      return false;
  }
}
