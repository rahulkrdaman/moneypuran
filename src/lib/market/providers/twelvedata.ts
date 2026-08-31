/**
 * Twelve Data provider — primary for stocks / ETFs / indices / forex / commodities
 * (and crypto, though we prefer CoinGecko for that).
 *
 * NOTE (spec §6, §38): the free/basic plans do NOT license public commercial
 * display. `isPublicDisplayAllowed()` in config.ts gates this in production;
 * this class just talks to the API.
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

const SUPPORTED: AssetClass[] = ["stock", "etf", "index", "forex", "commodity", "crypto"];

export class TwelveDataProvider implements MarketDataProvider {
  readonly id = "twelvedata";
  readonly displayName = "Twelve Data";
  readonly capabilities = {
    quote: SUPPORTED, candles: SUPPORTED, movers: false, batchQuote: true,
  };

  private get cfg() { return getMarketConfig().twelveData; }

  private url(path: string, params: Record<string, string>): string {
    const key = this.cfg.apiKey;
    if (!key) throw new MarketDataError("TWELVE_DATA_API_KEY not set", "config", this.id);
    const qs = new URLSearchParams({ ...params, apikey: key });
    return `${this.cfg.baseUrl}${path}?${qs}`;
  }

  supports(a: AssetClass) { return SUPPORTED.includes(a); }

  async ping() {
    const { data } = await fetchJson<Record<string, unknown>>(
      this.url("/quote", { symbol: "AAPL" }), { timeoutMs: 6000 },
    );
    if ((data as { status?: string }).status === "error") {
      throw new MarketDataError(String((data as { message?: string }).message ?? "ping failed"), "provider_error", this.id);
    }
  }

  async getQuote(meta: SymbolMeta): Promise<Quote> {
    const sym = providerSymbol(meta, this.id);
    const { status, data } = await fetchJson<TDQuote & TDError>(this.url("/quote", { symbol: sym }), { timeoutMs: 8000 });
    if (status === 429) throw new MarketDataError("rate limited", "rate_limited", this.id, 429);
    if (data?.status === "error") {
      const msg = data.message ?? "unknown";
      throw new MarketDataError(msg, /not found|not exist/i.test(msg) ? "not_found" : "provider_error", this.id);
    }
    return this.toQuote(meta, data);
  }

  async getQuotes(metas: SymbolMeta[]): Promise<Quote[]> {
    if (metas.length === 1) return [await this.getQuote(metas[0])];
    const symbolList = metas.map((m) => providerSymbol(m, this.id)).join(",");
    const { data } = await fetchJson<Record<string, TDQuote> & TDError>(
      this.url("/quote", { symbol: symbolList }), { timeoutMs: 10000 },
    );
    if ((data as TDError)?.status === "error") throw new MarketDataError(String((data as TDError).message), "provider_error", this.id);
    return metas.map((m) => {
      const sym = providerSymbol(m, this.id);
      const one = (data as Record<string, TDQuote>)[sym] ?? (metas.length === 1 ? (data as unknown as TDQuote) : undefined);
      if (!one || (one as unknown as TDError).status === "error") {
        // don't fail the whole batch for one bad symbol
        return this.toQuote(m, {} as TDQuote, true);
      }
      return this.toQuote(m, one);
    });
  }

  async getCandles(meta: SymbolMeta, interval: CandleInterval, outputSize: number): Promise<CandleSeries> {
    const sym = providerSymbol(meta, this.id);
    const { data } = await fetchJson<TDSeries & TDError>(
      this.url("/time_series", { symbol: sym, interval: tdInterval(interval), outputsize: String(outputSize) }),
      { timeoutMs: 10000 },
    );
    if (data?.status === "error" || !data?.values) {
      throw new MarketDataError(data?.message ?? "no series", "provider_error", this.id);
    }
    const candles = data.values
      .map((v) => ({
        t: Date.parse(v.datetime.replace(" ", "T") + "Z"),
        o: Number(v.open), h: Number(v.high), l: Number(v.low), c: Number(v.close),
        v: v.volume != null ? Number(v.volume) : null,
      }))
      .filter((c) => Number.isFinite(c.c))
      .reverse();
    return {
      symbol: meta.symbol, assetClass: meta.assetClass, interval, candles,
      asOf: new Date().toISOString(), entitlement: this.entitlement(), source: this.id,
    };
  }

  private entitlement(): Quote["entitlement"] {
    const plan = this.cfg.plan;
    return plan === "pro" || plan === "enterprise" || plan === "ultra" ? "realtime" : "delayed";
  }

  private toQuote(meta: SymbolMeta, q: TDQuote, missing = false): Quote {
    const market = marketForSymbol(meta.assetClass, meta.market);
    const st = marketStatus(market);
    const price = num(q.close) ?? num(q.price) ?? NaN;
    if (missing || !Number.isFinite(price)) {
      // still return a well-formed object; `stale` + null price signal "no data"
      return {
        symbol: meta.symbol, name: meta.name, assetClass: meta.assetClass, price: NaN,
        currency: meta.currency, changeAbs: null, changePct: null, previousClose: null,
        open: null, dayHigh: null, dayLow: null, yearHigh: null, yearLow: null,
        volume: null, marketCap: null, asOf: new Date().toISOString(), stale: true,
        entitlement: this.entitlement(), source: this.id, marketState: st.state, timezone: st.timezone,
      };
    }
    return {
      symbol: meta.symbol,
      name: q.name || meta.name,
      assetClass: meta.assetClass,
      price,
      currency: q.currency || meta.currency,
      changeAbs: num(q.change),
      changePct: num(q.percent_change),
      previousClose: num(q.previous_close),
      open: num(q.open),
      dayHigh: num(q.high),
      dayLow: num(q.low),
      yearHigh: num(q.fifty_two_week?.high),
      yearLow: num(q.fifty_two_week?.low),
      volume: num(q.volume),
      marketCap: null,
      asOf: q.timestamp ? new Date(Number(q.timestamp) * 1000).toISOString() : new Date().toISOString(),
      stale: false,
      entitlement: this.entitlement(),
      source: this.id,
      exchange: q.exchange || meta.exchange,
      timezone: st.timezone,
      marketState: (q.is_market_open ? "open" : st.state),
    };
  }
}

function tdInterval(i: CandleInterval): string {
  return { "1min": "1min", "5min": "5min", "15min": "15min", "30min": "30min", "1h": "1h", "4h": "4h", "1day": "1day", "1week": "1week", "1month": "1month" }[i];
}

interface TDError { status?: "error" | "ok"; message?: string; code?: number }
interface TDQuote {
  symbol?: string; name?: string; exchange?: string; currency?: string;
  open?: string; high?: string; low?: string; close?: string; price?: string;
  previous_close?: string; change?: string; percent_change?: string; volume?: string;
  timestamp?: string | number; is_market_open?: boolean;
  fifty_two_week?: { low?: string; high?: string };
}
interface TDSeries { status?: string; message?: string; values?: Array<{ datetime: string; open: string; high: string; low: string; close: string; volume?: string }> }
