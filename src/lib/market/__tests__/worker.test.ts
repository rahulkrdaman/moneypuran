import { beforeEach, describe, expect, it } from "vitest";
import { __resetMarketConfig } from "../config";
import { __resetRegistry } from "../providers";
import { cacheClear } from "../cache";
import { runMarketRefresh } from "@/workers/market-worker";

beforeEach(() => {
  __resetMarketConfig();
  __resetRegistry();
  cacheClear();
});

describe("market-worker (mock mode, no persist)", () => {
  it("warms the cache for the hot symbol set", async () => {
    const s = await runMarketRefresh({ persist: false });
    expect(s.mode).toBe("mock");
    expect(s.ok).toBeGreaterThan(20);
    expect(s.failed).toBe(0);
    expect(s.persisted).toBe(0);
    expect(s.durationMs).toBeGreaterThanOrEqual(0);
  });

  it("a second pass is fast (served from warm cache)", async () => {
    await runMarketRefresh();
    const t0 = Date.now();
    const s = await runMarketRefresh();
    expect(s.ok).toBeGreaterThan(20);
    expect(Date.now() - t0).toBeLessThan(1500);
  });
});
