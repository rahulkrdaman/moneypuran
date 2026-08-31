import { describe, expect, it } from "vitest";
import { resolveSymbol, symbolBySlug, providerSymbol, ALL_SYMBOLS, TICKER_SYMBOLS } from "../symbols";

describe("resolveSymbol", () => {
  it("resolves canonical tickers", () => {
    expect(resolveSymbol("AAPL")?.slug).toBe("apple");
    expect(resolveSymbol("btc")?.assetClass).toBe("crypto");
    expect(resolveSymbol("^GSPC")?.slug).toBe("sp-500");
  });
  it("resolves aliases and loose spellings", () => {
    expect(resolveSymbol("nvidia")?.symbol).toBe("NVDA");
    expect(resolveSymbol("EUR/USD")?.symbol).toBe("EURUSD");
    expect(resolveSymbol("eur-usd")?.symbol).toBe("EURUSD");
    expect(resolveSymbol("NASDAQ")?.symbol).toBe("^IXIC");
    expect(resolveSymbol("gold")?.symbol).toBe("XAU");
  });
  it("resolves by slug", () => {
    expect(resolveSymbol("bitcoin")?.symbol).toBe("BTC");
    expect(symbolBySlug("index", "nifty-50")?.symbol).toBe("^NSEI");
  });
  it("returns null for unknown input", () => {
    expect(resolveSymbol("NOTATHING")).toBeNull();
    expect(resolveSymbol("")).toBeNull();
  });
});

describe("providerSymbol", () => {
  it("maps to provider-specific symbols with fallback", () => {
    const btc = resolveSymbol("BTC")!;
    expect(providerSymbol(btc, "coingecko")).toBe("bitcoin");
    const aapl = resolveSymbol("AAPL")!;
    expect(providerSymbol(aapl, "coingecko")).toBe("AAPL"); // no override → canonical
  });
});

describe("universe integrity", () => {
  it("every symbol has a unique symbol + non-empty slug", () => {
    const seen = new Set<string>();
    for (const m of ALL_SYMBOLS) {
      expect(m.symbol).toBeTruthy();
      expect(m.slug).toMatch(/^[a-z0-9-]+$/);
      expect(seen.has(m.symbol)).toBe(false);
      seen.add(m.symbol);
    }
  });
  it("every ticker symbol resolves", () => {
    for (const s of TICKER_SYMBOLS) expect(resolveSymbol(s), s).not.toBeNull();
  });
});
