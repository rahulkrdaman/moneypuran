/**
 * The curated MoneyPuran symbol universe.
 *
 * This is the single source of truth for:
 *   - which entities get a programmatic SEO page (/stocks/apple, /crypto/bitcoin, …)
 *   - the canonical symbol ↔ slug ↔ provider-symbol mapping
 *   - the global ticker contents
 *
 * Keep it deliberately curated (high search-intent names) rather than "every
 * ticker" — thin auto-generated pages are penalised (spec §24).
 */
import type { SymbolMeta } from "./types";

/* ───────────────────────────── Indices ───────────────────────────── */
export const INDICES: SymbolMeta[] = [
  { symbol: "^GSPC", name: "S&P 500", slug: "sp-500", assetClass: "index", currency: "USD", market: "US",
    aliases: ["SPX", "S&P500", "SP500"], providerSymbols: { twelvedata: "SPX", alphavantage: "SPY" },
    description: "The S&P 500 tracks 500 of the largest US-listed companies, weighted by float-adjusted market capitalisation." },
  { symbol: "^IXIC", name: "Nasdaq Composite", slug: "nasdaq", assetClass: "index", currency: "USD", market: "US",
    aliases: ["NASDAQ", "IXIC"], providerSymbols: { twelvedata: "IXIC", alphavantage: "QQQ" },
    description: "The Nasdaq Composite covers almost all stocks listed on the Nasdaq exchange, skewed heavily toward technology." },
  { symbol: "^DJI", name: "Dow Jones Industrial Average", slug: "dow-jones", assetClass: "index", currency: "USD", market: "US",
    aliases: ["DOW", "DJIA", "DJI"], providerSymbols: { twelvedata: "DJI", alphavantage: "DIA" },
    description: "The Dow Jones Industrial Average is a price-weighted index of 30 large US companies." },
  { symbol: "^RUT", name: "Russell 2000", slug: "russell-2000", assetClass: "index", currency: "USD", market: "US",
    aliases: ["RUT"], providerSymbols: { twelvedata: "RUT", alphavantage: "IWM" },
    description: "The Russell 2000 measures the performance of about 2,000 small-cap US companies." },
  { symbol: "^FTSE", name: "FTSE 100", slug: "ftse-100", assetClass: "index", currency: "GBP", market: "UK",
    aliases: ["FTSE", "UKX"], providerSymbols: { twelvedata: "UKX" },
    description: "The FTSE 100 tracks the 100 largest companies on the London Stock Exchange by market capitalisation." },
  { symbol: "^GDAXI", name: "DAX", slug: "dax", assetClass: "index", currency: "EUR", market: "EU",
    aliases: ["DAX", "GDAXI"], providerSymbols: { twelvedata: "DAX" },
    description: "The DAX tracks 40 major German companies trading on the Frankfurt Stock Exchange." },
  { symbol: "^FCHI", name: "CAC 40", slug: "cac-40", assetClass: "index", currency: "EUR", market: "EU",
    aliases: ["CAC", "CAC40", "FCHI"], providerSymbols: { twelvedata: "CAC" },
    description: "The CAC 40 is the benchmark index of the 40 largest listed companies in France." },
  { symbol: "^N225", name: "Nikkei 225", slug: "nikkei-225", assetClass: "index", currency: "JPY", market: "JP",
    aliases: ["NIKKEI", "N225"], providerSymbols: { twelvedata: "N225" },
    description: "The Nikkei 225 is a price-weighted index of 225 large Japanese companies listed in Tokyo." },
  { symbol: "^HSI", name: "Hang Seng", slug: "hang-seng", assetClass: "index", currency: "HKD", market: "HK",
    aliases: ["HSI", "HANGSENG"], providerSymbols: { twelvedata: "HSI" },
    description: "The Hang Seng Index tracks the largest companies listed on the Hong Kong Stock Exchange." },
  { symbol: "^SSEC", name: "Shanghai Composite", slug: "shanghai-composite", assetClass: "index", currency: "CNY", market: "CN",
    aliases: ["SSEC", "SHCOMP"], providerSymbols: { twelvedata: "SHCOMP" },
    description: "The Shanghai Composite tracks all A-shares and B-shares listed on the Shanghai Stock Exchange." },
  { symbol: "^NSEI", name: "Nifty 50", slug: "nifty-50", assetClass: "index", currency: "INR", market: "IN",
    aliases: ["NIFTY", "NIFTY50", "NSEI"], providerSymbols: { twelvedata: "NIFTY 50" },
    description: "The Nifty 50 is the National Stock Exchange of India's benchmark index of 50 large Indian companies." },
  { symbol: "^BSESN", name: "BSE Sensex", slug: "sensex", assetClass: "index", currency: "INR", market: "IN",
    aliases: ["SENSEX", "BSESN"], providerSymbols: { twelvedata: "SENSEX" },
    description: "The S&P BSE Sensex tracks 30 large, established companies on the Bombay Stock Exchange." },
];

/* ───────────────────────────── Stocks / ETFs ───────────────────────────── */
export const STOCKS: SymbolMeta[] = [
  s("AAPL", "Apple Inc.", "apple", ["APPLE"]),
  s("MSFT", "Microsoft Corporation", "microsoft", ["MICROSOFT"]),
  s("NVDA", "NVIDIA Corporation", "nvidia", ["NVIDIA"]),
  s("GOOGL", "Alphabet Inc.", "alphabet", ["GOOGLE", "GOOG"]),
  s("AMZN", "Amazon.com, Inc.", "amazon", ["AMAZON"]),
  s("META", "Meta Platforms, Inc.", "meta", ["FACEBOOK", "FB"]),
  s("TSLA", "Tesla, Inc.", "tesla", ["TESLA"]),
  s("BRK.B", "Berkshire Hathaway Inc.", "berkshire-hathaway", ["BRKB", "BERKSHIRE"]),
  s("JPM", "JPMorgan Chase & Co.", "jpmorgan-chase", ["JPMORGAN"]),
  s("V", "Visa Inc.", "visa", ["VISA"]),
  s("WMT", "Walmart Inc.", "walmart", ["WALMART"]),
  s("XOM", "Exxon Mobil Corporation", "exxon-mobil", ["EXXON"]),
  s("UNH", "UnitedHealth Group Incorporated", "unitedhealth", []),
  s("LLY", "Eli Lilly and Company", "eli-lilly", ["LILLY"]),
  s("AVGO", "Broadcom Inc.", "broadcom", ["BROADCOM"]),
  s("AMD", "Advanced Micro Devices, Inc.", "amd", ["AMD"]),
  s("NFLX", "Netflix, Inc.", "netflix", ["NETFLIX"]),
  s("CRM", "Salesforce, Inc.", "salesforce", ["SALESFORCE"]),
  s("BAC", "Bank of America Corporation", "bank-of-america", []),
  s("KO", "The Coca-Cola Company", "coca-cola", ["COKE"]),
  etf("SPY", "SPDR S&P 500 ETF Trust", "spy"),
  etf("QQQ", "Invesco QQQ Trust", "qqq"),
  etf("VOO", "Vanguard S&P 500 ETF", "voo"),
  etf("VTI", "Vanguard Total Stock Market ETF", "vti"),
  etf("IWM", "iShares Russell 2000 ETF", "iwm"),
  // India (Nifty heavyweights) — NSE symbols
  s("RELIANCE", "Reliance Industries Limited", "reliance-industries", ["RELIANCE"], "INR", "IN", { twelvedata: "RELIANCE:NSE" }),
  s("TCS", "Tata Consultancy Services Limited", "tcs", ["TCS"], "INR", "IN", { twelvedata: "TCS:NSE" }),
  s("HDFCBANK", "HDFC Bank Limited", "hdfc-bank", ["HDFCBANK"], "INR", "IN", { twelvedata: "HDFCBANK:NSE" }),
  s("INFY", "Infosys Limited", "infosys", ["INFOSYS", "INFY"], "INR", "IN", { twelvedata: "INFY:NSE" }),
  s("ICICIBANK", "ICICI Bank Limited", "icici-bank", ["ICICIBANK"], "INR", "IN", { twelvedata: "ICICIBANK:NSE" }),
];

/* ───────────────────────────── Crypto ───────────────────────────── */
export const CRYPTO: SymbolMeta[] = [
  c("BTC", "Bitcoin", "bitcoin", "bitcoin"),
  c("ETH", "Ethereum", "ethereum", "ethereum"),
  c("SOL", "Solana", "solana", "solana"),
  c("XRP", "XRP", "xrp", "ripple"),
  c("BNB", "BNB", "bnb", "binancecoin"),
  c("ADA", "Cardano", "cardano", "cardano"),
  c("DOGE", "Dogecoin", "dogecoin", "dogecoin"),
  c("USDT", "Tether", "tether", "tether"),
  c("USDC", "USD Coin", "usd-coin", "usd-coin"),
  c("TRX", "TRON", "tron", "tron"),
  c("AVAX", "Avalanche", "avalanche", "avalanche-2"),
  c("LINK", "Chainlink", "chainlink", "chainlink"),
  c("DOT", "Polkadot", "polkadot", "polkadot"),
  c("MATIC", "Polygon", "polygon", "matic-network"),
  c("LTC", "Litecoin", "litecoin", "litecoin"),
];

/* ───────────────────────────── Forex ───────────────────────────── */
export const FOREX: SymbolMeta[] = [
  fx("EURUSD", "Euro / US Dollar", "eur-usd"),
  fx("GBPUSD", "British Pound / US Dollar", "gbp-usd"),
  fx("USDJPY", "US Dollar / Japanese Yen", "usd-jpy"),
  fx("USDINR", "US Dollar / Indian Rupee", "usd-inr"),
  fx("USDCNY", "US Dollar / Chinese Yuan", "usd-cny"),
  fx("USDCAD", "US Dollar / Canadian Dollar", "usd-cad"),
  fx("AUDUSD", "Australian Dollar / US Dollar", "aud-usd"),
  fx("USDCHF", "US Dollar / Swiss Franc", "usd-chf"),
  fx("USDKRW", "US Dollar / South Korean Won", "usd-krw"),
  fx("USDBRL", "US Dollar / Brazilian Real", "usd-brl"),
  { symbol: "DXY", name: "US Dollar Index", slug: "dxy", assetClass: "forex", currency: "USD",
    aliases: ["DXY", "USDOLLAR"], providerSymbols: { twelvedata: "DXY" },
    description: "The US Dollar Index (DXY) measures the dollar against a basket of six major currencies." },
];

/* ───────────────────────────── Commodities ───────────────────────────── */
export const COMMODITIES: SymbolMeta[] = [
  cm("XAU", "Gold", "gold", { twelvedata: "XAU/USD" }, "Gold is priced per troy ounce in US dollars and is widely used as a store of value and inflation hedge."),
  cm("XAG", "Silver", "silver", { twelvedata: "XAG/USD" }, "Silver is priced per troy ounce in US dollars and has both monetary and industrial demand."),
  cm("WTI", "WTI Crude Oil", "wti-crude", { twelvedata: "WTI/USD" }, "West Texas Intermediate is the main US crude oil benchmark, priced per barrel."),
  cm("BRENT", "Brent Crude Oil", "brent-crude", { twelvedata: "BRENT/USD" }, "Brent Crude is the primary international oil benchmark, priced per barrel."),
  cm("NG", "Natural Gas", "natural-gas", { twelvedata: "NG/USD" }, "Henry Hub natural gas is the US benchmark, priced per million British thermal units (MMBtu)."),
  cm("HG", "Copper", "copper", { twelvedata: "COPPER" }, "Copper is priced per pound and is often read as a barometer of global industrial demand."),
];

/* ───────────────────────────── Helpers ───────────────────────────── */
function s(
  symbol: string, name: string, slug: string, aliases: string[] = [],
  currency = "USD", market = "US", providerSymbols?: SymbolMeta["providerSymbols"],
): SymbolMeta {
  return { symbol, name, slug, assetClass: "stock", currency, market, exchange: market === "IN" ? "NSE" : "NASDAQ/NYSE", aliases, providerSymbols };
}
function etf(symbol: string, name: string, slug: string): SymbolMeta {
  return { symbol, name, slug, assetClass: "etf", currency: "USD", market: "US", aliases: [symbol] };
}
function c(symbol: string, name: string, slug: string, coingeckoId: string): SymbolMeta {
  return { symbol, name, slug, assetClass: "crypto", currency: "USD", aliases: [symbol, name.toUpperCase()], providerSymbols: { coingecko: coingeckoId, twelvedata: `${symbol}/USD` } };
}
function fx(symbol: string, name: string, slug: string): SymbolMeta {
  const base = symbol.slice(0, 3), quote = symbol.slice(3);
  return { symbol, name, slug, assetClass: "forex", currency: quote, aliases: [symbol, `${base}/${quote}`, `${base}-${quote}`], providerSymbols: { twelvedata: `${base}/${quote}`, alphavantage: `${base}${quote}` } };
}
function cm(symbol: string, name: string, slug: string, providerSymbols: SymbolMeta["providerSymbols"], description: string): SymbolMeta {
  return { symbol, name, slug, assetClass: "commodity", currency: "USD", aliases: [symbol, name.toUpperCase()], providerSymbols, description };
}

/* ───────────────────────────── Lookups ───────────────────────────── */

export const ALL_SYMBOLS: SymbolMeta[] = [
  ...INDICES, ...STOCKS, ...CRYPTO, ...FOREX, ...COMMODITIES,
];

const BY_SYMBOL = new Map<string, SymbolMeta>();
const BY_SLUG = new Map<string, SymbolMeta>();
const BY_ALIAS = new Map<string, SymbolMeta>();
for (const m of ALL_SYMBOLS) {
  BY_SYMBOL.set(m.symbol.toUpperCase(), m);
  BY_SLUG.set(`${m.assetClass}:${m.slug.toLowerCase()}`, m);
  BY_SLUG.set(m.slug.toLowerCase(), m);
  for (const a of m.aliases ?? []) BY_ALIAS.set(a.toUpperCase().replace(/[\s/_-]/g, ""), m);
}

/** Resolve a user-supplied ticker/name/slug to a canonical SymbolMeta. */
export function resolveSymbol(input: string, assetClass?: SymbolMeta["assetClass"]): SymbolMeta | null {
  if (!input) return null;
  const raw = input.trim();
  const up = raw.toUpperCase();
  const norm = up.replace(/[\s/_-]/g, "");
  return (
    BY_SYMBOL.get(up) ||
    (assetClass && BY_SLUG.get(`${assetClass}:${raw.toLowerCase()}`)) ||
    BY_SLUG.get(raw.toLowerCase()) ||
    BY_ALIAS.get(norm) ||
    null
  );
}

export function symbolBySlug(assetClass: SymbolMeta["assetClass"], slug: string): SymbolMeta | null {
  return BY_SLUG.get(`${assetClass}:${slug.toLowerCase()}`) || null;
}

/** provider-specific symbol string, falling back to the canonical symbol. */
export function providerSymbol(meta: SymbolMeta, providerId: string): string {
  return meta.providerSymbols?.[providerId] ?? meta.symbol;
}

/** The default global-ticker line-up (spec §4). */
export const TICKER_SYMBOLS = [
  "^GSPC", "^IXIC", "^DJI", "^FTSE", "^GDAXI", "^FCHI",
  "^N225", "^HSI", "^SSEC", "^NSEI", "^BSESN",
  "BTC", "ETH", "XAU", "BRENT", "WTI",
  "EURUSD", "GBPUSD", "USDJPY",
];
