import { beforeEach, describe, expect, it } from "vitest";
import { __resetMarketConfig } from "../config";
import { __resetRegistry } from "../providers";
import { cacheClear } from "../cache";
import {
  getQuote, getQuotes, getTicker, getCandles, getMovers, getAllMarketStatuses, marketHealth,
} from "../service";
import { direction, fmtChangePct, freshnessLabel, toClientQuote } from "../format";

// MARKET_DATA_MOCK defaults to true when NODE_ENV==="test" (see config.ts)
beforeEach(() => {
  __resetMarketConfig();
  __resetRegistry();
  cacheClear();
});

describe("MarketDataService (mock mode)", () => {
  it("getQuote returns a normalized, mock-labelled quote", async () => {
    const q = await getQuote("AAPL");
    expect(q.symbol).toBe("AAPL");
    expect(q.assetClass).toBe("stock");
    expect(q.entitlement).toBe("mock");
    expect(q.source).toBe("mock");
    expect(Number.isFinite(q.price)).toBe(true);
    expect(q.currency).toBe("USD");
    expect(typeof q.changePct === "number" || q.changePct === null).toBe(true);
  });

  it("getQuote resolves aliases", async () => {
    const q = await getQuote("bitcoin");
    expect(q.symbol).toBe("BTC");
    expect(q.assetClass).toBe("crypto");
  });

  it("second getQuote is served from cache (same asOf within TTL)", async () => {
    const a = await getQuote("MSFT");
    const b = await getQuote("MSFT");
    expect(b.asOf).toBe(a.asOf);
    expect(b.price).toBe(a.price);
  });

  it("getQuotes handles a mixed batch and preserves order", async () => {
    const out = await getQuotes(["AAPL", "BTC", "EURUSD", "^GSPC", "XAU"]);
    expect(out.map((q) => q.symbol)).toEqual(["AAPL", "BTC", "EURUSD", "^GSPC", "XAU"]);
    expect(out.every((q) => Number.isFinite(q.price))).toBe(true);
    expect(out.find((q) => q.symbol === "BTC")!.assetClass).toBe("crypto");
  });

  it("getTicker returns the configured ticker universe", async () => {
    const { items } = await getTicker();
    expect(items.length).toBeGreaterThan(10);
    expect(items.every((i) => i.entitlement === "mock")).toBe(true);
  });

  it("getCandles returns an ordered OHLC series for a range", async () => {
    const s = await getCandles("NVDA", "1M");
    expect(s.candles.length).toBeGreaterThan(10);
    for (let i = 1; i < s.candles.length; i++) {
      expect(s.candles[i].t).toBeGreaterThanOrEqual(s.candles[i - 1].t);
      expect(s.candles[i].h).toBeGreaterThanOrEqual(s.candles[i].l);
    }
  });

  it("getMovers returns ranked entries", async () => {
    const g = await getMovers("US", "gainers");
    expect(g.kind).toBe("gainers");
    expect(g.entries.length).toBeGreaterThan(0);
    for (let i = 1; i < g.entries.length; i++) {
      expect(g.entries[i - 1].changePct).toBeGreaterThanOrEqual(g.entries[i].changePct);
    }
    const l = await getMovers("US", "losers");
    for (let i = 1; i < l.entries.length; i++) {
      expect(l.entries[i - 1].changePct).toBeLessThanOrEqual(l.entries[i].changePct);
    }
  });

  it("unknown symbol → not_found", async () => {
    await expect(getQuote("ZZZZZ")).rejects.toMatchObject({ code: "not_found" });
  });

  it("marketHealth reports mock mode + a healthy provider", async () => {
    const h = await marketHealth();
    expect(h.mode).toBe("mock");
    expect(h.publicDisplayLicensed).toBe(true);
    expect(h.providers.some((p) => p.ok)).toBe(true);
  });

  it("getAllMarketStatuses covers every tracked market", () => {
    const s = getAllMarketStatuses();
    expect(s.map((x) => x.market)).toEqual(["US", "IN", "UK", "EU", "JP", "HK", "CN", "CRYPTO", "FX"]);
    expect(s.find((x) => x.market === "CRYPTO")!.state).toBe("open");
  });
});

describe("format helpers", () => {
  it("direction", () => {
    expect(direction(1.2)).toBe("up");
    expect(direction(-0.1)).toBe("down");
    expect(direction(0)).toBe("flat");
    expect(direction(null)).toBe("flat");
  });
  it("fmtChangePct", () => {
    expect(fmtChangePct(1.234)).toBe("+1.23%");
    expect(fmtChangePct(-2)).toBe("-2.00%");
    expect(fmtChangePct(null)).toBe("—");
  });
  it("freshnessLabel reflects entitlement + staleness", () => {
    const base = { entitlement: "mock" as const, asOf: new Date().toISOString(), stale: false };
    expect(freshnessLabel(base)).toBe("Demo data");
    const stale = { entitlement: "delayed" as const, asOf: new Date(Date.now() - 120_000).toISOString(), stale: true };
    expect(freshnessLabel(stale)).toMatch(/updated .* ago/);
  });
  it("toClientQuote adds _dir and _freshness without dropping fields", async () => {
    const q = await getQuote("AAPL");
    const c = toClientQuote(q);
    expect(c.symbol).toBe("AAPL");
    expect(["up", "down", "flat"]).toContain(c._dir);
    expect(typeof c._freshness).toBe("string");
  });
});
