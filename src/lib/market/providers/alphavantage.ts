/**
 * Alpha Vantage provider — FALLBACK only (spec §8). Free tier is 25 req/day and
 * non-commercial, so `isPublicDisplayAllowed()` returns false for it in prod.
 * Kept for dev, fundamentals, and as a circuit-breaker fallback for quotes.
 */
import { getMarketConfig } from "../config";
import { fetchJson } from "../resilience";
import { marketForSymbol, marketStatus } from "../market-status";
import { providerSymbol } from "../symbols";
import type {
  AssetClass, CandleInterval, CandleSeries, Quote, SymbolMeta,
} from "../types";
import { MarketDataError } from "../types";
import { num, type MarketDataProvider } from "./base";

const SUPPORTED: AssetClass[] = ["stock", "etf", "forex"];

export class AlphaVantageProvider implements MarketDataProvider {
  readonly id = "alphavantage";
  readonly displayName = "Alpha Vantage";
  readonly capabilities = { quote: SUPPORTED, candles: ["stock", "etf"] as AssetClass[], movers: false, batchQuote: false };

  private get cfg() { return getMarketConfig().alphaVantage; }

  private url(params: Record<string, string>): string {
    const key = this.cfg.apiKey;
    if (!key) throw new MarketDataError("ALPHAVANTAGE_API_KEY not set", "config", this.id);
    return `${this.cfg.baseUrl}?${new URLSearchParams({ ...params, apikey: key })}`;
  }

  supports(a: AssetClass) { return SUPPORTED.includes(a); }

  async ping() {
    const { data } = await fetchJson<Record<string, unknown>>(
      this.url({ function: "GLOBAL_QUOTE", symbol: "AAPL" }), { timeoutMs: 6000 },
    );
    if ((data as { Note?: string }).Note || (data as { Information?: string }).Information) {
      throw new MarketDataError("rate limited / info response", "rate_limited", this.id);
    }
  }

  async getQuote(meta: SymbolMeta): Promise<Quote> {
    if (meta.assetClass === "forex") return this.forexQuote(meta);
    const sym = providerSymbol(meta, this.id);
    const { data } = await fetchJson<{ "Global Quote"?: AVQuote; Note?: string; Information?: string }>(
      this.url({ function: "GLOBAL_QUOTE", symbol: sym }), { timeoutMs: 8000 },
    );
    if (data.Note || data.Information) throw new MarketDataError("rate limited", "rate_limited", this.id);
    const q = data["Global Quote"];
    if (!q || !q["05. price"]) throw new MarketDataError(`no data for ${sym}`, "not_found", this.id);
    const market = marketForSymbol(meta.assetClass, meta.market);
    const st = marketStatus(market);
    const price = num(q["05. price"])!;
    const prev = num(q["08. previous close"]);
    return {
      symbol: meta.symbol, name: meta.name, assetClass: meta.assetClass, price,
      currency: meta.currency,
      changeAbs: num(q["09. change"]),
      changePct: num((q["10. change percent"] || "").replace("%", "")),
      previousClose: prev,
      open: num(q["02. open"]), dayHigh: num(q["03. high"]), dayLow: num(q["04. low"]),
      yearHigh: null, yearLow: null,
      volume: num(q["06. volume"]), marketCap: null,
      asOf: q["07. latest trading day"] ? `${q["07. latest trading day"]}T00:00:00Z` : new Date().toISOString(),
      stale: false, entitlement: "eod", source: this.id, marketState: st.state, timezone: st.timezone,
    };
  }

  private async forexQuote(meta: SymbolMeta): Promise<Quote> {
    const from = meta.symbol.slice(0, 3), to = meta.symbol.slice(3);
    const { data } = await fetchJson<{ "Realtime Currency Exchange Rate"?: Record<string, string>; Note?: string }>(
      this.url({ function: "CURRENCY_EXCHANGE_RATE", from_currency: from, to_currency: to }), { timeoutMs: 8000 },
    );
    if (data.Note) throw new MarketDataError("rate limited", "rate_limited", this.id);
    const r = data["Realtime Currency Exchange Rate"];
    if (!r) throw new MarketDataError(`no fx ${meta.symbol}`, "not_found", this.id);
    const price = num(r["5. Exchange Rate"])!;
    return {
      symbol: meta.symbol, name: meta.name, assetClass: "forex", price, currency: to,
      changeAbs: null, changePct: null, previousClose: null, open: null,
      dayHigh: num(r["8. Bid Price"]), dayLow: num(r["9. Ask Price"]),
      yearHigh: null, yearLow: null, volume: null, marketCap: null,
      asOf: r["6. Last Refreshed"] ? new Date(r["6. Last Refreshed"] + "Z").toISOString() : new Date().toISOString(),
      stale: false, entitlement: "delayed", source: this.id, marketState: marketStatus("FX").state, timezone: "UTC",
    };
  }

  async getCandles(meta: SymbolMeta, interval: CandleInterval, outputSize: number): Promise<CandleSeries> {
    const sym = providerSymbol(meta, this.id);
    const isDaily = interval === "1day" || interval === "1week" || interval === "1month";
    const fn = isDaily ? "TIME_SERIES_DAILY" : "TIME_SERIES_INTRADAY";
    const params: Record<string, string> = { function: fn, symbol: sym, outputsize: outputSize > 100 ? "full" : "compact" };
    if (!isDaily) {
      const avIntraday: Record<string, string> = { "1min": "1min", "5min": "5min", "15min": "15min", "30min": "30min", "1h": "60min", "4h": "60min" };
      params.interval = avIntraday[interval] ?? "5min";
    }
    const { data } = await fetchJson<Record<string, unknown>>(this.url(params), { timeoutMs: 10000 });
    const key = Object.keys(data).find((k) => k.includes("Time Series"));
    if (!key) throw new MarketDataError("no series", "provider_error", this.id);
    const series = data[key] as Record<string, Record<string, string>>;
    const candles = Object.entries(series)
      .map(([t, v]) => ({
        t: Date.parse(t.replace(" ", "T") + "Z"),
        o: Number(v["1. open"]), h: Number(v["2. high"]), l: Number(v["3. low"]), c: Number(v["4. close"]),
        v: v["5. volume"] != null ? Number(v["5. volume"]) : null,
      }))
      .filter((c) => Number.isFinite(c.c))
      .sort((a, b) => a.t - b.t)
      .slice(-outputSize);
    return { symbol: meta.symbol, assetClass: meta.assetClass, interval, candles, asOf: new Date().toISOString(), entitlement: "eod", source: this.id };
  }
}

interface AVQuote {
  "01. symbol": string; "02. open": string; "03. high": string; "04. low": string;
  "05. price": string; "06. volume": string; "07. latest trading day": string;
  "08. previous close": string; "09. change": string; "10. change percent": string;
}
