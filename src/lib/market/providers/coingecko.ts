/**
 * CoinGecko provider — crypto only. Works keyless on the public API at low
 * volume; a demo/pro key raises limits and is required for commercial use.
 */
import { getMarketConfig } from "../config";
import { fetchJson } from "../resilience";
import { providerSymbol } from "../symbols";
import type {
  AssetClass, CandleInterval, CandleSeries, Quote, SymbolMeta,
} from "../types";
import { MarketDataError } from "../types";
import { num, type MarketDataProvider } from "./base";

export class CoinGeckoProvider implements MarketDataProvider {
  readonly id = "coingecko";
  readonly displayName = "CoinGecko";
  readonly capabilities = {
    quote: ["crypto"] as AssetClass[], candles: ["crypto"] as AssetClass[], movers: false, batchQuote: true,
  };

  private get cfg() { return getMarketConfig().coinGecko; }

  private base() { return this.cfg.apiKey ? this.cfg.proBaseUrl : this.cfg.publicBaseUrl; }
  private headers(): Record<string, string> {
    return this.cfg.apiKey ? { "x-cg-pro-api-key": this.cfg.apiKey } : {};
  }

  supports(a: AssetClass) { return a === "crypto"; }

  async ping() {
    const { status } = await fetchJson(`${this.base()}/ping`, { headers: this.headers(), timeoutMs: 6000 });
    if (status >= 400) throw new MarketDataError(`ping ${status}`, "provider_error", this.id, status);
  }

  private cgId(meta: SymbolMeta): string {
    const id = providerSymbol(meta, this.id);
    if (id === meta.symbol) throw new MarketDataError(`no coingecko id for ${meta.symbol}`, "unsupported", this.id);
    return id;
  }

  async getQuote(meta: SymbolMeta): Promise<Quote> {
    return (await this.getQuotes([meta]))[0];
  }

  async getQuotes(metas: SymbolMeta[]): Promise<Quote[]> {
    const crypto = metas.filter((m) => m.assetClass === "crypto");
    const ids = crypto.map((m) => this.cgId(m)).join(",");
    const url =
      `${this.base()}/coins/markets?vs_currency=usd&ids=${encodeURIComponent(ids)}` +
      `&price_change_percentage=24h&sparkline=false&per_page=250`;
    const { status, data } = await fetchJson<CGMarket[] | { error: string }>(url, { headers: this.headers(), timeoutMs: 9000 });
    if (status === 429) throw new MarketDataError("rate limited", "rate_limited", this.id, 429);
    if (!Array.isArray(data)) throw new MarketDataError((data as { error?: string })?.error ?? "bad response", "provider_error", this.id);

    const byId = new Map(data.map((d) => [d.id, d]));
    return crypto.map((m) => {
      const d = byId.get(this.cgId(m));
      const nowIso = new Date().toISOString();
      if (!d) {
        return {
          symbol: m.symbol, name: m.name, assetClass: "crypto", price: NaN, currency: "USD",
          changeAbs: null, changePct: null, previousClose: null, open: null, dayHigh: null, dayLow: null,
          yearHigh: null, yearLow: null, volume: null, marketCap: null, asOf: nowIso, stale: true,
          entitlement: "realtime", source: this.id, marketState: "open",
        };
      }
      const price = num(d.current_price) ?? NaN;
      const changePct = num(d.price_change_percentage_24h);
      const changeAbs = num(d.price_change_24h);
      return {
        symbol: m.symbol,
        name: d.name || m.name,
        assetClass: "crypto",
        price,
        currency: "USD",
        changeAbs,
        changePct,
        previousClose: changeAbs != null && Number.isFinite(price) ? Number((price - changeAbs).toFixed(price < 5 ? 4 : 2)) : null,
        open: null,
        dayHigh: num(d.high_24h),
        dayLow: num(d.low_24h),
        yearHigh: num(d.ath),
        yearLow: num(d.atl),
        volume: num(d.total_volume),
        marketCap: num(d.market_cap),
        asOf: d.last_updated || nowIso,
        stale: false,
        entitlement: "realtime",
        source: this.id,
        marketState: "open",
        timezone: "UTC",
      };
    });
  }

  async getCandles(meta: SymbolMeta, interval: CandleInterval, outputSize: number): Promise<CandleSeries> {
    const id = this.cgId(meta);
    const days = candleDays(interval, outputSize);
    const url = `${this.base()}/coins/${id}/ohlc?vs_currency=usd&days=${days}`;
    const { status, data } = await fetchJson<number[][] | { error: string }>(url, { headers: this.headers(), timeoutMs: 9000 });
    if (status === 429) throw new MarketDataError("rate limited", "rate_limited", this.id, 429);
    if (!Array.isArray(data)) throw new MarketDataError((data as { error?: string })?.error ?? "bad ohlc", "provider_error", this.id);
    const candles = data.slice(-outputSize).map(([t, o, h, l, c]) => ({ t, o, h, l, c, v: null }));
    return { symbol: meta.symbol, assetClass: "crypto", interval, candles, asOf: new Date().toISOString(), entitlement: "realtime", source: this.id };
  }
}

function candleDays(interval: CandleInterval, n: number): string {
  const perDay: Record<string, number> = { "1min": 1440, "5min": 288, "15min": 96, "30min": 48, "1h": 24, "4h": 6, "1day": 1, "1week": 1 / 7, "1month": 1 / 30 };
  const days = Math.ceil(n / (perDay[interval] || 1));
  if (interval === "1day" || interval === "1week" || interval === "1month") return days > 90 ? "365" : days > 30 ? "90" : "30";
  return days > 7 ? "30" : days > 1 ? "7" : "1";
}

interface CGMarket {
  id: string; symbol: string; name: string;
  current_price: number; market_cap: number; total_volume: number;
  high_24h: number; low_24h: number; price_change_24h: number; price_change_percentage_24h: number;
  ath: number; atl: number; last_updated: string;
}
