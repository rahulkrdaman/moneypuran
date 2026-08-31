import { describe, expect, it, beforeEach } from "vitest";
import { CircuitBreaker, TokenBucket, coalesce, __clearInflight, retry } from "../resilience";

describe("CircuitBreaker", () => {
  it("opens after threshold failures and half-opens after cooldown", async () => {
    const cb = new CircuitBreaker("t", 3, 50);
    expect(cb.canRequest()).toBe(true);
    for (let i = 0; i < 3; i++) {
      await expect(cb.run(async () => { throw new Error("boom"); })).rejects.toThrow("boom");
    }
    expect(cb.status).toBe("open");
    expect(cb.canRequest()).toBe(false);
    await new Promise((r) => setTimeout(r, 60));
    expect(cb.status).toBe("half-open");
    await cb.run(async () => "ok");
    expect(cb.status).toBe("closed");
  });

  it("a single failure in half-open re-opens", async () => {
    const cb = new CircuitBreaker("t", 2, 20);
    await expect(cb.run(async () => { throw new Error("x"); })).rejects.toThrow();
    await expect(cb.run(async () => { throw new Error("x"); })).rejects.toThrow();
    await new Promise((r) => setTimeout(r, 25));
    expect(cb.status).toBe("half-open");
    await expect(cb.run(async () => { throw new Error("x"); })).rejects.toThrow();
    expect(cb.status).toBe("open");
  });
});

describe("TokenBucket", () => {
  it("limits burst to capacity then refills", async () => {
    const b = new TokenBucket(3, 100); // 100/sec refill
    expect(b.tryRemove()).toBe(true);
    expect(b.tryRemove()).toBe(true);
    expect(b.tryRemove()).toBe(true);
    expect(b.tryRemove()).toBe(false);
    await new Promise((r) => setTimeout(r, 30)); // ~3 tokens back
    expect(b.tryRemove()).toBe(true);
  });

  it("acquire waits then succeeds within budget", async () => {
    const b = new TokenBucket(1, 50);
    expect(b.tryRemove()).toBe(true);
    const ok = await b.acquire(1, 200);
    expect(ok).toBe(true);
  });
});

describe("coalesce", () => {
  beforeEach(() => __clearInflight());
  it("runs the fn once for concurrent identical keys", async () => {
    let calls = 0;
    const fn = () => { calls++; return new Promise<number>((r) => setTimeout(() => r(42), 20)); };
    const [a, b, c] = await Promise.all([
      coalesce("k", fn), coalesce("k", fn), coalesce("k", fn),
    ]);
    expect([a, b, c]).toEqual([42, 42, 42]);
    expect(calls).toBe(1);
  });
  it("allows a new call after the first settles", async () => {
    let calls = 0;
    const fn = async () => { calls++; return calls; };
    await coalesce("k2", fn);
    await coalesce("k2", fn);
    expect(calls).toBe(2);
  });
});

describe("retry", () => {
  it("retries then succeeds", async () => {
    let n = 0;
    const r = await retry(async () => { if (++n < 3) throw new Error("no"); return n; }, { tries: 5, baseMs: 1 });
    expect(r).toBe(3);
  });
  it("throws after exhausting tries", async () => {
    await expect(retry(async () => { throw new Error("always"); }, { tries: 2, baseMs: 1 })).rejects.toThrow("always");
  });
});
