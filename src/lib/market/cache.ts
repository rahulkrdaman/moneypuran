/**
 * Two-tier cache for market data.
 *   L1 — in-process LRU + TTL (survives without Redis; per-instance)
 *   L2 — Redis (shared across instances; optional)
 *
 * Values carry a soft-TTL (fresh) and a hard-TTL (usable-but-stale). Past the
 * soft-TTL the value is returned with `stale:true` while a refresh happens, so
 * the UI shows "Last updated 2 min ago" instead of a blank page (spec §33).
 */
import { getMarketConfig } from "./config";

type Entry<T> = { v: T; soft: number; hard: number };

const L1 = new Map<string, Entry<unknown>>();
const L1_MAX = 5000;

let redisClient: import("ioredis").Redis | null | undefined;

function redis(): import("ioredis").Redis | null {
  if (redisClient !== undefined) return redisClient;
  const url = getMarketConfig().redisUrl;
  if (!url) return (redisClient = null);
  try {
    // Lazy require so the app runs with zero Redis in dev / mock.

    const Redis = require("ioredis") as typeof import("ioredis").default;
    redisClient = new Redis(url, {
      maxRetriesPerRequest: 2,
      enableReadyCheck: false,
      lazyConnect: true,
      retryStrategy: (n: number) => (n > 3 ? null : Math.min(n * 200, 2000)),
    });
    redisClient.on("error", () => {/* handled per-call */});
    return redisClient;
  } catch {
    return (redisClient = null);
  }
}

function now() { return Date.now(); }

export interface CacheRead<T> {
  value: T;
  stale: boolean;
}

export async function cacheRead<T>(key: string): Promise<CacheRead<T> | null> {
  const l1 = L1.get(key) as Entry<T> | undefined;
  if (l1) {
    if (now() < l1.hard) return { value: l1.v, stale: now() >= l1.soft };
    L1.delete(key);
  }
  const r = redis();
  if (r) {
    try {
      const raw = await r.get(key);
      if (raw) {
        const e = JSON.parse(raw) as Entry<T>;
        if (now() < e.hard) {
          L1.set(key, e as Entry<unknown>);
          return { value: e.v, stale: now() >= e.soft };
        }
      }
    } catch { /* redis down → ignore */ }
  }
  return null;
}

export async function cacheWrite<T>(
  key: string,
  value: T,
  softTtlSec: number,
  hardTtlMult = 8,
): Promise<void> {
  const soft = now() + softTtlSec * 1000;
  const hard = now() + softTtlSec * 1000 * hardTtlMult;
  const entry: Entry<T> = { v: value, soft, hard };

  if (L1.size >= L1_MAX) {
    // evict oldest ~10%
    let i = 0;
    for (const k of L1.keys()) { L1.delete(k); if (++i > L1_MAX / 10) break; }
  }
  L1.set(key, entry as Entry<unknown>);

  const r = redis();
  if (r) {
    try {
      await r.set(key, JSON.stringify(entry), "PX", softTtlSec * 1000 * hardTtlMult);
    } catch { /* ignore */ }
  }
}

export function cacheClear() {
  L1.clear();
}

/** test/health helper */
export async function cachePing(): Promise<{ redis: boolean }> {
  const r = redis();
  if (!r) return { redis: false };
  try { await r.ping(); return { redis: true }; }
  catch { return { redis: false }; }
}

/** for tests only */
export function __setRedisForTest(client: import("ioredis").Redis | null) {
  redisClient = client;
}
