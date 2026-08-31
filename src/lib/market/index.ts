/** Public entrypoint for the market-data layer. Import from "@/lib/market". */
export * from "./types";
export * from "./format";
export {
  getQuote, getQuotes, getTicker, getCandles, getMovers,
  getMarketStatus, getAllMarketStatuses, marketHealth, providerHealth,
  resolveSymbol, symbolBySlug,
} from "./service";
export {
  ALL_SYMBOLS, INDICES, STOCKS, CRYPTO, FOREX, COMMODITIES, TICKER_SYMBOLS,
} from "./symbols";
export { getMarketConfig, isPublicDisplayAllowed } from "./config";
