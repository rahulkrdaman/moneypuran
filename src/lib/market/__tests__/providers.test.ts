import { beforeEach, describe, expect, it } from "vitest";
import { __resetMarketConfig } from "../config";
import { resolveSymbol } from "../symbols";
import { MockProvider } from "../providers/mock";
import { TwelveDataProvider } from "../providers/twelvedata";
import { CoinGeckoProvider } from "../providers/coingecko";

beforeEach(() => __resetMarketConfig());

describe("MockProvider", () => {
  const p = new MockProvider();
  it("produces internally consistent quotes", async () => {
    for (const sym of ["AAPL", "BTC", "^GSPC", "EURUSD", "XAU"]) {
      const q = await p.getQuote(resolveSymbol(sym)!);
      expect(q.entitlement).toBe("mock");
      expect(q.price).toBeGreaterThan(0);
      if (q.changeAbs != null && q.previousClose != null) {
        expect(Math.abs(q.price - q.previousClose - q.changeAbs)).toBeLessThan(0.02);
      }
    }
  });
  it("is deterministic within the same minute", async () => {
    const a = await p.getQuote(resolveSymbol("AAPL")!);
    const b = await p.getQuote(resolveSymbol("AAPL")!);
    expect(a.price).toBe(b.price);
  });
});

describe("provider config guards", () => {
  it("TwelveDataProvider throws a config error when no key is set", async () => {
    const p = new TwelveDataProvider();
    await expect(p.getQuote(resolveSymbol("AAPL")!)).rejects.toMatchObject({ code: "config" });
  });
  it("CoinGeckoProvider only supports crypto", () => {
    const p = new CoinGeckoProvider();
    expect(p.supports("crypto")).toBe(true);
    expect(p.supports("stock")).toBe(false);
  });
});
